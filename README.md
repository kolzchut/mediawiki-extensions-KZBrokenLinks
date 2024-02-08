# Kol-Zchut Broken Links

## Purpose

This extension provides maintenance scripts to automate updates to an
external (Google Sheets) database of all external links and related data, in
particular status data pertaining to the links' "health".

## Installation

1. Download the extension
2. Add the extension's `composer.json` to `composer.local.json` in MediaWiki's installation directory:
   ```json
   "extra": {
   	"merge-plugin": {
   		"include": [
   			"extensions/KZBrokenLinks/composer.json"
   		]
   }
   ```
3. Run `composer update` in MediaWiki's installation directory:
4. Add `wfLoadExtension( 'KZBrokenLinks' )` to `LocalSettings.php` or your custom PHP config file

## Configuration

| Main Key                     | sub-key             | default                                 | description                                                       |
| ---------------------------- | ------------------- | --------------------------------------- | ----------------------------------------------------------------- |
| $wgKZBrokenLinksGoogleConfig | `keyPath`           | empty                                   | local path to Google Client authentication key JSON               |
| $wgKZBrokenLinksGoogleConfig | `sheetId`           | empty                                   | ID of the Google Sheets document to sync to                       |
| $wgKZBrokenLinksGoogleConfig | `rateLimit`         | 60                                      | Maximum Google API callouts per minute                            |
| $wgKZBrokenLinksHttpConfig   | `proxy`             | empty                                   | optional proxy configuration for HTTP callouts                    |
| $wgKZBrokenLinksHttpConfig   | `timeout`           | 30                                      | timeout in seconds for HTTP callouts                              |
| $wgKZBrokenLinksHttpConfig   | `agent`             | Kol-Zchut Broken Links HealthCheckLinks | agent name for HTTP callouts                                      |
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

### HealthCheckLinks

Usage:
php extensions/KZBrokenLinks/maintenance/HealthCheckLinks.php --runtime={runtime} --maxlinks={maxlinks}

| Parameter | Type    | Description                                                                                       |
| --------- | ------- | ------------------------------------------------------------------------------------------------- |
| runtime   | Integer | Maximum number of seconds to execute before exiting (default 300)                                 |
| maxlinks  | Integer | Maximum number of links to process before exiting (default unlimited)                             |
| batchsize | Integer | Maximum number of link status rows per callout to the Google Sheets batch update API (default 20) |
| querysize | Integer | Maximum number of rows to query per callout to the Google Sheets get API (default 1000)           |
