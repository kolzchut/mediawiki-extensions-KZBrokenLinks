<?php

namespace MediaWiki\Extension\KZBrokenLinks;

/**
 * The ALL_LINKS tab of the links sheet: one row per external link, as
 * SyncLinksSheet copies it out of the externallinks table.
 *
 * The rows are written with valueInputOption=RAW, so Sheets stores every
 * value exactly as sent. Page titles and link labels are wiki text, and with
 * USER_ENTERED Sheets would parse them the way it parses typing into a cell:
 * a label such as "+972-2-1234567" or "1/2" became a number or a date.
 * Nothing in ALL_LINKS is meant to be interpreted; the sheet's own tabs
 * derive from it.
 *
 * Under RAW a JSON string lands as text and a JSON number as a number, so the
 * page ID is cast to an integer here to keep column B numeric, as it was when
 * Sheets parsed it.
 */
class AllLinksSheet {
	/** Range the rows are appended to. */
	public const APPEND_RANGE = 'ALL_LINKS!A:ZZZ';

	/** Range cleared before a sync appends a fresh copy. */
	public const CLEAR_RANGE = 'ALL_LINKS!A2:D';

	/** Query parameters for spreadsheets.values.append. */
	public const APPEND_PARAMS = [
		'valueInputOption' => 'RAW',
		'insertDataOption' => 'INSERT_ROWS',
	];

	/**
	 * Build one ALL_LINKS row.
	 *
	 * @param string $url The link's URL, as convertUrl() normalised it
	 * @param int|string $pageId ID of the page carrying the link (el_from)
	 * @param string $title The page's prefixed title
	 * @param string $linkText The link's label in the wikitext, or ''
	 * @return array{0:string,1:int,2:string,3:string}
	 */
	public static function buildRow( string $url, $pageId, string $title, string $linkText ): array {
		return [ $url, (int)$pageId, $title, $linkText ];
	}

	/**
	 * Append rows to ALL_LINKS.
	 *
	 * @param SheetsClient $sheets
	 * @param string $spreadsheetId
	 * @param array[] $rows Rows from buildRow()
	 * @return array The decoded API response
	 */
	public static function appendRows( SheetsClient $sheets, string $spreadsheetId, array $rows ): array {
		return $sheets->appendValues( $spreadsheetId, self::APPEND_RANGE, $rows, self::APPEND_PARAMS );
	}
}
