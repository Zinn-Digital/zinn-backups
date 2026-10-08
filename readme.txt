=== Zinn® Backups ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-backups
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: backup, restore, migration, dropbox, google drive
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up files and database to your own Dropbox, Google Drive, R2 or S3, restore safely, and import backups from other plugins.

== Description ==

Zinn® Backups copies your WordPress site — every file in wp-content and the whole database — to storage you own, and puts it back when you need it. Nothing is kept on your web server: each part of a backup is uploaded and removed before the next one is made, so a backup never eats the disk space your hosting gives you, and it survives if the server is lost.

= Your own storage =

Choose one of:

* **Cloudflare R2**, **Amazon S3**, **Wasabi**, **Backblaze B2**, **DigitalOcean Spaces** or any other S3-compatible storage
* **Dropbox** (sign in with your own Dropbox app; the plugin only sees its own app folder)
* **Google Drive** (sign in with your own Google OAuth client; the plugin only sees the files it creates)

Credentials are encrypted before they are saved in your database.

= Backups that work on ordinary hosting =

* Works without shell access, `mysqldump` or `exec` — the database is read through WordPress itself.
* Long backups run in short steps that pick up where they stopped, so a host's 30-second limit does not end them.
* Large uploads go in 8 MB chunks, resumed after a network hiccup.
* Every file is checked against its SHA-256 checksum before it is restored.

= Restore safely =

The backup's database is loaded into temporary tables first and swapped in with one atomic step at the very end. If anything goes wrong on the way, your site's database is untouched. Your storage settings are kept across the restore, so you are never locked out of your own backups.

= Import from other backup and migration plugins =

Move a site in from a backup made by:

* UpdraftPlus (all the files of one backup set: `-db.gz`, `-plugins.zip`, `-themes.zip`, `-uploads.zip`, `-others.zip`)
* All-in-One WP Migration (`.wpress`, unencrypted)
* Duplicator (zip archives)
* BackWPup, WPvivid, Jetpack VaultPress Backup and BlogVault downloads
* Zinn® Migrate
* any zip, tar or tar.gz archive of a WordPress site with its `.sql` dump, or a `.sql`/`.sql.gz` dump on its own

Upload the files (any size — they are sent in small pieces, so your host's upload limit does not apply), paste a Google Drive or Dropbox share link or any download address, or put the files in `wp-content/uploads/zinn-backups-import/` by FTP. The site's address and table prefix are changed to this site's automatically, and serialized data stays valid.

= Pro features =

Everything above is free and stays free, restoring included. [Zinn® Backups Pro](https://zinndigital.com/wordpress-plugins/zinn-backups-pro) is an add-on that installs on top of this plugin and adds:

* Several storage destinations at once, up to ten, so a copy survives if one is lost.
* Scheduled backups: hourly, twice a day, daily, weekly or monthly, from the hour you choose.
* Incremental backups after a full one, with a fresh full backup every few runs.
* Retention rules that never remove a backup a kept incremental backup builds on.
* Encryption with your own passphrase before anything leaves the server.
* A one-time code to copy a backup to another site, e-mail notices and a white label.

Pro costs $49 a year for one site, $99 for five sites and $199 for unlimited sites, and every plan starts with a 14-day free trial, no card needed.

= WP-CLI =

`wp zinn-backups backup`, `list`, `restore`, `import`, `destination`, `status` and `cancel`. Secrets are read from environment variables, never from the command line.

== Installation ==

1. Install and activate the plugin.
2. Open **Zinn® Backups → Storage** and connect your storage.
3. Open **Backups** and press **Back up now**.

== External services ==

The plugin sends data only to the storage you connect, only when you back up, restore, list or delete backups, or test the connection:

* **Amazon S3 and S3-compatible storage (Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean Spaces and others)** — the backup files are uploaded to and downloaded from the bucket you name. Cloudflare: terms https://www.cloudflare.com/terms/ and privacy https://www.cloudflare.com/privacypolicy/. Amazon: terms https://aws.amazon.com/service-terms/ and privacy https://aws.amazon.com/privacy/. For other providers, see the provider's own terms.
* **Dropbox** — signing in sends your app key and the code Dropbox shows you to `api.dropboxapi.com`; backups are uploaded to and downloaded from your app folder through `content.dropboxapi.com`. Terms https://www.dropbox.com/terms, privacy https://www.dropbox.com/privacy.
* **Google Drive** — signing in sends your OAuth client id and secret and the sign-in code to `oauth2.googleapis.com`; backups are uploaded to and downloaded from `www.googleapis.com`. Terms https://policies.google.com/terms, privacy https://policies.google.com/privacy.
* **Download links you paste on the Import tab** — the plugin downloads the file from that address (for Google Drive links, from `drive.usercontent.google.com`; for Dropbox links, from `dropbox.com`).

* **Updates from Zinn Digital® (only on sites Zinn Digital® hosts).** The hosting platform writes an update address and a site key into wp-config.php (`ZINN_UPDATE_URL`, `ZINN_UPDATE_SECRET`). Where they exist, WordPress's normal update check asks that address, which ends in `/v1/wp/plugin-update/<site id>`, whether a newer Zinn® Backups is available, sending the plugin's slug and version and the site's address, signed with the site's key. On any other site the constants do not exist and nothing is asked. Terms https://zinndigital.com/legal/terms, privacy https://zinndigital.com/legal/privacy.
* **Hosting-customer Pro discount (only on sites Zinn Digital® hosts).** The plugin's screen shows administrators a card offering hosting customers a personal discount code for Zinn® Backups Pro. Nothing is sent when the page loads. Only when an administrator presses the card's button does the site send one request to Zinn Digital® at `https://api.zinndigital.com/v1/wp/pro-discount/<site id>`, containing the plugin's slug, the word `issue` and the administrator's WordPress language, signed with the site's own key. The site's address and key come from constants the platform writes into wp-config.php on the sites it hosts; the card reuses only the host and site id of the address, which ends in `/v1/wp/plugin-update/<site id>`, and never sends anything to that address itself. On any other site they do not exist and the card is never shown. The answer is the customer's code and a Freemius checkout link, to which the browser is then sent. The card is not shown once Zinn® Backups Pro is active. Terms https://zinndigital.com/legal/terms, privacy https://zinndigital.com/legal/privacy.

Apart from that, no data about your site or its visitors is sent to Zinn Digital®.

== Frequently Asked Questions ==

= Does the backup stay on my server? =

No. Each part is uploaded to your storage and deleted from the server before the next part is made. Only a small file list and the job's progress are kept between steps, in a private folder that web visitors cannot open.

= What is not backed up? =

WordPress's own files (wp-admin and wp-includes) unless you tick the option, caches, and other backup plugins' archive folders. Restoring never replaces wp-config.php.

= Can I restore a backup onto a different site or domain? =

Yes. Connect the same storage on the other site, find the backup under **Backups** (it is marked "From another site"), and restore it. Addresses are rewritten to the new site's.

= Does it support multisite? =

Not yet. The plugin refuses to run on a multisite network rather than make a backup it cannot restore.

= What happens to files that are not in the backup? =

They are kept. A restore writes the backup's files over the site's; it never deletes anything.

== Screenshots ==

1. The Backups tab with a backup running and the list of backups in your storage.
2. Connecting Cloudflare R2.
3. Importing an UpdraftPlus backup.

== Changelog ==

= 1.0.2 =
* Zinn® Backups Pro is out: a link to it on the Plugins screen, and the hosting-customer discount card on sites Zinn Digital® hosts.
* Security: the request a running backup sends to its own site to take the next step now carries a single-use token that expires after five minutes, instead of one key for the whole job.

= 1.0.1 =
* Not yet published: the dashboard notice follows the hosted-only distribution until the WordPress.org release.

= 1.0.0 =
* First release: backups of files and database to Dropbox, Google Drive, Cloudflare R2, Amazon S3 and S3-compatible storage; restore through temporary tables and an atomic swap; import from UpdraftPlus, All-in-One WP Migration, Duplicator, BackWPup, WPvivid, Jetpack VaultPress Backup, BlogVault and Zinn® Migrate archives; WP-CLI commands.

== Upgrade Notice ==

= 1.0.0 =
First release.
