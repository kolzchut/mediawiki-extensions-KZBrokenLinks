<?php

/**
 * Abstract extension of Maintenance class with Google API rate limiting for us by KZBrokenLinks subclasses.
 *
 * @ingroup Maintenance
 * @SuppressWarnings(StaticAccess)
 * @SuppressWarnings(LongVariable)
 */
abstract class KZBrokenLinksMaintenance extends Maintenance {
	private array $apiCalloutTimes;
	private int $rateLimit;

	/**
	 * Ensure API callouts don't exceed the configured rate limit.
	 */
	protected function maintainRateLimit() {
		// Setup on the first call.
		if ( !isset( $this->apiCalloutTimes ) ) {
			$this->apiCalloutTimes = [];
			$googleConfig = $this->getConfig()->get( 'KZBrokenLinksGoogleConfig' );
			$this->rateLimit = intval( empty( $googleConfig[ 'rateLimit' ] ) ) ? 60 : $googleConfig[ 'rateLimit' ];
		}

		// Clear callouts recorded more than a minute ago.
		$minute_ago = time() - 60;
		$this->apiCalloutTimes = array_values(
			array_filter( $this->apiCalloutTimes, fn( $time ) => $time > $minute_ago )
		);

		// Add timestamp for the most recent callout.
		$this->apiCalloutTimes[] = time();

		// Have total callouts in the last minute reached the limit?
		if ( count( $this->apiCalloutTimes ) >= $this->rateLimit ) {
			// Pause execution until the oldest recorded timestamp is one minute old.
			$pause = $this->apiCalloutTimes[0] - $minute_ago;
			$this->output(
				"Pausing $pause sec. to respect Google API rate limit of {$this->rateLimit} callouts per minute.\n"
			);
			sleep( $pause );
		}
	}
}
