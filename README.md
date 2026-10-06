# Kol-Zchut Broken Links

## Purpose

This extension provides maintenance scripts to automate updates to an
external (Google Sheets) database of all external links and related data, in
particular status data pertaining to the links' "health".

## Installation

1. Download the extension
2. Run `composer install --no-dev` in the extension's directory
3. Add `wfLoadExtension( 'KZBrokenLinks' )` to `LocalSettings.php` or your custom PHP config file

## Configuration

| Main Key                     | sub-key             | default                                 | description                                                       |
| ---------------------------- |---------------------|-----------------------------------------|-------------------------------------------------------------------|
| $wgKZBrokenLinksGoogleConfig | `keyPath`           | empty                                   | local path to Google Client authentication key JSON               |
| $wgKZBrokenLinksGoogleConfig | `sheetId`           | empty                                   | ID of the Google Sheets document to sync to                       |
| $wgKZBrokenLinksGoogleConfig | `rateLimit`         | 60                                      | Maximum Google API callouts per minute                            |
| $wgKZBrokenLinksHttpConfig   | `proxy`             | empty                                   | optional proxy configuration for HTTP callouts                    |
| $wgKZBrokenLinksHttpConfig   | `timeout`           | 30                                      | timeout in seconds for HTTP callouts                              |
| $wgKZBrokenLinksHttpConfig   | `agent`             | Kol-Zchut Broken Links HealthCheckLinks | agent name for HTTP callouts                                      |
| $wgKZBrokenLinksHttpConfig   | `followRedirects`   | true                                    | Should HTTP redirects be followed                                 |
| $wgKZBrokenLinksHttpConfig   | `excludedProtocols` | empty                                   | array of protocols to exclude from link health checks (e.g., ftp) |

### sheetId

The appropriate Google Sheet can be created by uploading the included `google-sheets-template.xslx`;
make sure it is converted to a native Google Sheet, otherwise the extension won't be able to use it.

## Maintenance scripts

### SyncLinksSheet

Usage:
php extensions/KZBrokenLinks/maintenance/SyncLinksSheet.php --chunksize={chunk_size} --maxlinks={maxlinks}

| Parameter | Type    | Description                                                                                         |
| --------- | ------- | --------------------------------------------------------------------------------------------------- |
| chunksize | Integer | Maximum number of external links to sync from Mediawiki to Google Sheets per API call (default 500) |
| maxlinks  | Integer | Maximum number of external links to sync before exiting (default unlimited)                         |
| no-recase | Flag    | Skip the LINKS_STATUS re-case step described below                                                  |

#### URL case

URLs are exported with their case preserved. Only the scheme and host are
lowercased (both are case-insensitive, and MediaWiki already stores the host
lowercased); the path, query string and fragment are written byte-exact,
because many servers treat them case-sensitively and `HealthCheckLinks`
requests exactly the URL in the sheet.

Earlier versions lowercased the whole URL. To repair sheets written by those
versions, each run reads `LINKS_STATUS!B` and rewrites every all-lowercase URL
that is the lowercase form of exactly one URL exported in that run with its
exact-case form (written as raw text). Rows with several case variants are
skipped as ambiguous, rows with no matching URL (typically links since
removed from the wiki) are left alone, and the counts of each are printed.
A corrected row no longer qualifies, so once a sheet has been repaired the
step writes nothing. It is skipped when `--maxlinks` is set, since a partial
export cannot tell a removed link from one it did not reach, and can be
disabled with `--no-recase`.

Known limit: `NEW_LINKS` finds new URLs with `MATCH`, which is
case-insensitive, so URLs that differ only by case share a single
`LINKS_STATUS` row and only one of them is health-checked. On the dev Hebrew
wiki that affects about 1.4% of distinct URLs (242 of 17,874), which is
accepted rather than changing the template's formulas.

### HealthCheckLinks

Usage:
php extensions/KZBrokenLinks/maintenance/HealthCheckLinks.php --runtime={runtime} --maxlinks={maxlinks}

| Parameter | Type    | Description                                                                                       |
| --------- | ------- | ------------------------------------------------------------------------------------------------- |
| runtime   | Integer | Maximum number of seconds to execute before exiting (default 300)                                 |
| maxlinks  | Integer | Maximum number of links to process before exiting (default unlimited)                             |
| batchsize | Integer | Maximum number of link status rows per callout to the Google Sheets batch update API (default 20) |
| querysize | Integer | Maximum number of rows to query per callout to the Google Sheets get API (default 1000)           |
