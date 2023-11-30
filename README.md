# Kol-Zchut Broken Links

## Purpose

This extension provides maintenance scripts to automate updates to an
external (Google Sheets) database of all external links and related data, in
particular status data pertaining to the links' "health".

## Configuration

| Main Key                      | sub-key              | default                                  | description
|-------------------------------|----------------------|------------------------------------------|------------
| $wgKZBrokenLinksGoogleConfig  | `keyPath`            | empty                                    | local path to Google Client authentication key JSON
| $wgKZBrokenLinksGoogleConfig  | `sheetId`            | empty                                    | ID of the Google Sheets document to sync to
| $wgKZBrokenLinksHttpConfig    | `proxy`              | empty                                    | optional proxy configuration for HTTP callouts
| $wgKZBrokenLinksHttpConfig    | `timeout`            | 30                                       | timeout in seconds for HTTP callouts
| $wgKZBrokenLinksHttpConfig    | `agent`              | Kol-Zchut Broken Links HealthCheckLinks  | agent name for HTTP callouts
| $wgKZBrokenLinksHttpConfig    | `excludedProtocols`  | empty                                    | array of protocols to exclude from link health checks (e.g., ftp)

## Maintenance scripts

### SyncLinksSheet
Usage:
php extensions/KZBrokenLinks/maintenance/SyncLinksSheet.php --chunksize={chunk_size} --maxlinks={maxlinks}

| Parameter  | Type    | Description
|------------|---------|------------
| chunksize  | Integer | Maximum number of external links to sync from Mediawiki to Google Sheets per API call (default 500)
| maxlinks   | Integer | Maximum number of external links to sync before exiting (default unlimited)

### HealthCheckLinks
Usage:
php extensions/KZBrokenLinks/maintenance/HealthCheckLinks.php --runtime={runtime} --maxlinks={maxlinks}

| Parameter | Type    | Description
|-----------|---------|------------
| runtime   | Integer | Maximum number of seconds to execute before exiting (default 300)
| maxlinks  | Integer | Maximum number of links to process before exiting (default unlimited)