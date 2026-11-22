<?php

namespace MediaWiki\Extension\KZBrokenLinks\Tests\Unit;

use MediaWiki\Extension\KZBrokenLinks\AllLinksSheet;
use MediaWiki\Extension\KZBrokenLinks\SheetsClient;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Status\Status;
use MediaWikiUnitTestCase;
use MWHttpRequest;

/**
 * @covers \MediaWiki\Extension\KZBrokenLinks\AllLinksSheet
 */
class AllLinksSheetTest extends MediaWikiUnitTestCase {
	private const SPREADSHEET_ID = 'test-spreadsheet';
	private const TOKEN_URI = 'https://token.invalid/token';
	private const API_BASE = 'https://sheets.invalid/v4/spreadsheets/';

	/**
	 * Wiki text that a sheet parsing user input would not keep as typed.
	 *
	 * @return array[]
	 */
	public static function provideWikiText(): array {
		return [
			'leading equals sign' => [ '=1+1' ],
			'leading equals sign with a function' => [ '=CONCATENATE("a","b")' ],
			'leading plus sign' => [ '+972-2-1234567' ],
			'leading minus sign' => [ '-5' ],
			'leading at sign' => [ '@sum' ],
			'leading tab' => [ "\t=1+1" ],
			'leading carriage return' => [ "\r=1+1" ],
			'fraction' => [ '1/2' ],
		];
	}

	/**
	 * @dataProvider provideWikiText
	 */
	public function testBuildRowKeepsTitleAndLabelAsGiven( string $text ) {
		$row = AllLinksSheet::buildRow( 'https://example.com/x', '42', $text, $text );

		$this->assertSame( [ 'https://example.com/x', 42, $text, $text ], $row );
	}

	public function testAppendParamsAskForRawValues() {
		$this->assertSame( 'RAW', AllLinksSheet::APPEND_PARAMS['valueInputOption'] );
		$this->assertSame( 'INSERT_ROWS', AllLinksSheet::APPEND_PARAMS['insertDataOption'] );
	}

	/**
	 * @dataProvider provideWikiText
	 */
	public function testAppendRowsSendsLabelAsLiteralText( string $text ) {
		$sent = [];
		$client = new SheetsClient(
			$this->recordingHttpFactory( $sent ), $this->serviceAccountKey(), self::API_BASE, 0
		);

		AllLinksSheet::appendRows( $client, self::SPREADSHEET_ID, [
			AllLinksSheet::buildRow( 'https://example.com/x', 42, "Page $text", $text ),
		] );

		$this->assertCount( 2, $sent, 'one token request, one append' );
		[ $url, $options ] = $sent[1];

		$appendUrl = self::API_BASE . self::SPREADSHEET_ID
			. '/values/' . rawurlencode( AllLinksSheet::APPEND_RANGE ) . ':append?';
		$this->assertStringStartsWith( $appendUrl, $url );
		parse_str( (string)parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'RAW', $query['valueInputOption'], 'Sheets stores RAW values as sent, never as formulas' );

		$this->assertSame( 'POST', $options['method'] );
		$body = json_decode( $options['postData'], true );
		$this->assertSame(
			[ 'values' => [ [ 'https://example.com/x', 42, "Page $text", $text ] ] ],
			$body
		);
	}

	public function testSyncScriptUsesTheRawAppend() {
		// The maintenance script cannot be loaded without a wiki, so check that
		// it routes ALL_LINKS through this class and has no other write to it.
		$script = file_get_contents( __DIR__ . '/../../../maintenance/SyncLinksSheet.php' );

		$this->assertStringContainsString( 'AllLinksSheet::appendRows(', $script );
		$this->assertStringContainsString( 'AllLinksSheet::buildRow(', $script );
		$this->assertStringNotContainsString( "'ALL_LINKS!", $script );
	}

	/**
	 * @param array &$sent Receives [ url, options ] for each request created
	 * @return HttpRequestFactory
	 */
	private function recordingHttpFactory( array &$sent ): HttpRequestFactory {
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturnCallback(
			function ( $url, array $options ) use ( &$sent ) {
				$sent[] = [ $url, $options ];
				$content = $url === self::TOKEN_URI
					? json_encode( [ 'access_token' => 'test-token', 'expires_in' => 3600 ] )
					: json_encode( [ 'spreadsheetId' => self::SPREADSHEET_ID ] );
				$request = $this->createMock( MWHttpRequest::class );
				$request->method( 'execute' )->willReturn( Status::newGood() );
				$request->method( 'getStatus' )->willReturn( 200 );
				$request->method( 'getContent' )->willReturn( $content );
				return $request;
			}
		);
		return $factory;
	}

	/**
	 * A throwaway service-account key, its private key generated once per run.
	 *
	 * @return array
	 */
	private function serviceAccountKey(): array {
		static $pem = null;
		if ( $pem === null ) {
			$key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
			$this->assertNotFalse( $key, 'openssl could not generate a test key' );
			openssl_pkey_export( $key, $pem );
		}
		return [
			'type' => 'service_account',
			'client_email' => 'test@example.invalid',
			'private_key' => $pem,
			'token_uri' => self::TOKEN_URI,
		];
	}
}
