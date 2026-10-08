<?php

namespace MediaWiki\Extension\KZBrokenLinks;

use MediaWiki\Http\HttpRequestFactory;

/**
 * Minimal Google Sheets v4 client: service-account authentication plus the
 * four spreadsheets.values calls the maintenance scripts need.
 *
 * This replaces google/apiclient, whose google/auth dependency pins
 * firebase/php-jwt to a vulnerable major that cannot be lifted while
 * MediaWiki core pins psr/log 1.x (kolzchut/kz-mediawiki-main#121).
 *
 * Every request goes through MediaWiki's HttpRequestFactory. A non-2xx
 * response throws SheetsApiException carrying Google's error body; transient
 * failures (transport errors, 429 and 5xx) are retried with exponential
 * backoff first, as the SDK's 'retries' => 6 setting used to do.
 */
class SheetsClient {
	public const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';
	public const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets/';

	private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';
	private const JWT_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
	/** Lifetime requested for the signed assertion; Google allows at most one hour. */
	private const ASSERTION_LIFETIME = 3600;
	/** Refresh the access token this many seconds before it expires. */
	private const TOKEN_REFRESH_MARGIN = 300;
	private const RETRYABLE_STATUSES = [ 429, 500, 502, 503, 504 ];
	private const MAX_BACKOFF = 60;
	private const TIMEOUT = 120;

	private HttpRequestFactory $httpRequestFactory;
	private string $clientEmail;
	/** @var resource|\OpenSSLAsymmetricKey */
	private $privateKey;
	private string $tokenUri;
	private string $apiBase;
	private int $maxRetries;
	private ?string $accessToken = null;
	private int $accessTokenExpiry = 0;

	/**
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param string|array $key Path to the service-account JSON key file, or the
	 *  already-decoded key document (the shape $wgKZBrokenLinksGoogleConfig['keyPath']
	 *  has always accepted)
	 * @param string $apiBase Base URL of the spreadsheets resource, ending in '/'
	 * @param int $maxRetries Retries for a transient failure before giving up
	 */
	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		$key,
		string $apiBase = self::API_BASE,
		int $maxRetries = 6
	) {
		$this->httpRequestFactory = $httpRequestFactory;
		$this->apiBase = $apiBase;
		$this->maxRetries = $maxRetries;

		$keyData = self::loadKey( $key );
		$this->clientEmail = $keyData['client_email'];
		$this->tokenUri = $keyData['token_uri'] ?? self::DEFAULT_TOKEN_URI;
		$privateKey = openssl_pkey_get_private( $keyData['private_key'] );
		if ( $privateKey === false ) {
			throw new SheetsApiException(
				'The Google service-account private_key could not be parsed: ' . openssl_error_string()
			);
		}
		$this->privateKey = $privateKey;
	}

	/**
	 * spreadsheets.values.get
	 *
	 * @param string $spreadsheetId
	 * @param string $range A1 notation
	 * @return array[] The rows in the range; empty when the range holds no values
	 */
	public function getValues( string $spreadsheetId, string $range ): array {
		$response = $this->apiRequest( 'GET', $this->valuesUrl( $spreadsheetId, $range ) );
		return $response['values'] ?? [];
	}

	/**
	 * spreadsheets.values.clear
	 *
	 * @param string $spreadsheetId
	 * @param string $range A1 notation
	 * @return array The decoded response
	 */
	public function clearValues( string $spreadsheetId, string $range ): array {
		return $this->apiRequest( 'POST', $this->valuesUrl( $spreadsheetId, $range ) . ':clear', [] );
	}

	/**
	 * spreadsheets.values.append
	 *
	 * @param string $spreadsheetId
	 * @param string $range A1 notation
	 * @param array[] $values Rows to append
	 * @param array $params Query parameters, e.g. valueInputOption and insertDataOption
	 * @return array The decoded response
	 */
	public function appendValues( string $spreadsheetId, string $range, array $values, array $params = [] ): array {
		$url = $this->valuesUrl( $spreadsheetId, $range ) . ':append';
		if ( $params ) {
			$url .= '?' . http_build_query( $params );
		}
		return $this->apiRequest( 'POST', $url, [ 'values' => $values ] );
	}

	/**
	 * spreadsheets.values.batchUpdate
	 *
	 * @param string $spreadsheetId
	 * @param array[] $data Value ranges, each [ 'range' => A1 notation, 'values' => rows ]
	 * @param string $valueInputOption
	 * @return array The decoded response
	 */
	public function batchUpdateValues( string $spreadsheetId, array $data, string $valueInputOption ): array {
		return $this->apiRequest(
			'POST',
			$this->apiBase . rawurlencode( $spreadsheetId ) . '/values:batchUpdate',
			[ 'valueInputOption' => $valueInputOption, 'data' => $data ]
		);
	}

	/**
	 * Build the signed RS256 JWT that is exchanged for an access token.
	 *
	 * @param int $now Issue time, as a Unix timestamp
	 * @return string
	 */
	public function buildAssertion( int $now ): string {
		$header = [ 'alg' => 'RS256', 'typ' => 'JWT' ];
		$claims = [
			'iss' => $this->clientEmail,
			'scope' => self::SCOPE,
			'aud' => $this->tokenUri,
			'iat' => $now,
			'exp' => $now + self::ASSERTION_LIFETIME,
		];
		$signingInput = self::base64UrlEncode( json_encode( $header, JSON_UNESCAPED_SLASHES ) )
			. '.' . self::base64UrlEncode( json_encode( $claims, JSON_UNESCAPED_SLASHES ) );
		if ( !openssl_sign( $signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256 ) ) {
			throw new SheetsApiException( 'Signing the Google auth assertion failed: ' . openssl_error_string() );
		}
		return $signingInput . '.' . self::base64UrlEncode( $signature );
	}

	/**
	 * Return a valid access token, fetching a new one when none is cached or the
	 * cached one is about to expire. A sync run can outlive a single token.
	 *
	 * @return string
	 */
	private function getAccessToken(): string {
		$now = time();
		if ( $this->accessToken !== null && $now < $this->accessTokenExpiry - self::TOKEN_REFRESH_MARGIN ) {
			return $this->accessToken;
		}

		$response = $this->send( 'POST', $this->tokenUri, [
			'grant_type' => self::JWT_GRANT_TYPE,
			'assertion' => $this->buildAssertion( $now ),
		] );
		if ( empty( $response['access_token'] ) || !is_string( $response['access_token'] ) ) {
			throw new SheetsApiException(
				"Google token endpoint {$this->tokenUri} returned no access_token: " . json_encode( $response )
			);
		}
		$this->accessToken = $response['access_token'];
		$this->accessTokenExpiry = $now + (int)( $response['expires_in'] ?? self::ASSERTION_LIFETIME );
		return $this->accessToken;
	}

	/**
	 * Send an authenticated Sheets API request. A 401 drops the cached token and
	 * retries once with a fresh one.
	 *
	 * @param string $method
	 * @param string $url
	 * @param array|null $body Encoded as the JSON request body when not null
	 * @return array
	 */
	private function apiRequest( string $method, string $url, ?array $body = null ): array {
		$json = null;
		if ( $body !== null ) {
			// An empty array must encode as an object, as clear's request body is.
			$json = $body === [] ? '{}' : json_encode(
				$body,
				JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
			);
		}
		try {
			return $this->send( $method, $url, $json, $this->getAccessToken() );
		} catch ( SheetsApiException $e ) {
			if ( $e->getHttpStatus() !== 401 ) {
				throw $e;
			}
			$this->accessToken = null;
			return $this->send( $method, $url, $json, $this->getAccessToken() );
		}
	}

	/**
	 * Send one request, retrying transient failures, and decode the JSON reply.
	 *
	 * @param string $method
	 * @param string $url
	 * @param string|array|null $postData A JSON string, or form fields
	 * @param string|null $accessToken Bearer token, if the request needs one
	 * @return array
	 */
	private function send( string $method, string $url, $postData, ?string $accessToken = null ): array {
		$backoff = 1;
		for ( $attempt = 0; ; $attempt++ ) {
			$options = [ 'method' => $method, 'timeout' => self::TIMEOUT ];
			if ( $postData !== null ) {
				$options['postData'] = $postData;
			}
			$request = $this->httpRequestFactory->create( $url, $options, __METHOD__ );
			if ( $accessToken !== null ) {
				$request->setHeader( 'Authorization', "Bearer $accessToken" );
			}
			if ( is_string( $postData ) ) {
				$request->setHeader( 'Content-Type', 'application/json' );
			}
			$status = $request->execute();
			$code = $request->getStatus();
			$content = (string)$request->getContent();

			if ( $code >= 200 && $code < 300 ) {
				$decoded = $content === '' ? [] : json_decode( $content, true );
				if ( !is_array( $decoded ) ) {
					throw new SheetsApiException(
						"Google API $method $url returned HTTP $code with a body that is not JSON: $content",
						$code
					);
				}
				return $decoded;
			}

			$transient = $code === 0 || in_array( $code, self::RETRYABLE_STATUSES );
			if ( $transient && $attempt < $this->maxRetries ) {
				sleep( $backoff );
				$backoff = min( self::MAX_BACKOFF, $backoff * 2 );
				continue;
			}

			if ( $code === 0 ) {
				$errors = array_map(
					static fn ( $msg ) => wfMessage( $msg )->inLanguage( 'en' )->text(),
					$status->getMessages( 'error' )
				);
				$detail = 'no HTTP response: ' . implode( '; ', $errors );
			} else {
				$detail = "HTTP $code: $content";
			}
			throw new SheetsApiException( "Google API $method $url failed: $detail", $code );
		}
	}

	/**
	 * @param string $spreadsheetId
	 * @param string $range
	 * @return string
	 */
	private function valuesUrl( string $spreadsheetId, string $range ): string {
		return $this->apiBase . rawurlencode( $spreadsheetId ) . '/values/' . rawurlencode( $range );
	}

	/**
	 * Read and validate a service-account key.
	 *
	 * @param string|array $key Path to the JSON key file, or its decoded content
	 * @return array
	 */
	private static function loadKey( $key ): array {
		if ( is_array( $key ) ) {
			$data = $key;
		} elseif ( is_string( $key ) && $key !== '' ) {
			if ( !is_readable( $key ) ) {
				throw new SheetsApiException( "Google service-account key file '$key' is not readable" );
			}
			$data = json_decode( (string)file_get_contents( $key ), true );
			if ( !is_array( $data ) ) {
				throw new SheetsApiException( "Google service-account key file '$key' is not valid JSON" );
			}
		} else {
			throw new SheetsApiException( "\$wgKZBrokenLinksGoogleConfig['keyPath'] is not set" );
		}

		if ( ( $data['type'] ?? 'service_account' ) !== 'service_account' ) {
			throw new SheetsApiException(
				"The Google key is of type '{$data['type']}'; only a service-account key is supported"
			);
		}
		foreach ( [ 'client_email', 'private_key' ] as $field ) {
			if ( empty( $data[$field] ) || !is_string( $data[$field] ) ) {
				throw new SheetsApiException( "The Google service-account key has no '$field'" );
			}
		}
		return $data;
	}

	/**
	 * @param string $data
	 * @return string
	 */
	private static function base64UrlEncode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
