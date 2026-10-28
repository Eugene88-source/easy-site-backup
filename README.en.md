# Easy Site Backup

[![Release](https://img.shields.io/github/v/release/Eugene88-source/easy-site-backup)](https://github.com/Eugene88-source/easy-site-backup/releases/latest)
[![Tests](https://github.com/Eugene88-source/easy-site-backup/actions/workflows/tests.yml/badge.svg)](https://github.com/Eugene88-source/easy-site-backup/actions/workflows/tests.yml)
![WordPress 5.8+](https://img.shields.io/badge/WordPress-5.8%2B-21759b)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

[Українською](README.md) | **English**

A simple WordPress plugin for backing up the database and site files — and restoring from a backup in one click. It works on budget hosting with strict limits: backup and restore run in short steps, so they never hit the PHP time limit or the Cloudflare timeout, and an interrupted step resumes from where it stopped.

> The plugin interface is in Ukrainian. Button and section names below are given in English with the original Ukrainian label in parentheses.

## Installation
1. Download `easy-site-backup.zip` from the [latest release](https://github.com/Eugene88-source/easy-site-backup/releases/latest) (this file, not "Source code").
2. In the WordPress admin: **Plugins → Add New → Upload Plugin**, choose the archive and click **Install Now**.
3. Activate the plugin.

After that the plugin updates itself from this repository's releases — see [Updates](#updates).

## Quick start
1. Open **Tools → Easy Site Backup**.
2. Choose the backup type (database + files by default) and click **Create backup** (Створити бекап).
3. When the backup is ready, click **Download** (Завантажити) to save the archive to your computer: a backup that only lives on the same server won't help if the server is lost.
4. To make backups automatically, turn on the **Schedule** (Розклад) at the bottom of the page.

To return the site to a backup's state, click **Restore** (Відновити) next to it in the list.

## Features
- A **Tools → Easy Site Backup** page in the admin area.
- Three backup types: database + files, database only, files only.
- Each backup is a single ZIP archive.
- Step-by-step processing: each step lasts up to 20 seconds, so a backup never hits `max_execution_time` or the Cloudflare timeout (100 s).
- Progress bar and cancellation. If you close the page, the backup continues in the background via WP-Cron.
- An interrupted step (timeout, dropped connection) is retried from the same point.
- A list of backups with download, restore, log and delete — one at a time or several selected at once.

## Schedule
In the **Schedule** (Розклад) section of the plugin page you can turn on automatic backups: daily or weekly at a set time (in the site's time zone), of any of the three types.

- Only the last N scheduled backups are kept (5 by default); older ones are deleted after each new one. Backups created manually are never deleted. Failed scheduled backups are removed after 30 days.
- Email notifications: on errors only (default), after every backup, or never. The address is the site admin's or one you specify; the backup log is attached.
- If a backup is already running or a restore is in progress at launch time, the run is skipped and an error email is sent.
- WP-Cron only fires when someone visits the site. For exact timing, disable it in `wp-config.php` (`define( 'DISABLE_WP_CRON', true );`) and run it from system cron every minute:
  `* * * * * curl -s https://example.com/wp-cron.php > /dev/null`

## Restore
The **Restore** (Відновити) button in the backup list returns the site to the state of the backup: the database and the files contained in it. Restore also runs in steps with a progress bar and continues via WP-Cron if you close the page. It cannot be cancelled.

- The database is first imported into temporary `esbr_…` tables, and at the end a single `RENAME TABLE` swaps them in for the live ones. If the restore fails before that, the site's database stays unchanged.
- If the host kills a step (time or memory limit, restart), the next one continues from where it stopped. A statement that was executed but didn't make it into the saved state is recognised by row count and not repeated — even in tables without a key; a MySQL named lock guarantees that the killed step's query has finished. After three interruptions in a row you get a clear error and the database is left unchanged. Verified by a test that kills the process at random moments.
- Each file is extracted next to the original and only then replaces it; integrity is checked by CRC.
- Not restored: `wp-config.php`, the plugin itself, the backup storage folder and cache drop-ins (`wp-content/object-cache.php`, `wp-content/advanced-cache.php`) — they are tied to the server (Redis, Memcached), and on another server the site would not open with them.
- Files and tables that are not in the backup are not deleted.
- A backup can only be restored to the same site: the address and table prefix must match. Migration between sites is not supported yet.
- If you logged in again after the backup was made, you may need to log in once more after the restore.
- Restore progress and result are written to the backup's log.

### Restore from a file
If the site is broken or the server is lost, you can restore from an archive you downloaded to your computer earlier:

1. Install a clean WordPress with the same site address and the same table prefix (`$table_prefix` in `wp-config.php`).
2. Install and activate Easy Site Backup.
3. On the plugin page, in the "Existing backups" (Наявні резервні копії) section, choose the archive and click **Upload archive** (Завантажити архів).
4. Next to the uploaded backup, click **Restore** (Відновити).

- The archive is sent in chunks of up to 8 MB (smaller if the server limits request size), so its size is not limited by `upload_max_filesize` or Cloudflare's limit. A failed chunk is resent.
- The server checks that it is an Easy Site Backup archive (`manifest.json`); file integrity is checked by CRC during restore.
- An incomplete upload (tab closed mid-file) is deleted automatically after one day.
- In the list, the uploaded backup shows the date the backup itself was created and an "uploaded from computer" label.
- After the restore, log in with the username and password that existed on the site when the backup was made.

## Archive contents
| Path in archive | Contents |
|---|---|
| `database-001.sql`, `database-002.sql`… | Dump of all tables with the WordPress prefix, in parts of ~16 MB (archives before 0.5.1 use a single `database.sql`; they can still be restored) |
| `files/…` | Site files relative to the WordPress root |
| `manifest.json` | WP and plugin versions, site URL, table prefix, file count |

## How it works
Each backup is a separate folder with a random name in the storage: `backup.zip`, `log.txt` (log) and `job.json` (internal state). The storage is an `esb-backups-<site code>` folder next to the site's public folder (for example, next to `public_html`), or `wp-content/uploads/esb-backups/` if writing there is not allowed.

Phases: preparation → database dump → adding the database to the archive → file scan → archiving files → finalisation.

- Tables are read in batches of 500 rows, by primary key if it is an integer.
- No operation takes longer than a step, however large the database or a file: the dump is written and restored in parts of ~16 MB, and files of 64 MB and larger are copied into and out of the archive in parts, continuing in the next step (the CRCs of the parts are combined). Indivisible phases — file scan, archive check, finalisation — start only at the beginning of a step. Tested on hosting with 0.1 CPU core and a hard 30 s request limit.
- The ZIP is written by our own code that only appends to the end of the file: ZipArchive rewrites the whole archive on every open, which is too slow for step-by-step backups. If a step is interrupted, the archive is truncated back to the state after the last saved step.
- Archives over 4 GB and with more than 65,535 files are written in ZIP64 format.
- Images, video, archives and files over 64 MB are stored without compression.
- A file that cannot be read (for example, locked) is skipped and noted in the log.
- Not included in the backup: the backup folder, `wp-content/cache`, `wp-content/upgrade`, backup folders of some other plugins, symbolic links.
- Only the WordPress folder (`ABSPATH`) is backed up. If `wp-content` has been moved outside it (for example, Bedrock), themes, plugins and media are not included — the plugin page warns about this.

## Updates
The plugin updates from this repository's GitHub Releases, like a regular plugin from wordpress.org: WordPress shows the new version on the Plugins page and installs it automatically.

- Checked hourly via WP-Cron: a new version is installed automatically within about an hour of release (on low-traffic sites — after the next visit). The "Check again" button on the Updates page checks immediately.
- Auto-update is enabled once after installation; you can turn it off on the Plugins page, and the plugin won't turn it back on. Then updates are done with the "Update" button.
- While a backup or restore is in progress, auto-update is postponed.
- Update packages are accepted only from this repository's GitHub addresses.

### Releasing a new version
1. Bump the version in `easy-site-backup.php` — both in the `Version:` header and the `ESB_VERSION` constant.
2. Add a `### <version>` section under "Зміни" (Changes) in `README.md` — it becomes the release text (WordPress shows it as the changelog).
3. Commit and push.
4. Create and push a tag: `git tag v0.4.0 && git push origin v0.4.0`.
5. The `Release` GitHub Action checks that the tag matches the version, builds `easy-site-backup.zip` and publishes the release. If a release with this tag was created manually, the archive is attached to it.

## Security
- All actions are available only to users with the `manage_options` capability (on multisite — only to super admins, since a backup contains the tables of the whole network) and are protected by nonces.
- If `DISALLOW_FILE_MODS` is set in `wp-config.php`, files from an archive uploaded from a computer are not restored — otherwise this would be a way to put arbitrary PHP code on the site. The database from such an archive can still be restored.
- Restore executes only statements in exactly the form the plugin writes them (`DROP TABLE IF EXISTS`, `CREATE TABLE … (`, `INSERT INTO … VALUES`), and only for tables listed in the backup.
- **Storage outside the site's public folder.** The first time the plugin page is opened, backups are moved to an `esb-backups-<site code>` folder next to the public folder (one level above `public_html`) if the host allows writing there. There they have no web address, so no web server can serve them — Apache, Nginx, a hosting proxy or Cloudflare, however they are configured. The storage path is stored in the pointer file `wp-content/uploads/esb-backups/location.php` (not in the database — restoring the database won't affect it; the file shows nothing over the web). If writing above the public folder is not allowed, backups stay in `wp-content/uploads/esb-backups/`.
- The storage folder is protected by `.htaccess` and `index.php`; backup folder names are random.
- No file in the storage has a fixed address: archives and the database dump extracted during restore live in backup folders with random names, and the restore state is in a file with a random name.
- Downloads go through PHP, not a direct link. The server supports resuming (HTTP Range), and the **Download** (Завантажити) button in Chrome and Edge downloads the archive in 8 MB chunks straight into the chosen file — so large archives can be downloaded even on hosts that kill long requests (PHP-FPM with `request_terminate_timeout`). In other browsers it is a regular download that can be resumed.
- Uploaded archives are checked while extracting: an entry that unpacks to more than its declared size (a "ZIP bomb") is rejected immediately, before it can fill the disk.
- If the storage stayed in uploads: on Nginx (and on hosts where a proxy in front of Apache serves static files itself) `.htaccess` has no effect. The plugin checks by itself whether the web server serves files from the storage folder (once a day, with a test file), and if it does, shows a warning on its page with a ready-made configuration block:
  ```nginx
  location ^~ "/wp-content/uploads/esb-backups/" {
      deny all;
  }
  ```
- On Apache with `AllowOverride None`, `.htaccess` doesn't work either — the warning will appear there too.

## Tests
The `Tests` GitHub Action runs on every push to `main`, on pull requests and before every release — a release is not published if tests fail.

- `tests/unit/zip.php` — ZIP writing and reading without WordPress (PHP 7.4, 8.1, 8.3).
- `tests/integration/*.php` — on a real WordPress with MySQL (PHP 7.4, 8.3): plugin page, disk space check, schedule, full backup and restore cycle.

Locally (on a test site only — the tests create and restore backups):
```
php tests/unit/zip.php
php tests/integration/backup-restore.php /path/to/wordpress
```
Integration tests need the plugin to be active and the site to be reachable (restore runs via HTTP requests to `admin-ajax.php`).

## Requirements
- WordPress 5.8+, PHP 7.4+
- PHP `zlib` extension
- On 32-bit PHP the archive cannot exceed 2 GB.
- Free disk space is checked before a backup (with headroom for the size of all files) and before a restore (database dump + the largest file). Space for MySQL temporary tables during restore is not checked — MySQL data may live on another disk.

## Changelog

### 1.1.3
- On multisite the plugin page is available only to super admins: a sub-site administrator can no longer download or restore the database of the whole network.
- Files from an archive uploaded from a computer are not restored when `DISALLOW_FILE_MODS` is set.
- Stricter dump check during restore: a forged archive can no longer run `INSERT … SELECT` to copy data from other tables or databases.
- A backup step interrupted at the very last moment could mark the backup as finished with a corrupted archive. The state is now saved before temporary files are removed, and archive writing stops with an error if an intermediate file has disappeared.
- Tables without an integer primary key are dumped in unique-key order, so row batches can no longer overlap or skip rows.
- Restoring the database no longer rolls the schedule settings back to the moment of the backup: the schedule stays as it was before the restore.

### 1.1.2
- Extraction stops as soon as an entry's data exceeds the size declared in the archive: a forged archive (a "ZIP bomb") can no longer fill the disk, either when uploading from a computer or during restore.
- Files that are never restored (`wp-config.php`, the plugin itself, the storage, cache drop-ins) are matched case-insensitively — on Windows and macOS a `WP-CONFIG.php` entry in an archive can no longer overwrite `wp-config.php`.
- Incomplete uploads (tab closed mid-upload) are deleted automatically after one day instead of staying on disk forever.
- A warning on the plugin page if `wp-content` lives outside the WordPress folder and is therefore not included in backups.

### 1.1.1
- Restore no longer overwrites the cache drop-ins `wp-content/object-cache.php` and `advanced-cache.php`: they are tied to the server (Redis, Memcached), and a backup restored to a server without the same cache failed to open with "Error establishing a Redis connection".

### 1.1.0
- Backups are stored outside the site's public folder (next to `public_html`) if the host allows writing there: then no web server or proxy will serve them by URL, regardless of `.htaccess` and Nginx settings. Existing backups are moved automatically the first time the plugin page is opened; if the move fails, everything stays where it was, with a warning as before.

### 1.0.0
First stable release: the full "backup → download → restore on a new server" cycle has been tested on Apache + mod_php and Nginx + PHP-FPM hosting with budget-hosting limits (128 MB memory, 30 s per request, 0.1 CPU core) on a site with a ~600 MB database and a 1 GB file, with the process killed at random moments.

- Manual and scheduled database and file backups, with rotation and error emails.
- Restore from a backup on the server or from an archive uploaded from a computer; atomic database swap, CRC integrity check.
- Step-by-step operation that never hits hosting limits: the dump and large files in parts, archive download and upload in chunks, resuming after an interrupted step.
- Protected storage with a self-check for exposure on Nginx; updates from GitHub.

### 0.5.3
- Resuming after an interrupted step can no longer duplicate rows: a statement that executed before the interruption is recognised by row count (previously tables without a primary key got duplicates), and the next step waits until MySQL finishes the interrupted query.
- A large file whose restore was interrupted right after the temporary file was renamed can no longer be corrupted: if the temporary file is gone or shorter than the saved position, the file is restored from the start.
- A new test "kills" the PHP process at random moments during backup and restore (including right after the table swap) and checks that the data matches byte for byte.

### 0.5.2
- Downloading large archives on hosts that kill long requests: the **Download** button in Chrome and Edge downloads the archive in 8 MB chunks into the chosen file; the server supports resuming (HTTP Range) for other browsers, download managers and `curl -C -`.

### 0.5.1
- Large sites on slow hosting: the database dump is written to the archive and restored in parts of ~16 MB, and files of 64 MB and larger are copied in parts between steps, so no request hits the host's time limit, however large the database or a file. Archives from previous versions can still be restored.
- Indivisible phases start only at the beginning of a step so they get the whole step.
- On Windows servers: retries when saving state if the file is briefly locked by antivirus; a rejected archive no longer leaves files on disk.

### 0.5.0
- Restore from a file: a backup archive can be uploaded from a computer (in chunks, with no single-request size limit) and the site restored from it — including on a freshly installed WordPress after losing the server.

### 0.4.9
- The hourly update check always asks GitHub afresh instead of using a cached response: previously a response cached right before a release could hide the new version for up to 50 minutes.

### 0.4.8
- Nginx configuration block in the warning: the path is quoted (a space in the path no longer breaks the configuration) and URL-decoded; if uploads are served from another address (CDN), a hint shows where to add the block.
- Code block lines no longer shift: removed the indent and smaller font WordPress gives the code tag.

### 0.4.7
- The Nginx configuration block in the exposed-storage warning is shown as a plain grey box, without separate stripes under each line.

### 0.4.6
- Security on Nginx: the database dump extracted during restore no longer sits in the storage root under a fixed name (it could be downloaded from a known address), and the restore state is saved under a random name.
- Self-check: if the web server serves files from the backup folder directly, the plugin page shows a warning with a ready-made Nginx configuration block.

### 0.4.5
- Automatic updates are back: a new version installs itself within about an hour of release. It can be turned off on the Plugins page.
- A "Settings" link in the plugin's row on the Plugins page leads to the Easy Site Backup page.

### 0.4.4
- Plugin auto-update disabled: a new version appears on the Plugins page within about an hour and is installed with the "Update" button at your discretion. Auto-update enabled by previous versions is turned off; you can turn it back on on the Plugins page if you wish.

### 0.4.3
- The backup list is now above the schedule; after saving the schedule, the page opens right at it, together with the "Schedule saved" message.

### 0.4.2
- Plugin updates are checked hourly and installed automatically within about an hour of release (previously up to 12 hours).

### 0.4.1
- New look for the plugin page: a coloured header matching the admin colour scheme and sections in separate cards.

### 0.4.0
- Restoring a site from a backup: atomic database swap via temporary tables, file integrity check by CRC.
- Scheduled backups (daily or weekly), keeping the last N backups, error emails.
- Dates on the plugin page are shown in browser time; the log uses site time with a time-zone label.
- Free disk space check before backup and restore.
