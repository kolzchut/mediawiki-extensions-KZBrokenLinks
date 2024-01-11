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

		// Set up Google Client and Sheets Service
		$client = new \Google_Client();
		$client->setApplicationName( 'Google Sheets API' );
		$client->setScopes( [ \Google\Service\Sheets::SPREADSHEETS ] );
		$client->setAccessType( 'offline' );
		$client->setAuthConfig( $googleConfig[ 'keyPath' ] );
		$service = new \Google\Service\Sheets( $client );

		// Clear all-links rows.
		$clearService = new \Google\Service\Sheets\ClearValuesRequest();
		$service->spreadsheets_values->clear( $spreadsheetId, 'ALL_LINKS!A2:C', $clearService );
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
		for ( $last_el_id = 0; $last_el_id < $max_el_id; ) {
			$this->output( "Loading externallinks data from el_id $last_el_id...\n" );
			$res = $dbw->select(
				[
					'el' => 'externallinks',
					'p' => 'page',
				],
				[
					'el.el_id',
					'el.el_from',
					'el.el_to',
					'p.page_title',
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
				// Convert table row to spreadsheet row values.
				$url = $this->convertUrl( $row['el_to'] );
				if ( $url === false ) {
					// URL with excluded protocol, so skip this row.
					continue;
				}
				$values[] = [
					$url,
					$row['el_from'],
					$this->convertPageTitle( $row['page_title'] ),
				];
				$last_el_id = $row['el_id'];
				if ( $max_links > 0 && ++$processed_count == $max_links ) {
					// Maximum reached, so stop adding rows to sync.
					break;
				}
			}

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
	 * Massage URL to ensure proper form.
	 * @param string $url The URL as recorded in the externallinks table
	 * @return string $url The URL ready for export to the ALL_LINKS sheet
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

		// URL-decode the domain name.
		if ( count( $urlexp ) === 2 ) {
			$locexp = explode( '/', $urlexp[1], 2 );
			$domain = urldecode( $locexp[0] );
			$url = $urlexp[0] . '://' . $domain;
			if ( count( $locexp ) === 2 ) {
				$url = $url . '/' . $locexp[1];
			}
		}

		// Convert to all-lowercase to reduce dupe rows.
		$url = strtolower( $url );

		return $url;
	}

	/**
	 * Convert underscores to spaces.
	 * @param string $title The title as recorded in the pages table
	 * @return string $title The title with underscores converted to spaces
	 */
	private function convertPageTitle( $title ) {
		$title = str_replace( '_', ' ', $title );
		return $title;
	}
}

$maintClass = "SyncLinksSheet";
require_once RUN_MAINTENANCE_IF_MAIN;
