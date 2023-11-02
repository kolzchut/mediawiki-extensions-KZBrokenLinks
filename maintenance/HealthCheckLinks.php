#!/usr/bin/php
<?php
// @codingStandardsIgnoreStart
$IP = getenv( "MW_INSTALL_PATH" ) ?: __DIR__ . "/../../..";
if ( !is_readable( "$IP/maintenance/Maintenance.php" ) ) {
	die( "MW_INSTALL_PATH needs to be set to your MediaWiki installation.\n" );
}
require_once ( "$IP/maintenance/Maintenance.php" );
// @codingStandardsIgnoreEnd

use MediaWiki\MediaWikiServices;

/**
 * Maintenance script to sync the Mediawiki table externalinks to Google Sheets
 *
 * @ingroup Maintenance
 * @SuppressWarnings(StaticAccess)
 * @SuppressWarnings(LongVariable)
 */
class HealthCheckLinks extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( "CLI utility to poll the health of external links recorded in Google Sheets" );

		// MW 1.28
		if ( method_exists( $this, 'requireExtension' ) ) {
			$this->requireExtension( 'KZBrokenLinks' );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		// Time limit
		$max_secs = $this->getOption( 'runtime', 300 );
		$end_time = time() + $max_secs;

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
		$service = new \Google\Service\Sheets( $client );

		// Check links health
		$domains_checked = [];
		$row_num = 2;
		$today_day = date( 'j' );
		$today_month = date( 'n' );
		$today_year = date( 'Y' );
		while ( time() < $end_time ) {
			// Query the next row in NEXT_CHECK sheet
			$row = $service->spreadsheets_values->get( $spreadsheetId, "NEXT_CHECK!A{$row_num}:F{$row_num}" );
			if ( empty( $row ) || empty( $row->getValues()[0] ) || empty( $row->getValues()[0][1] ) ) {
				$this->output( "Row number {$row_num} in NEXT_CHECK sheet is empty. Exiting.\n" );
				return;
			}

			// Parse URL.
			$url = $row->getValues()[0][1];
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
				$this->output( "Skipping additional link to domain $domain: $url\n" );
				$row_num++;
				continue;
			} elseif ( in_array( $protocol, $excluded_protocols ) ) {
				// Don't call out to an excluded protocol.
				$this->output( "Skipping URL with an excluded protocol: $url\n" );
				$row_num++;
				continue;
			} else {
				// Add the domain to those we'll skip in any subsequent row during this run.
				$domains_checked[] = $domain;
			}

			// Call out to URL and check response status
			$last_status = $row->getValues()[0][5] ?? 0;
			$result = $this->calloutToUrl( $url, $protocol, $last_status >= 300 );
			$redirectUrl = ( strtolower( $result['finalUrl'] ) != $url ) ? $result['finalUrl'] : '';

			// Update row in NEXT_CHECK sheet with response status and today's date.
			$update_row = $row->getValues()[0][0];
			$updated_values = [ [
				"=DATE({$today_year},{$today_month},{$today_day})",
				$result['code'],
				$redirectUrl,
				$result['error'] ?? '',
			] ];
			$valueRange = new \Google\Service\Sheets\ValueRange();
			$valueRange->setValues( $updated_values );
			$firstCell = "LINKS_STATUS!E{$update_row}";
			$service->spreadsheets_values->update(
				$spreadsheetId, $firstCell, $valueRange, [ 'valueInputOption' => 'USER_ENTERED' ]
			);
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
		$proxy = $config[ 'proxy' ] ?? null;
		$timeout = $config[ 'timeout' ] ?? 30;
		$agent = $config[ 'agent' ] ?? 'Kol-Zchut Broken Links HealthCheckLinks';
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
			'isOK' => $sv->isOK(),  //@TODO nix
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
