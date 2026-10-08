<?php
/**
 * Uninstall: remove the settings, the job history, the work folder and the schedule.
 *
 * ⛔ Backups in the customer's own storage are NOT touched: they belong to the customer and
 * are exactly what they may need after removing the plugin (a restore with a fresh install).
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete what this plugin keeps on the site.
 *
 * @return void
 */
function zinn_backups_uninstall(): void {
	global $wpdb;
	wp_clear_scheduled_hook( 'zinn_backups_continue' );
	$work_name = (string) get_option( 'zinn_backups_work_dir', '' );
	if ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) && strlen( (string) AUTH_KEY . (string) SECURE_AUTH_KEY ) > 40 ) {
		$work_name = 'zinn-backups-' . substr( hash_hmac( 'sha256', 'zinn-backups-work', (string) AUTH_KEY . (string) SECURE_AUTH_KEY ), 0, 32 );
	}
	$uploads = wp_upload_dir( null, false );
	foreach ( array( untrailingslashit( (string) $uploads['basedir'] ) ) as $parent ) {
		$dir = $parent . '/' . $work_name;
		if ( 1 === preg_match( '/^zinn-backups-[a-f0-9]{32}$/', $work_name ) && is_dir( $dir ) ) {
			$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $items as $item ) {
				if ( $item->isDir() && ! $item->isLink() ) {
					rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own private work folder.
				} else {
					wp_delete_file( $item->getPathname() );
				}
			}
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- see above.
		}
	}
	$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'zinn_backups_' ) . '%', $wpdb->esc_like( '_transient_zinn_backups_' ) . '%', $wpdb->esc_like( '_transient_timeout_zinn_backups_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall: finding our own options.
	foreach ( (array) $options as $option ) {
		if ( ! str_starts_with( (string) $option, 'zinn_backups_pro' ) ) {
			delete_option( (string) $option );
		}
	}
}

zinn_backups_uninstall();
