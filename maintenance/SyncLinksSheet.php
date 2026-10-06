#!/usr/bin/php
<?php
// @codingStandardsIgnoreStart
$IP = getenv( "MW_INSTALL_PATH" ) ?: __DIR__ . "/../../..";
if ( !is_readable( "$IP/maintenance/Maintenance.php" ) ) {
	die( "MW_INSTALL_PATH needs to be set to your MediaWiki installation.\n" );
}
require_once ( "$IP/maintenance/Maintenance.php" );
require_once ( "$IP/extensions/KZBrokenLinks/includes/KZBrokenLinksMaintenance.php" );
// @codingStandardsIgnoreEnd

/**
 * Maintenance script to sync the Mediawiki table externalinks to Google Sheets
 *
 * @ingroup Maintenance
 * @SuppressWarnings(StaticAccess)
 * @SuppressWarnings(LongVariable)
 */
class SyncLinksSheet extends KZBrokenLinksMaintenance {

	private array $excludedProtocols;
	private array $urlsEncountered;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "CLI utility to sync the Mediawiki table externalinks to Google Sheets" );
		$this->addOption( 'chunksize',
			'Maximum number of external links to sync from Mediawiki to Google Sheets per API call (default 500)',
			false, true
		);
		$this->addOption( 'maxlinks',
			'Maximum number of external links to sync before exiting (default unlimited)',
			false, true
		);
		$this->addOption( 'no-recase',
			'Skip restoring the case of URLs in LINKS_STATUS that earlier versions lowercased'
		);

		if ( method_exists( $this, 'requireExtension' ) ) {
			$this->requireExtension( 'KZBrokenLinks' );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		// Load configuration.
		$config = $this->getConfig();
		$googleConfig = $config->get( 'KZBrokenLinksGoogleConfig' );
		$spreadsheetId = $googleConfig[ 'sheetId' ];
		$this->excludedProtocols = $config->get( 'KZBrokenLinksHttpConfig' )[ 'excludedProtocols' ] ?? [];

		$titleFormatter = \MediaWiki\MediaWikiServices::getInstance()->getTitleFormatter();

		// Set up Google Client and Sheets Service
		$client = new \Google_Client();
		$client->setApplicationName( 'Google Sheets API' );
		$client->setScopes( [ \Google\Service\Sheets::SPREADSHEETS ] );
		$client->setAccessType( 'offline' );
		$client->setAuthConfig( $googleConfig[ 'keyPath' ] );
		$client->setConfig( 'retry', [ 'retries' => 6 ] );
		$service = new \Google\Service\Sheets( $client );

		// Clear all-links rows.
		$clearService = new \Google\Service\Sheets\ClearValuesRequest();
		$service->spreadsheets_values->clear( $spreadsheetId, 'ALL_LINKS!A2:D', $clearService );
		$this->maintainRateLimit();

		// Get the highest el_id from the externallinks table
		$dbw = $this->getDB( DB_PRIMARY );
		$res = $dbw->select(
			[ 'el' => 'externallinks' ],
			[ 'MAX(el.el_id)' ],
		);
		$max_el_id = $res->fetchRow()[0];

		// Query Mediawiki's external links table and append to "All Links" sheet in chunks
		$chunk_size = $this->getOption( 'chunksize', 500 );
		$max_links = $this->getOption( 'maxlinks', 0 );
		$processed_count = 0;
		$this->urlsEncountered = [];
		for ( $last_el_id = 0; $last_el_id < $max_el_id; ) {
			$this->output( "Loading externallinks data from el_id $last_el_id..." );
			$res = $dbw->select(
				[
					'el' => 'externallinks',
					'p' => 'page',
				],
				[
					'el.el_id',
					'el.el_from',
					'el.el_to_domain_index',
					'el.el_to_path',
					'p.page_title',
					'p.page_namespace'
				],
				"el.el_id > $last_el_id",
				__METHOD__,
				[
					'ORDER BY' => 'el.el_id',
					'LIMIT' => $chunk_size,
				],
				[
					'p' => [ 'LEFT JOIN', 'p.page_id=el.el_from' ]
				]
			);
			$values = [];
			for ( $row = $res->fetchRow(); is_array( $row ); $row = $res->fetchRow() ) {
				$last_el_id = $row['el_id'];

				// Convert table row to spreadsheet row values.
				$rawUrl = $this->reconstructUrl( $row );
				if ( $rawUrl === '' ) {
					// Domain index that wouldn't parse, so skip this row.
					continue;
				}
				$url = $this->convertUrl( $rawUrl );
				if ( $url === false ) {
					// URL with excluded protocol, so skip this row.
					continue;
				}

				$formattedTitle = $titleFormatter->formatTitle( $row['page_namespace'], $row['page_title'] );
				$repeat_url = !empty( $this->urlsEncountered[$url] );
				$values[] = [
					$url,
					$row['el_from'],
					$formattedTitle,
					// getLinkText() is expensive, so don't run it for repeat links
					$repeat_url ? '' : $this->getLinkText( $rawUrl, $row['el_from'] ),
				];
				$this->urlsEncountered[ $url ] = true;
				if ( $max_links > 0 && ++$processed_count == $max_links ) {
					// Maximum reached, so stop adding rows to sync.
					break;
				}
			}

			$this->output( ' (' . count( $this->urlsEncountered ) . " total unique URLs)\n" );

			// Append rows to ALL_LINKS sheet.
			$valueRange = new \Google\Service\Sheets\ValueRange();
			$valueRange->setValues( $values );
			$service->spreadsheets_values->append(
				$spreadsheetId,
				'ALL_LINKS!A:ZZZ',
				$valueRange,
				[
					'valueInputOption' => 'USER_ENTERED',
					'insertDataOption' => 'INSERT_ROWS',
				]
			);
			$this->maintainRateLimit();

			// If the maximum was reached, stop processing even if there are more rows in externallinks.
			if ( $max_links > 0 && $processed_count == $max_links ) {
				break;
			}
		}

		// Restore the case of LINKS_STATUS URLs that earlier versions lowercased.
		if ( $this->hasOption( 'no-recase' ) ) {
			$this->output( "Skipping LINKS_STATUS re-case (--no-recase).\n" );
		} elseif ( $max_links > 0 ) {
			$this->output( "Skipping LINKS_STATUS re-case: --maxlinks makes this a partial export.\n" );
		} else {
			$this->recaseLinksStatus( $service, $spreadsheetId, $chunk_size );
		}

		// Query new links.
		$range = $service->spreadsheets_values->get( $spreadsheetId, 'NEW_LINKS!C2:C' );
		$new_links_count = count( $range );
		if ( $new_links_count === 0 ) {
			$this->output( "Found no new links to add. Exiting.\n" );
			return;
		}
		$this->maintainRateLimit();
		$this->output( "Appending $new_links_count new links to LINKS_STATUS sheet...\n" );

		// Append new links to the LINKS_STATUS sheet.
		$appendValues = $range->getValues();
		if ( !empty( $appendValues[0][0] ) && $appendValues[0][0] == '#N/A' ) {
			// The query in Google Sheets says there are no new links. Exit.
			$this->output( "No new links to sync. Exiting.\n" );
			return;
		}
		for ( $i = count( $appendValues ) - 1; $i >= 0; $i-- ) {
			// Don't overwrite the sheet's row-index column.
			array_unshift( $appendValues[$i], '' );
		}
		$valueRange = new \Google\Service\Sheets\ValueRange();
		$valueRange->setValues( $appendValues );
		$service->spreadsheets_values->append(
			$spreadsheetId,
			'LINKS_STATUS!A:ZZZ',
			$valueRange,
			[
				'valueInputOption' => 'USER_ENTERED',
				'insertDataOption' => 'INSERT_ROWS',
			]
		);

		$this->output( "Done.\n" );
	}

	/**
	 * Rebuild a link's URL from the two columns that replaced el_to.
	 *
	 * MediaWiki 1.40 split externallinks.el_to into el_to_domain_index -- the
	 * URL's scheme plus its host with the labels reversed, so that a search
	 * for every link into a domain is an index prefix scan -- and el_to_path.
	 * 1.43 dropped el_to altogether, so the original URL has to be reassembled
	 * here. Core does the same thing in the same way; see
	 * ExternalLinks\ExternalLinksLookup::getExternalLinksForPage().
	 *
	 * Un-reversing is not just a matter of flipping the labels back: mailto
	 * indexes are stored as "domain.reversed@localpart", IP hosts are left
	 * alone, and ports ride along. LinkFilter::reverseIndexes() is the exact
	 * inverse of the function that wrote the column, so use it rather than
	 * reimplementing those cases.
	 *
	 * @param array $row Row with el_to_domain_index and el_to_path
	 * @return string The URL, or '' if the domain index could not be parsed
	 */
	private function reconstructUrl( array $row ) {
		$domain = \MediaWiki\ExternalLinks\LinkFilter::reverseIndexes( $row['el_to_domain_index'] );
		if ( $domain === '' ) {
			// reverseIndexes() returns '' for an index it can't parse. Without
			// a scheme and host there is no URL to report, and the path alone
			// would be worse than useless in the sheet.
			return '';
		}

		// el_to_path is nullable: a link to a bare domain records no path.
		return $domain . ( $row['el_to_path'] ?? '' );
	}

	/**
	 * Massage URL to ensure proper form.
	 *
	 * Only the scheme and host are case-insensitive (RFC 3986 3.1, 3.2.2), and
	 * core already stores the host lowercased (LinkFilter::makeIndexes()), so
	 * only those are lowercased here. Path, query and fragment are often
	 * case-sensitive on the server, and HealthCheckLinks requests exactly
	 * what is written to the sheet, so they are kept byte-exact.
	 *
	 * @param string $url The URL as recorded in the externallinks table
	 * @return string|false $url The URL ready for export to the ALL_LINKS
	 *  sheet, or false if its protocol is excluded
	 */
	private function convertUrl( $url ) {
		// First check for excluded protocol.
		foreach ( $this->excludedProtocols as $excludedProtocol ) {
			if ( stripos( $url, $excludedProtocol ) === 0 ) {
				// Excluded protocol, so we won't process this URL.
				return false;
			}
		}

		// Break out the URL protocol.
		$urlexp = explode( '://', $url, 2 );

		if ( count( $urlexp ) === 2 ) {
			// URL-decode the domain name, and lowercase it with the scheme.
			// Userinfo before an '@' (rare) is not part of the host, so it
			// keeps its case.
			$locexp = explode( '/', $urlexp[1], 2 );
			$authority = urldecode( $locexp[0] );
			$atPos = strrpos( $authority, '@' );
			$userinfo = $atPos === false ? '' : substr( $authority, 0, $atPos + 1 );
			$host = $atPos === false ? $authority : substr( $authority, $atPos + 1 );
			$url = strtolower( $urlexp[0] ) . '://' . $userinfo . strtolower( $host );
			if ( count( $locexp ) === 2 ) {
				$url = $url . '/' . $locexp[1];
			}
		} else {
			// A scheme without '//' (e.g. "news:"); lowercase the scheme only.
			$colonPos = strpos( $url, ':' );
			if ( $colonPos !== false ) {
				$url = strtolower( substr( $url, 0, $colonPos ) ) . substr( $url, $colonPos );
			}
		}

		return $url;
	}

	/**
	 * Restore the case of URLs that earlier versions of this script lowercased.
	 *
	 * Until URLs were exported case-preserved, every URL in LINKS_STATUS was
	 * written all-lowercase. NEW_LINKS matches against LINKS_STATUS
	 * case-insensitively, so those rows would never be replaced by their
	 * exact-case URLs and the health check would keep requesting the wrong
	 * path. Rewrite each such cell with the one URL exported this run that
	 * it is the lowercase form of. Corrected cells no longer qualify, so this
	 * is a no-op once the sheet has been fixed.
	 *
	 * @param \Google\Service\Sheets $service
	 * @param string $spreadsheetId
	 * @param int $chunkSize Maximum cells per batchUpdate call
	 */
	private function recaseLinksStatus( $service, $spreadsheetId, $chunkSize ) {
		$this->output( "Checking LINKS_STATUS for lowercased URLs...\n" );
		$range = $service->spreadsheets_values->get( $spreadsheetId, 'LINKS_STATUS!B2:B' );
		$this->maintainRateLimit();
		$cells = [];
		foreach ( $range->getValues() ?? [] as $i => $rowValues ) {
			$cells[$i + 2] = (string)( $rowValues[0] ?? '' );
		}

		$plan = self::planRecase( $cells, array_keys( $this->urlsEncountered ) );
		$this->output(
			'LINKS_STATUS re-case: ' . count( $plan['updates'] ) . ' to correct, '
			. $plan['ambiguous'] . ' ambiguous (skipped), '
			. $plan['unmatched'] . " unmatched (skipped)\n"
		);

		$data = [];
		foreach ( $plan['updates'] as $rowNum => $exactUrl ) {
			$data[] = new \Google\Service\Sheets\ValueRange( [
				'range' => "LINKS_STATUS!B{$rowNum}",
				'values' => [ [ $exactUrl ] ],
			] );
		}
		foreach ( array_chunk( $data, max( 1, (int)$chunkSize ) ) as $chunk ) {
			$service->spreadsheets_values->batchUpdate(
				$spreadsheetId,
				new \Google\Service\Sheets\BatchUpdateValuesRequest( [
					// RAW, so a URL is stored as text and never reinterpreted.
					'valueInputOption' => 'RAW',
					'data' => $chunk,
				] )
			);
			$this->maintainRateLimit();
		}
	}

	/**
	 * Decide which LINKS_STATUS cells to re-case.
	 *
	 * A cell qualifies if it is all-lowercase and differs only by case from
	 * at least one exported URL. It is corrected when exactly one exported
	 * URL has that lowercase form (and the cell is not already that URL);
	 * with several case variants there is no telling which one the row
	 * stands for, so it is skipped as ambiguous. An all-lowercase cell that
	 * no exported URL lowercases to is counted as unmatched (typically a
	 * link since removed from the wiki).
	 *
	 * @param string[] $cells Cell values keyed by sheet row number
	 * @param string[] $exportedUrls Exact-case URLs exported this run
	 * @return array With keys "updates" (row number => exact-case URL), "ambiguous" and "unmatched" (counts)
	 */
	private static function planRecase( array $cells, array $exportedUrls ) {
		$variants = [];
		foreach ( $exportedUrls as $exactUrl ) {
			$exactUrl = (string)$exactUrl;
			$variants[ strtolower( $exactUrl ) ][ $exactUrl ] = true;
		}

		$updates = [];
		$ambiguous = 0;
		$unmatched = 0;
		foreach ( $cells as $rowNum => $value ) {
			if ( $value === '' || $value !== strtolower( $value ) ) {
				// Empty, or already carries case, so never lowercased by us.
				continue;
			}
			if ( !isset( $variants[$value] ) ) {
				$unmatched++;
				continue;
			}
			if ( isset( $variants[$value][$value] ) ) {
				// The lowercase URL itself is a link on the wiki; the row is right.
				continue;
			}
			if ( count( $variants[$value] ) > 1 ) {
				$ambiguous++;
				continue;
			}
			$updates[$rowNum] = (string)array_key_first( $variants[$value] );
		}

		return [ 'updates' => $updates, 'ambiguous' => $ambiguous, 'unmatched' => $unmatched ];
	}

	/**
	 * Locate and extract link text from wiki page.
	 * @param string $url
	 * @param int $page_id
	 * @return string $linkText
	 */
	private function getLinkText( $url, $page_id ) {
		$page = \MediaWiki\MediaWikiServices::getInstance()->getWikiPageFactory()->newFromID( $page_id );
		$wikitext = $page->getContent()->getWikitextForTransclusion();
		$url = preg_replace( '/\\s/', '%20', $url );
		$mostlyDecodedUrl = preg_replace( '/\\s/', '%20', urldecode( $url ) );
		$extraEncodedUrl = str_replace( [ '-', '@' ], [ '%2D', '%40' ], $url );
		$urlVersions = [ $url, $mostlyDecodedUrl, $extraEncodedUrl ];
		// Is there a query string?
		$qsPos = strpos( $url, '?' );
		if ( $qsPos !== false ) {
			// Try properly encoding the query string.
			parse_str( substr( $url, $qsPos + 1 ), $params );
			$qs = http_build_query( $params );
			$urlVersions[] = substr( $url, 0, $qsPos ) . '?'
				. str_replace( [ '-', '@', '.' ], [ '%2D', '%40', '%2E' ], $qs );
		}
		foreach ( $urlVersions as $searchUrl ) {
			$regex = '|\\['
			. preg_replace( '/([\\/\\:\\(\\)\\+\\-\\.\\?\\&\\%\\*\\{\\}\\[\\]\\#\\|])/', '\\\$1', $searchUrl )
			. '\\s+(.+?)\\]|i';
			if ( preg_match( $regex, $wikitext, $matches ) ) {
				return $matches[1];
			}
		}
		return '';
	}
}

$maintClass = "SyncLinksSheet";
require_once RUN_MAINTENANCE_IF_MAIN;
