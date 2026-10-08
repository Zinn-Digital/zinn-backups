<?php
/**
 * The Zinn® Backups screen in wp-admin.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

use ZinnDigital\Backups\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * One top-level screen with tabs. Its script and stylesheet load on this screen only.
 */
final class Admin {

	/**
	 * Hook suffix of our screen.
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ZINN_BACKUPS_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * The name shown in menus (Pro's white label can change it).
	 *
	 * @return string
	 */
	public static function name(): string {
		/**
		 * Filters the plugin's name as shown in wp-admin. Zinn® Backups Pro's white label sets it.
		 *
		 * @param string $name The name.
		 */
		return (string) apply_filters( 'zinn_backups_display_name', __( 'Zinn® Backups', 'zinn-backups' ) );
	}

	/**
	 * Add the menu.
	 *
	 * @return void
	 */
	public static function menu(): void {
		self::$hook = (string) add_menu_page( self::name(), self::name(), Plugin::CAP, Plugin::PAGE, array( self::class, 'render' ), 'dashicons-backup', 80 );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . Plugin::PAGE ) ) . '">' . esc_html__( 'Backups', 'zinn-backups' ) . '</a>' );
		// The paid add-on (owner D58), named once on the Plugins screen and only while it is not
		// installed: nothing in this plugin is locked, so this is a link, never a nag.
		if ( ! defined( 'ZINN_BACKUPS_PRO_VERSION' ) ) {
			$links[] = '<a href="' . esc_url( 'https://zinndigital.com/wordpress-plugins/zinn-backups-pro' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get Pro: schedules, several storages, encryption', 'zinn-backups' ) . '</a>';
		}
		return $links;
	}

	/**
	 * Script and styles, on our screen only.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public static function assets( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		$base = plugin_dir_url( ZINN_BACKUPS_FILE ) . 'assets/';
		wp_enqueue_style( 'zinn-backups-admin', $base . 'admin.css', array(), ZINN_BACKUPS_VERSION );
		wp_enqueue_script( 'zinn-backups-admin', $base . 'admin.js', array(), ZINN_BACKUPS_VERSION, true );
		wp_localize_script(
			'zinn-backups-admin',
			'zinnBackups',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( Plugin::NONCE ),
				'chunk'     => self::chunk_bytes(),
				'timezone'  => wp_timezone_string(),
				'locale'    => str_replace( '_', '-', get_user_locale() ),
				'providers' => Destinations::s3_providers(),
				/**
				 * Filters what the screen's script is told about optional features. An add-on sets
				 * `deploy` (with its own translated `deployLabel` and `deployHelp`) to show a
				 * "Copy to another site" button beside each backup.
				 *
				 * @param array<string,mixed> $features Feature flags and their labels.
				 */
				'features'  => (array) apply_filters( 'zinn_backups_admin_features', array( 'deploy' => false ) ),
				'i18n'      => array(
					'working'       => __( 'Working…', 'zinn-backups' ),
					'done'          => __( 'Finished', 'zinn-backups' ),
					'failed'        => __( 'Failed', 'zinn-backups' ),
					'cancelled'     => __( 'Cancelled', 'zinn-backups' ),
					'cancel'        => __( 'Cancel', 'zinn-backups' ),
					'restore'       => __( 'Restore', 'zinn-backups' ),
					'delete'        => __( 'Delete', 'zinn-backups' ),
					'confirmDelete' => __( 'Delete this backup from your storage? This cannot be undone.', 'zinn-backups' ),
					'noBackups'     => __( 'No backups yet. Press “Back up now” to make the first one.', 'zinn-backups' ),
					'loading'       => __( 'Reading your storage…', 'zinn-backups' ),
					'full'          => _x( 'Full backup', 'a complete backup, as opposed to an incremental one', 'zinn-backups' ),
					'incremental'   => _x( 'Incremental backup', 'a backup holding only what changed since an earlier one', 'zinn-backups' ),
					'encrypted'     => __( 'Encrypted', 'zinn-backups' ),
					'otherSite'     => __( 'From another site', 'zinn-backups' ),
					'database'      => __( 'Database', 'zinn-backups' ),
					'files'         => __( 'Files', 'zinn-backups' ),
					/* translators: 1: file name, 2: percentage uploaded. */
					'uploading'     => __( 'Uploading %1$s: %2$s%%', 'zinn-backups' ),
					'pasteCode'     => __( 'Sign in to Dropbox in the window that opened, then paste the code Dropbox shows you:', 'zinn-backups' ),
					'saved'         => __( 'Saved.', 'zinn-backups' ),
					'removeDest'    => __( 'Remove this destination from the plugin? The backups stored in it are not deleted.', 'zinn-backups' ),
					'reload'        => __( 'Reload the page to see the restored site.', 'zinn-backups' ),
				),
			)
		);
	}

	/**
	 * Upload chunk size: under PHP's upload and post limits.
	 *
	 * @return int
	 */
	private static function chunk_bytes(): int {
		$limit = min( wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) ), wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) );
		$limit = $limit > 0 ? (int) ( $limit * 0.8 ) : 1048576;
		return max( 262144, min( 8388608, $limit ) );
	}

	/**
	 * A destination as the screen may see it (no secrets).
	 *
	 * @param array<string,mixed> $record Saved destination.
	 * @return array<string,mixed>
	 */
	public static function public_destination( array $record ): array {
		$secrets = Secrets::open( (string) ( $record['secret'] ?? '' ) );
		return array(
			'id'          => (string) $record['id'],
			'type'        => (string) $record['type'],
			'label'       => (string) $record['label'],
			'config'      => (array) $record['config'],
			'ready'       => Destinations::is_ready( $record ),
			'has_secret'  => '' !== ( $secrets['access_secret'] ?? $secrets['client_secret'] ?? '' ),
			'needs_login' => in_array( $record['type'], array( 'dropbox', 'gdrive' ), true ),
		);
	}

	/**
	 * Tabs: slug => label.
	 *
	 * @return array<string,string>
	 */
	private static function tabs(): array {
		$tabs = array(
			'backups'  => __( 'Backups', 'zinn-backups' ),
			'storage'  => __( 'Storage', 'zinn-backups' ),
			'import'   => __( 'Import & migrate', 'zinn-backups' ),
			'settings' => __( 'Settings', 'zinn-backups' ),
		);
		/**
		 * Filters the tabs of the Zinn® Backups screen.
		 *
		 * @param array<string,string> $tabs Slug => label.
		 */
		return (array) apply_filters( 'zinn_backups_admin_tabs', $tabs );
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return;
		}
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a tab to display.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'backups';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'backups';
		echo '<div class="wrap zb">';
		echo '<h1>' . esc_html( self::name() ) . '</h1>';
		self::notices();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Sections', 'zinn-backups' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%4$s>%3$s</a>',
				esc_url( admin_url( 'admin.php?page=' . Plugin::PAGE . '&tab=' . $slug ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label ),
				$slug === $tab ? ' aria-current="page"' : ''
			);
		}
		echo '</nav>';
		self::job_panel();
		echo '<div class="zb-tab" data-tab="' . esc_attr( $tab ) . '">';
		switch ( $tab ) {
			case 'storage':
				self::tab_storage();
				break;
			case 'import':
				self::tab_import();
				break;
			case 'settings':
				self::tab_settings();
				break;
			case 'backups':
				self::tab_backups();
				break;
			default:
				/**
				 * Renders a tab added through `zinn_backups_admin_tabs`.
				 *
				 * @param string $tab Tab slug.
				 */
				do_action( 'zinn_backups_admin_tab', $tab );
		}
		echo '</div>';
		\Zinn_Backups_Promo::render_panel();
		echo '</div>';
	}

	/**
	 * Notices from a redirect.
	 *
	 * @return void
	 */
	private static function notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display-only notice code.
		$code = isset( $_GET['zb_notice'] ) ? sanitize_key( wp_unslash( $_GET['zb_notice'] ) ) : '';
		if ( 'oauth_ok' === $code ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Google Drive is connected.', 'zinn-backups' ) . '</p></div>';
		} elseif ( 'oauth_failed' === $code ) {
			$detail = (string) get_transient( 'zinn_backups_notice_' . get_current_user_id() );
			delete_transient( 'zinn_backups_notice_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Google Drive could not be connected.', 'zinn-backups' ) . ' ' . esc_html( $detail ) . '</p></div>';
		}
	}

	/**
	 * The running-job panel (filled by the script).
	 *
	 * @return void
	 */
	private static function job_panel(): void {
		$job = Runner::current();
		echo '<section id="zb-job" class="zb-card zb-job" aria-live="polite"' . ( null !== $job && $job->running() ? '' : ' hidden' ) . ' data-running="' . ( null !== $job && $job->running() ? '1' : '0' ) . '">';
		echo '<h2 class="zb-job__title"></h2>';
		echo '<progress class="zb-job__bar" max="100"></progress>';
		echo '<p class="zb-job__status"></p>';
		echo '<details><summary>' . esc_html__( 'Details', 'zinn-backups' ) . '</summary><ol class="zb-job__log"></ol></details>';
		echo '<p><button type="button" class="button zb-job__cancel">' . esc_html__( 'Cancel', 'zinn-backups' ) . '</button></p>';
		echo '</section>';
	}

	/**
	 * Backups tab.
	 *
	 * @return void
	 */
	private static function tab_backups(): void {
		$ready = Destinations::active();
		echo '<section class="zb-card">';
		echo '<h2>' . esc_html__( 'Back up now', 'zinn-backups' ) . '</h2>';
		if ( ! $ready ) {
			echo '<p>' . wp_kses(
				sprintf(
					/* translators: %s: link to the Storage tab. */
					__( 'Backups go to your own storage, not this server. <a href="%s">Connect your storage</a> first.', 'zinn-backups' ),
					esc_url( admin_url( 'admin.php?page=' . Plugin::PAGE . '&tab=storage' ) )
				),
				array( 'a' => array( 'href' => array() ) )
			) . '</p></section>';
			return;
		}
		echo '<form id="zb-backup-form" class="zb-inline">';
		echo '<label>' . esc_html__( 'What to back up', 'zinn-backups' ) . ' <select name="what">';
		echo '<option value="all">' . esc_html__( 'Files and database', 'zinn-backups' ) . '</option>';
		echo '<option value="database">' . esc_html__( 'Database only', 'zinn-backups' ) . '</option>';
		echo '<option value="files">' . esc_html__( 'Files only', 'zinn-backups' ) . '</option>';
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Note (optional)', 'zinn-backups' ) . ' <input type="text" name="note" maxlength="120" class="regular-text"></label> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Back up now', 'zinn-backups' ) . '</button>';
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Each part is uploaded to your storage and removed from this server before the next one is made, so the backup never takes up room on your hosting.', 'zinn-backups' ) . '</p>';
		echo '</section>';

		echo '<section class="zb-card"><h2>' . esc_html__( 'Your backups', 'zinn-backups' ) . ' <button type="button" class="button-link zb-refresh">' . esc_html__( 'Refresh', 'zinn-backups' ) . '</button></h2>';
		echo '<div id="zb-list" class="zb-list"></div></section>';

		echo '<dialog id="zb-restore" class="zb-dialog"><form method="dialog" class="zb-restore-form">';
		echo '<h2>' . esc_html__( 'Restore this backup', 'zinn-backups' ) . '</h2>';
		echo '<p class="zb-restore-what"></p>';
		echo '<p><label>' . esc_html__( 'Restore', 'zinn-backups' ) . ' <select name="what"><option value="all">' . esc_html__( 'Files and database', 'zinn-backups' ) . '</option><option value="database">' . esc_html__( 'Database only', 'zinn-backups' ) . '</option><option value="files">' . esc_html__( 'Files only', 'zinn-backups' ) . '</option></select></label></p>';
		echo '<p class="zb-passphrase" hidden><label>' . esc_html__( 'Passphrase', 'zinn-backups' ) . ' <input type="password" name="passphrase" autocomplete="off"></label></p>';
		echo '<p><label><input type="checkbox" name="confirm" value="yes" required> ' . esc_html__( 'I understand this replaces this site’s files and database with the backup’s. Files that are not in the backup are kept.', 'zinn-backups' ) . '</label></p>';
		echo '<p class="zb-actions"><button value="cancel" class="button">' . esc_html__( 'Cancel', 'zinn-backups' ) . '</button> <button value="go" class="button button-primary">' . esc_html__( 'Restore', 'zinn-backups' ) . '</button></p>';
		echo '</form></dialog>';

		$history = get_option( Runner::HISTORY, array() );
		if ( is_array( $history ) && $history ) {
			echo '<section class="zb-card"><h2>' . esc_html__( 'Recent activity', 'zinn-backups' ) . '</h2><ul class="zb-history">';
			foreach ( array_slice( $history, 0, 10 ) as $row ) {
				$when = (int) ( ! empty( $row['finished'] ) ? $row['finished'] : $row['started'] );
				printf(
					'<li class="zb-history__%1$s"><strong>%2$s</strong> · %3$s · %4$s%5$s</li>',
					esc_attr( (string) $row['status'] ),
					esc_html( 'backup' === $row['type'] ? __( 'Backup', 'zinn-backups' ) : __( 'Restore / import', 'zinn-backups' ) ),
					esc_html( self::status_label( (string) $row['status'] ) ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) ),
					'' !== (string) $row['error'] ? ' — ' . esc_html( (string) $row['error'] ) : ''
				);
			}
			echo '</ul></section>';
		}
	}

	/**
	 * A job status in words.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		return match ( $status ) {
			'done'      => __( 'Finished', 'zinn-backups' ),
			'failed'    => __( 'Failed', 'zinn-backups' ),
			'cancelled' => __( 'Cancelled', 'zinn-backups' ),
			default     => __( 'Running', 'zinn-backups' ),
		};
	}

	/**
	 * Storage tab.
	 *
	 * @return void
	 */
	private static function tab_storage(): void {
		$records = Settings::destinations();
		$limit   = Settings::destination_limit();
		echo '<section class="zb-card"><h2>' . esc_html( _n( 'Your storage', 'Your storage destinations', $limit, 'zinn-backups' ) ) . '</h2>';
		if ( ! $records ) {
			echo '<p>' . esc_html__( 'No storage yet. Choose where your backups go below.', 'zinn-backups' ) . '</p>';
		}
		echo '<ul class="zb-destinations">';
		foreach ( $records as $record ) {
			$public = self::public_destination( $record );
			printf(
				'<li class="zb-destination" data-destination="%1$s"><strong>%2$s</strong> <span class="zb-badge zb-badge--%3$s">%4$s</span> <span class="zb-dest-actions">%5$s<button type="button" class="button zb-test">%6$s</button> <button type="button" class="button zb-edit">%7$s</button> <button type="button" class="button-link zb-remove">%8$s</button></span> <span class="zb-dest-result" role="status"></span></li>',
				esc_attr( (string) wp_json_encode( $public ) ),
				esc_html( (string) $record['label'] ),
				$public['ready'] ? 'ok' : 'warn',
				esc_html( $public['ready'] ? __( 'Ready', 'zinn-backups' ) : __( 'Not connected', 'zinn-backups' ) ),
				$public['needs_login'] ? '<button type="button" class="button zb-connect">' . esc_html__( 'Connect', 'zinn-backups' ) . '</button> ' : '',
				esc_html__( 'Test', 'zinn-backups' ),
				esc_html__( 'Edit', 'zinn-backups' ),
				esc_html__( 'Remove', 'zinn-backups' )
			);
		}
		echo '</ul></section>';

		$can_add = count( $records ) < $limit;
		echo '<section class="zb-card" id="zb-dest-card"' . ( $can_add ? '' : ' hidden' ) . '><h2 class="zb-dest-title">' . esc_html__( 'Add storage', 'zinn-backups' ) . '</h2>';
		self::destination_form();
		echo '</section>';
		if ( ! $can_add && 1 === $limit ) {
			echo '<p class="description">' . esc_html__( 'Backups go to one storage destination. To use a different one, edit or remove the one above.', 'zinn-backups' ) . '</p>';
		}
	}

	/**
	 * The add/edit destination form.
	 *
	 * @return void
	 */
	private static function destination_form(): void {
		echo '<form id="zb-dest-form" class="zb-form" autocomplete="off"><input type="hidden" name="id" value="">';
		echo '<p><label>' . esc_html__( 'Kind of storage', 'zinn-backups' ) . '<br><select name="type">';
		foreach ( Destinations::types() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Name (for you)', 'zinn-backups' ) . '<br><input type="text" name="label" class="regular-text"></label></p>';

		echo '<fieldset data-type="s3"><p><label>' . esc_html__( 'Provider', 'zinn-backups' ) . '<br><select name="provider">';
		foreach ( Destinations::s3_providers() as $value => $provider ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $provider['label'] ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p data-provider="r2"><label>' . esc_html__( 'Cloudflare account id', 'zinn-backups' ) . '<br><input type="text" name="account" class="regular-text" dir="ltr"></label></p>';
		echo '<p data-provider="other"><label>' . esc_html__( 'Endpoint address', 'zinn-backups' ) . '<br><input type="url" name="endpoint" class="regular-text" dir="ltr" placeholder="https://"></label></p>';
		echo '<p><label>' . esc_html__( 'Region', 'zinn-backups' ) . '<br><input type="text" name="region" class="regular-text" dir="ltr"></label></p>';
		echo '<p><label>' . esc_html__( 'Bucket', 'zinn-backups' ) . '<br><input type="text" name="bucket" class="regular-text" dir="ltr"></label></p>';
		echo '<p><label>' . esc_html__( 'Access key id', 'zinn-backups' ) . '<br><input type="text" name="access_key" class="regular-text" dir="ltr"></label></p>';
		echo '<p><label>' . esc_html__( 'Secret access key', 'zinn-backups' ) . '<br><input type="password" name="access_secret" class="regular-text" dir="ltr" placeholder="' . esc_attr__( 'Leave empty to keep the saved key', 'zinn-backups' ) . '"></label></p>';
		echo '<p class="description">' . esc_html__( 'For Cloudflare R2: in the Cloudflare dashboard open R2, create a bucket, then “Manage R2 API tokens” → create a token with Object Read & Write for that bucket, and copy the access key id and secret here.', 'zinn-backups' ) . '</p>';
		echo '</fieldset>';

		echo '<fieldset data-type="dropbox" hidden><p><label>' . esc_html__( 'Dropbox app key', 'zinn-backups' ) . '<br><input type="text" name="app_key" class="regular-text" dir="ltr"></label></p>';
		echo '<p class="description">' . esc_html__( 'Create an app at dropbox.com/developers/apps (Scoped access, App folder) with the files.content.read, files.content.write and files.metadata.read permissions, and copy its App key here. Save, then press Connect.', 'zinn-backups' ) . '</p></fieldset>';

		echo '<fieldset data-type="gdrive" hidden><p><label>' . esc_html__( 'OAuth client id', 'zinn-backups' ) . '<br><input type="text" name="client_id" class="regular-text" dir="ltr"></label></p>';
		echo '<p><label>' . esc_html__( 'OAuth client secret', 'zinn-backups' ) . '<br><input type="password" name="client_secret" class="regular-text" dir="ltr" placeholder="' . esc_attr__( 'Leave empty to keep the saved secret', 'zinn-backups' ) . '"></label></p>';
		echo '<p class="description">' . esc_html__( 'In Google Cloud Console, enable the Google Drive API, then create an OAuth client of type “Web application” with this address as an authorised redirect URI:', 'zinn-backups' ) . ' <code dir="ltr">' . esc_html( Plugin::oauth_redirect() ) . '</code>. ' . esc_html__( 'Save, then press Connect. The plugin only sees the files it creates.', 'zinn-backups' ) . '</p></fieldset>';

		echo '<p><label>' . esc_html__( 'Folder inside the storage (optional)', 'zinn-backups' ) . '<br><input type="text" name="folder" class="regular-text" dir="ltr"></label></p>';
		echo '<p class="zb-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save storage', 'zinn-backups' ) . '</button> <span class="zb-form-result" role="status"></span></p>';
		echo '</form>';
	}

	/**
	 * Import tab.
	 *
	 * @return void
	 */
	private static function tab_import(): void {
		echo '<section class="zb-card"><h2>' . esc_html__( 'Import a backup from another plugin or host', 'zinn-backups' ) . '</h2>';
		echo '<p>' . esc_html__( 'Bring a site in from a backup made by UpdraftPlus, All-in-One WP Migration (.wpress), Duplicator (zip), BackWPup, WPvivid, Jetpack VaultPress Backup, BlogVault, Zinn® Migrate, or any zip/tar archive of a WordPress site with its .sql dump. The content goes into wp-content and the database replaces this site’s; addresses are changed to this site’s automatically.', 'zinn-backups' ) . '</p>';
		echo '<form id="zb-import-form" class="zb-form">';
		echo '<fieldset><legend>' . esc_html__( 'Upload from your computer', 'zinn-backups' ) . '</legend><input type="file" name="files" multiple accept=".zip,.wpress,.gz,.tgz,.tar,.sql"><p class="description">' . esc_html__( 'Choose every file of the backup at once (UpdraftPlus makes several). Large files are sent in small pieces, so your host’s upload limit does not apply.', 'zinn-backups' ) . '</p></fieldset>';
		echo '<fieldset><legend>' . esc_html__( 'Or from links (Google Drive, Dropbox or any download address)', 'zinn-backups' ) . '</legend><textarea name="urls" rows="3" class="large-text" dir="ltr" placeholder="https://drive.google.com/file/d/…"></textarea><p class="description">' . esc_html__( 'One link per line. For Google Drive and Dropbox, share the file with “anyone with the link” and paste that link; turn sharing off again afterwards.', 'zinn-backups' ) . '</p></fieldset>';
		$inbox = Plugin::inbox_files();
		echo '<fieldset><legend>' . esc_html__( 'Or a file already on this server', 'zinn-backups' ) . '</legend>';
		if ( $inbox ) {
			foreach ( $inbox as $file ) {
				echo '<label class="zb-block"><input type="checkbox" name="server[]" value="' . esc_attr( $file['name'] ) . '"> <span dir="ltr">' . esc_html( $file['name'] ) . '</span> (' . esc_html( size_format( $file['size'] ) ) . ')</label>';
			}
		} else {
			echo '<p class="description">' . sprintf(
			/* translators: %s: folder path. */
				esc_html__( 'Upload archives by FTP or your host’s file manager into %s and reload this page.', 'zinn-backups' ),
				'<code dir="ltr">wp-content/uploads/' . esc_html( Plugin::INBOX ) . '/</code>'
			) . '</p>';
		}
		echo '</fieldset>';
		echo '<fieldset><legend>' . esc_html__( 'Or a deploy code from another site', 'zinn-backups' ) . '</legend><textarea name="deploy" rows="2" class="large-text" dir="ltr" placeholder="zbdeploy1.…"></textarea><p class="description">' . esc_html__( 'If another site gave you a Zinn® Backups deploy code, paste it here to copy that backup onto this site without sharing its storage keys. A code works for a few hours; treat it like a password.', 'zinn-backups' ) . '</p><p><label>' . esc_html__( 'Passphrase (only if that backup is encrypted)', 'zinn-backups' ) . ' <input type="password" name="passphrase" autocomplete="off"></label></p></fieldset>';
		echo '<p><label>' . esc_html__( 'Import', 'zinn-backups' ) . ' <select name="what"><option value="all">' . esc_html__( 'Files and database', 'zinn-backups' ) . '</option><option value="database">' . esc_html__( 'Database only', 'zinn-backups' ) . '</option><option value="files">' . esc_html__( 'Files only', 'zinn-backups' ) . '</option></select></label></p>';
		echo '<p><label><input type="checkbox" name="confirm" value="yes" required> ' . esc_html__( 'I understand the import replaces this site’s content and database. After it, sign in with an account from the imported site.', 'zinn-backups' ) . '</label></p>';
		echo '<p class="zb-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Import', 'zinn-backups' ) . '</button> <span class="zb-form-result" role="status"></span></p>';
		echo '</form></section>';
		echo '<section class="zb-card"><h2>' . esc_html__( 'Moving to Zinn® hosting?', 'zinn-backups' ) . '</h2><p>' . esc_html__( 'Zinn Digital® can move your site for you from your old host. If your old host gives you no FTP, SSH or control-panel access, install Zinn® Migrate on the old site; it makes one archive you can import here or hand to Zinn®.', 'zinn-backups' ) . '</p></section>';
	}

	/**
	 * Settings tab.
	 *
	 * @return void
	 */
	private static function tab_settings(): void {
		$all = Settings::all();
		echo '<section class="zb-card"><h2>' . esc_html__( 'What goes into a backup', 'zinn-backups' ) . '</h2><form id="zb-settings-form" class="zb-form">';
		echo '<p><label><input type="checkbox" name="include_core" value="1"' . checked( $all['include_core'], true, false ) . '> ' . esc_html__( 'Include WordPress’s own files (wp-admin, wp-includes). Usually not needed: WordPress can always be reinstalled.', 'zinn-backups' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Also leave out these folders or files (one per line, relative to the WordPress folder)', 'zinn-backups' ) . '<br><textarea name="leave_out" rows="5" class="large-text" dir="ltr">' . esc_textarea( $all['leave_out'] ) . '</textarea></label></p>';
		echo '<p class="description">' . esc_html__( 'Always left out: caches, update scratch space, and other backup plugins’ own archive folders:', 'zinn-backups' ) . ' <code dir="ltr">' . esc_html( implode( ', ', Settings::DEFAULT_EXCLUDES ) ) . '</code></p>';
		echo '<p><label>' . esc_html__( 'Size of each part (MB)', 'zinn-backups' ) . ' <input type="number" name="part_mb" min="10" max="2000" value="' . esc_attr( (string) $all['part_mb'] ) . '"></label></p>';
		/**
		 * Prints extra settings fields (Zinn® Backups Pro adds schedules, retention and encryption).
		 */
		do_action( 'zinn_backups_settings_fields' );
		echo '<p class="zb-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save settings', 'zinn-backups' ) . '</button> <span class="zb-form-result" role="status"></span></p>';
		echo '</form></section>';
	}
}
