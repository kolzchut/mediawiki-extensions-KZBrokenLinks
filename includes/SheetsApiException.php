<?php

namespace MediaWiki\Extension\KZBrokenLinks;

use RuntimeException;

/**
 * A Google auth or Sheets API failure. The message carries Google's error body.
 */
class SheetsApiException extends RuntimeException {

	/**
	 * @return int The HTTP status of the failed response, or 0 if there was none
	 */
	public function getHttpStatus(): int {
		return $this->getCode();
	}
}
