<?php
/**
 * Plugin Name:       Zinn® Backups loader
 * Plugin URI:        https://zinndigital.com/wordpress-plugins/zinn-backups
 * Description:       Keeps Zinn® Backups' background backups, restores and WP-CLI commands working when the Zinn® Backups plugin is switched off, or when a restored database no longer lists it as active. Turn it off under Zinn® Backups → Settings; deleting Zinn® Backups removes it.
 * Version:           1
 * Author:            Neil Lock — CEO, Zinn Digital® Ltd
 * Author URI:        https://zinndigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zinn-backups
 *
 * Marker: zinn-backups-mu-loader
 *
 * ⛔ This file is written into wp-content/mu-plugins by Zinn® Backups (Mu_Loader::install()) and
 * removed by it. It never loads the plugin a second time: it acts only at `plugins_loaded`
 * priority 0, AFTER WordPress loaded its normal plugins, and only when Zinn® Backups did not load
 * itself (no ZINN_BACKUPS_VERSION). It stays inert in recovery mode, when the plugin folder is
 * gone, and after a load it started ended in a fatal error (the fuse below).
 *
 * @package ZinnDigital\Backups
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'plugins_loaded',
	static function (): void {
		$zinn_backups_target = 'zinn-backups/zinn-backups.php';
		if ( defined( 'ZINN_BACKUPS_VERSION' ) ) {
			return; // Zinn® Backups is active and loaded itself.
		}
		if ( function_exists( 'wp_is_recovery_mode' ) && wp_is_recovery_mode() ) {
			return; // Never override what recovery mode paused.
		}
		if ( '' !== (string) get_option( 'zinn_backups_mu_tripped', '' ) ) {
			return; // A load this file started ended in a fatal error; the plugin clears this when it next loads normally.
		}
		$zinn_backups_file = WP_PLUGIN_DIR . '/' . $zinn_backups_target;
		if ( ! is_readable( $zinn_backups_file ) ) {
			return; // The plugin was removed without uninstalling; stay inert.
		}
		$zinn_backups_dir = dirname( $zinn_backups_file );
		register_shutdown_function(
			static function () use ( $zinn_backups_dir ): void {
				$error = error_get_last();
				if ( is_array( $error ) && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) && str_starts_with( (string) $error['file'], $zinn_backups_dir . '/' ) ) {
					update_option( 'zinn_backups_mu_tripped', (string) time(), false );
				}
			}
		);
		define( 'ZINN_BACKUPS_LOADED_BY_MU', true );
		try {
			require_once $zinn_backups_file;
		} catch ( \Throwable $e ) {
			unset( $e );
			update_option( 'zinn_backups_mu_tripped', (string) time(), false );
		}
	},
	0
);
