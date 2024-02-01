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

use MediaWiki\MediaWikiServices;

/**
 * Maintenance script to sync the Mediawiki table externalinks to Google Sheets
 *
 * @ingroup Maintenance
 * @SuppressWarnings(StaticAccess)
 * @SuppressWarnings(LongVariable)
 */
class HealthCheckLinks extends KZBrokenLinksMaintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( "CLI utility to poll the health of external links recorded in Google Sheets" );
		$this->addOption( 'runtime',
			'Maximum number of seconds to execute before exiting (default 60)',
			false, true
		);
		$this->addOption( 'maxlinks',
			'Maximum number of links to process before exiting (default unlimited)',
			false, true
		);
		$this->addOption( 'batchsize',
			'Maximum number of link status rows per callout to the Google Sheets batch update API (default 20)',
			false, true
		);
		$this->addOption( 'querysize',
			'Maximum number of rows to query per callout to the Google Sheets get API (default 1000)',
			false, true
		);

		// MW 1.28
		if ( method_exists( $this, 'requireExtension' ) ) {
			$this->requireExtension( 'KZBrokenLinks' );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		// Update batch size and get query size
		$query_size = intval( $this->getOption( 'querysize', 1000 ) );
		$batch_size = intval( $this->getOption( 'batchsize', 20 ) );

		// Time limit
		$max_secs = $this->getOption( 'runtime', 60 );
		$end_time = time() + $max_secs;

		// Optionally also limit total number of links/rows
		$max_links = $this->getOption( 'maxlinks', 0 );
		$processed_count = 0;

		// Load configuration
		$config = $this->getConfig();
		$googleConfig = $config->get( 'KZBrokenLinksGoogleConfig' );
		$spreadsheetId = $googleConfig[ 'sheetId' ];
		$excluded_protocols = $config->get( 'KZBrokenLinksHttpConfig' )[ 'excludedProtocols' ];

		// Set up Google Client and Sheets Service
		$client = new \Google_Client();
		$client->setApplicationName( 'Google Sheets API' );
		$client->setScopes( [ \Google\Service\Sheets::SPREADSHEETS ] );
		$client->setAccessType( 'offline' );
		$client->setAuthConfig( $googleConfig[ 'keyPath' ] );
		$client->setConfig( 'retry', [ 'retries' => 6 ] );
		$service = new \Google\Service\Sheets( $client );

		// Check links health
		$domains_checked = [];
		$end_reached = false;
		$today_day = date( 'j' );
		$today_month = date( 'n' );
		$today_year = date( 'Y' );
		$first_nonskip_row = 2;
		while ( time() < $end_time && ( $max_links === 0 || $processed_count < $max_links ) ) {
			// Query NEXT_CHECK rows, run checks, and amass updates until we either reach maximum
			// batch size or run out of rows to check.
			$status_updates = [];
			$start_row = $first_nonskip_row;
			while ( count( $status_updates ) < $batch_size && time() < $end_time ) {
				// Query enough rows in NEXT_CHECK to fill the update batch (if we don't skip any)
				$end_row = $start_row + $query_size;
				$rows = $service->spreadsheets_values->get( $spreadsheetId, "NEXT_CHECK!A{$start_row}:F{$end_row}" );
				if ( empty( $rows ) || empty( $rows->getValues()[0] ) || $rows->getValues()[0][0] == '#N/A' ) {
					$this->output( "Row number {$start_row} in NEXT_CHECK sheet is empty. Preparing to exit.\n" );
					$end_reached = true;
					break;
				}
				// Loop through the returned rows and perform health checks.
				for (
					$row_i = 0;
					!empty( $rows->getValues()[$row_i] ) && !empty( $rows->getValues()[$row_i][1] )
						&& count( $status_updates ) < $batch_size;
					$row_i++, $start_row++
				) {
					// Parse URL.
					$url = $rows->getValues()[$row_i][1];
					preg_match( "|([a-zA-Z]+)\\:\\/*([^\\/]+)\\/*(.*)|", $url, $url_components );
					$protocol = $url_components[1];
					$domain = $url_components[2];

					// Remove anchor portion from end of URL.
					if ( strpos( $url, '#' ) !== false ) {
						$url = substr( $url, 0, strpos( $url, '#' ) );
					}

					// Don't call out twice to the same domain within a single run.
					if ( in_array( $domain, $domains_checked ) ) {
						// Skip this row.
//						$this->output( "Skipping additional link to domain $domain: $url\n" );
						if ( $first_nonskip_row == $start_row ) {
							// There are no non-skip rows before this one, so future queries can skip all rows up to
							// and including this one.
							$first_nonskip_row++;
						}
						continue;
					} elseif ( in_array( $protocol, $excluded_protocols ) ) {
						// Don't call out to an excluded protocol.
						$this->output( "Skipping URL with an excluded protocol: $url\n" );
						if ( $first_nonskip_row == $start_row ) {
							// There are no non-skip rows before this one, so future queries can skip all rows up to
							// and including this one.
							$first_nonskip_row++;
						}
						continue;
					} else {
						// Add the domain to those we'll skip in any subsequent row during this run.
						$domains_checked[] = $domain;
					}

					// Call out to URL and check response status
					$last_status = $rows->getValues()[$row_i][5] ?? 0;
					$result = $this->calloutToUrl( $url, $protocol, $last_status >= 300 );
					$redirectUrl = ( strtolower( $result['finalUrl'] ) != $url ) ? $result['finalUrl'] : '';

					// Add status update to batch.
					$update_row = $rows->getValues()[$row_i][0];
					$updated_values = [ [
						"=DATE({$today_year},{$today_month},{$today_day})",
						$result['code'],
						$redirectUrl,
						$result['error'] ?? '',
					] ];
					$status_updates[] = new \Google\Service\Sheets\ValueRange( [
						'range' => "LINKS_STATUS!E{$update_row}:H{$update_row}",
						'values' => $updated_values,
					] );

					$this->output( "$url status: {$result['code']}\n" );
				}
				$this->maintainRateLimit();
			}

			// Batch update LINKS_STATUS sheet with the collected health check data.
			$batch_rows = count( $status_updates );
			$this->output( "Batch updating {$batch_rows} rows in LINKS_STATUS...\n" );
			$result = $service->spreadsheets_values->batchUpdate(
				$spreadsheetId,
				new \Google\Service\Sheets\BatchUpdateValuesRequest( [
					'valueInputOption' => 'USER_ENTERED',
					'data' => $status_updates,
				] )
			);
			if ( $end_reached ) {
				// No more rows left to process, so we're done.
				return;
			}
			$this->maintainRateLimit();
			$processed_count += $batch_rows;
		}
	}

	/**
	 * Perform the callout and compile results.
	 * @param string $url The URL to check
	 * @param boolean $lastCheckFailed Whether the last check returned a failure code
	 * @return array $result Array of information from the server response
	 */
	private function calloutToUrl( $url, $lastCheckFailed = false ) {
		// Config
		$config = $this->getConfig()->get( 'KZBrokenLinksHttpConfig' );
		$proxy = empty( $config[ 'proxy' ] ) ? null : $config[ 'proxy' ];
		$timeout = empty( $config[ 'timeout' ] ) ? 30 : $config[ 'timeout' ];
		$agent = empty( $config[ 'agent' ] ) ? 'Kol-Zchut Broken Links HealthCheckLinks' : $config[ 'agent' ];
		$method = $lastCheckFailed ? 'GET' : 'HEAD';

		// Make the callout
		$httpOptions = [
			'timeout' => $timeout,
			'connectTimeout' => $timeout,
			'method' => $method,
			'proxy' => $proxy,
			'userAgent' => $agent,
			// 'logger' => $logger,  // These two we probably won't need.
			// 'caInfo' => $caBundlePath,
		];

		$httpRequest = MediaWikiServices::getInstance()->getHttpRequestFactory()->create(
			$url, $httpOptions, __METHOD__ );
		$sv = $httpRequest->execute()->getStatusValue();

		// Assemble response data.
		$result = [
			'code' => ( $httpRequest->getStatus() > 0 ) ? $httpRequest->getStatus() : 999,
			'finalUrl' => $httpRequest->getFinalUrl(),
		];

		// If there were errors, compile the error codes.
		if ( !$sv->isOK() ) {
			$result['error'] = $this->getCalloutError( $sv );
		}

		return $result;
	}

	/**
	 * Compile server error message
	 * @param \StatusValue $sv Callout response status value object
	 * @return string $error Compiled error message or blank string if no error
	 */
	private function getCalloutError( $sv ) {
		$error = '';
		$svErrors = $sv->getErrors();
		if ( isset( $svErrors[0] ) ) {
			$error = $svErrors[0]['message'];
			// Add reason verbiage if given.
			// param values vary per failure type (ex. unknown host vs unknown page)
			if ( isset( $svErrors[0]['params'][0] ) ) {
				if ( is_numeric( $svErrors[0]['params'][0] ) ) {
					if ( isset( $svErrors[0]['params'][1] ) ) {
						// @phan-suppress-next-line PhanTypeInvalidDimOffset
						$error .= ': ' . $svErrors[0]['params'][1];
					}
				} else {
					$error .= ': ' . $svErrors[0]['params'][0];
				}
			}
		}
		return $error;
	}
}

$maintClass = "HealthCheckLinks";
require_once RUN_MAINTENANCE_IF_MAIN;
