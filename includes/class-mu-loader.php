<?php
/**
 * The must-use loader: keeps backups, restores and WP-CLI running when the plugin is switched off.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * Offers `wp-content/mu-plugins/zinn-backups-loader.php` for the site owner to copy in, and
 * removes it when it is ours and no longer wanted. The plugin never writes it (WordPress.org
 * review class R003: no executable code written outside the plugin's own folder).
 *
 * Why it exists: a restore replaces the options table with the backup's, and a site restored from
 * a backup made before Zinn® Backups was active no longer lists the plugin as active — halfway
 * through its own restore. A plugin switched off by another plugin, by a person, or paused after a
 * conflict stops its backups the same way. The loader (`mu-loader/zinn-backups-loader.php`) loads
 * the plugin at `plugins_loaded` priority 0 only when it did not load itself, so the two copies
 * can never both run (the plugin's main file also returns at once when ZINN_BACKUPS_VERSION is
 * already defined).
 *
 * ⛔ Installed and removed safely:
 *  - copied in by the site owner (settings page or `wp zinn-backups loader install`), never written;
 *  - never overwrites or deletes a file of that name that is not ours (the marker line);
 *  - on a multisite network only when the plugin is network-active (a must-use plugin runs on
 *    every site of the network);
 *  - removed by the setting, by `wp zinn-backups loader remove`, and on uninstall.
 */
final class Mu_Loader {

	public const FILE = 'zinn-backups-loader.php';

	/** The line that identifies a loader this plugin wrote. */
	public const MARKER = 'zinn-backups-mu-loader';

	/** Option set by the loader when a load it started ended in a fatal error. */
	public const TRIPPED = 'zinn_backups_mu_tripped';

	/** Option recording which plugin version last synced the loader. */
	public const REV = 'zinn_backups_mu_rev';

	/**
	 * Option: the one-time offer was answered ('taken' or 'dismissed').
	 */
	public const OFFERED = 'zinn_backups_mu_offered';

	/** The plugin basename baked into the template. */
	private const TEMPLATE_TARGET = "'zinn-backups/zinn-backups.php'";

	/**
	 * Hooks: keep the loader current after an update, and clear the fuse once the plugin loads normally.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_sync' ) );
	}

	/**
	 * Whether this request's copy of the plugin was loaded by the must-use loader.
	 *
	 * @return bool
	 */
	public static function loaded_by_mu(): bool {
		return defined( 'ZINN_BACKUPS_LOADED_BY_MU' ) && ZINN_BACKUPS_LOADED_BY_MU;
	}

	/**
	 * Where the loader lives.
	 *
	 * @return string
	 */
	public static function path(): string {
		return untrailingslashit( self::mu_dir() ) . '/' . self::FILE;
	}

	/**
	 * The must-use plugins folder.
	 *
	 * @return string
	 */
	private static function mu_dir(): string {
		return defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	}

	/**
	 * The loader's source for a plugin installed at `$basename`.
	 *
	 * @param string $template The template's source.
	 * @param string $basename Plugin basename, e.g. `zinn-backups/zinn-backups.php`.
	 * @return string
	 * @throws \InvalidArgumentException When the basename is not a plain `folder/file.php`.
	 */
	public static function render( string $template, string $basename ): string {
		if ( 1 !== preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\.php$#', $basename ) || str_contains( $basename, '..' ) ) {
			throw new \InvalidArgumentException( 'Unexpected plugin path.' );
		}
		if ( 1 !== substr_count( $template, self::TEMPLATE_TARGET ) || ! str_contains( $template, self::MARKER ) ) {
			throw new \InvalidArgumentException( 'The loader template is damaged.' );
		}
		return str_replace( self::TEMPLATE_TARGET, var_export( $basename, true ), $template ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- quoting a validated path as a PHP string literal.
	}

	/**
	 * The loader for this copy of the plugin.
	 *
	 * @return string
	 */
	public static function source(): string {
		$template = (string) file_get_contents( dirname( ZINN_BACKUPS_FILE ) . '/mu-loader/' . self::FILE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading our own bundled file.
		return self::render( $template, plugin_basename( ZINN_BACKUPS_FILE ) );
	}

	/**
	 * Whether a file's contents are a loader this plugin wrote.
	 *
	 * @param string $contents File contents.
	 * @return bool
	 */
	public static function is_ours( string $contents ): bool {
		return str_contains( $contents, "\n * Marker: " . self::MARKER . "\n" );
	}

	/**
	 * Whether a loader we wrote is in place.
	 *
	 * @return bool
	 */
	public static function installed(): bool {
		$path = self::path();
		return is_file( $path ) && self::is_ours( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
	}

	/**
	 * Why the loader cannot be installed here, or '' when it can.
	 *
	 * @return string
	 */
	public static function blocked_reason(): string {
		if ( is_multisite() ) {
			if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( ! is_plugin_active_for_network( plugin_basename( ZINN_BACKUPS_FILE ) ) ) {
				return __( 'On a multisite network the loader is only used when Zinn® Backups is network-activated, because a must-use plugin runs on every site.', 'zinn-backups' );
			}
		}
		$path = self::path();
		if ( is_file( $path ) && ! self::is_ours( (string) file_get_contents( $path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
			return __( 'Another file named zinn-backups-loader.php is already in the must-use plugins folder; it was left alone.', 'zinn-backups' );
		}
		return '';
	}

	/**
	 * Whether the site wants the loader (the "Keep running" setting).
	 *
	 * @return bool
	 */
	public static function wanted(): bool {
		return Settings::all()['keep_running'];
	}

	/**
	 * Whether the loader in place is the current one for this copy of the plugin.
	 *
	 * @return bool
	 */
	public static function current(): bool {
		try {
			$source = self::source();
		} catch ( \InvalidArgumentException $e ) {
			return false;
		}
		$path = self::path();
		return is_file( $path ) && (string) file_get_contents( $path ) === $source; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
	}

	/**
	 * The loader is never written by the plugin (WordPress.org review: a plugin does not write
	 * executable code into the must-use plugins folder). The site owner copies it in: the code is
	 * shown on the settings page and printed by `wp zinn-backups loader install`.
	 *
	 * @return true|string True when the current loader is in place, or what the owner has to do.
	 */
	public static function install() {
		$blocked = self::blocked_reason();
		if ( '' !== $blocked ) {
			return $blocked;
		}
		try {
			self::source();
		} catch ( \InvalidArgumentException $e ) {
			return $e->getMessage();
		}
		if ( self::current() ) {
			return true;
		}
		/* translators: %s: the full path the loader file goes to. */
		return sprintf( __( 'Copy the loader code into a new file at %s (the settings page shows it; WP-CLI prints it with: wp zinn-backups loader install).', 'zinn-backups' ), self::path() );
	}

	/**
	 * Remove the loader, if it is ours.
	 *
	 * @return bool Whether no loader of ours remains.
	 */
	public static function remove(): bool {
		if ( ! self::installed() ) {
			return true;
		}
		wp_delete_file( self::path() );
		return ! is_file( self::path() );
	}

	/**
	 * Make the loader match the setting.
	 *
	 * @return true|string True, or why it could not.
	 */
	public static function sync() {
		update_option( self::REV, self::rev(), false );
		if ( self::wanted() ) {
			return self::install();
		}
		return self::remove() ? true : __( 'The loader could not be removed.', 'zinn-backups' );
	}

	/**
	 * After an update (a new version or a moved folder), bring the loader up to date once; and once
	 * the plugin loads normally again, re-arm a loader whose fuse blew.
	 *
	 * @return void
	 */
	public static function maybe_sync(): void {
		if ( ! self::loaded_by_mu() && '' !== (string) get_option( self::TRIPPED, '' ) ) {
			delete_option( self::TRIPPED ); // The plugin loaded normally again: re-arm the loader.
		}
		if ( get_option( self::REV, '' ) !== self::rev() && current_user_can( Plugin::CAP ) ) {
			self::sync();
		}
	}

	/**
	 * What a sync is keyed on.
	 *
	 * @return string
	 */
	private static function rev(): string {
		return ZINN_BACKUPS_VERSION . '|' . plugin_basename( ZINN_BACKUPS_FILE );
	}
}
