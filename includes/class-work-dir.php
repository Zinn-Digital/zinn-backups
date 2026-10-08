<?php
/**
 * The private folder a job writes its temporary files into.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * ⛔ The folder name carries 32 hex characters of randomness AND is sealed (deny-all
 * `.htaccess`, `web.config`, empty `index.php`/`index.html`): a part of a backup is a copy of
 * the site's files or database, and on nginx the `.htaccess` does nothing, so the name is the
 * control that works everywhere.
 *
 * ⛔ Job state also lives here, in a file, not in `wp_options`: a restore REPLACES
 * `wp_options`, and a job whose state is in the table it is replacing loses its place the
 * moment the swap happens.
 */
final class Work_Dir {

	private const OPTION = 'zinn_backups_work_dir';

	/**
	 * Absolute path of the work folder, created and sealed on first use.
	 *
	 * @return string Path without a trailing slash.
	 * @throws \RuntimeException When no writable location exists.
	 */
	public static function path(): string {
		$name = self::name();
		foreach ( self::parents() as $parent ) {
			$dir = $parent . '/' . $name;
			if ( is_dir( $dir ) || wp_mkdir_p( $dir ) ) {
				self::seal( $dir );
				return $dir;
			}
		}
		throw new \RuntimeException( esc_html__( 'There is no writable folder for temporary backup files. Make wp-content writable and try again.', 'zinn-backups' ) );
	}

	/**
	 * The folder's name.
	 *
	 * ⛔ Derived from the `wp-config.php` keys when they are set, because a restore replaces
	 * `wp_options` mid-job and a name kept only there would change under the running restore.
	 * The option is the fallback for a site with no keys in its config, and the restore puts
	 * it back after the table swap ({@see Restore_Job}).
	 *
	 * @return string
	 */
	public static function name(): string {
		if ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) && strlen( (string) AUTH_KEY . (string) SECURE_AUTH_KEY ) > 40 && ! str_contains( (string) AUTH_KEY, 'put your unique phrase here' ) ) {
			return 'zinn-backups-' . substr( hash_hmac( 'sha256', 'zinn-backups-work', (string) AUTH_KEY . (string) SECURE_AUTH_KEY ), 0, 32 );
		}
		$name = (string) get_option( self::OPTION, '' );
		if ( 1 !== preg_match( '/^zinn-backups-[a-f0-9]{32}$/', $name ) ) {
			$name = 'zinn-backups-' . bin2hex( random_bytes( 16 ) );
			update_option( self::OPTION, $name, false );
		}
		return $name;
	}

	/**
	 * Put the fallback name back after a restore replaced `wp_options`.
	 *
	 * @param string $name The name in use before the swap.
	 * @return void
	 */
	public static function remember( string $name ): void {
		if ( 1 === preg_match( '/^zinn-backups-[a-f0-9]{32}$/', $name ) ) {
			update_option( self::OPTION, $name, false );
		}
	}

	/**
	 * A sub-folder for one job.
	 *
	 * @param string $job_id The job id.
	 * @return string
	 * @throws \RuntimeException When the folder cannot be created.
	 */
	public static function job( string $job_id ): string {
		$dir = self::path() . '/' . preg_replace( '/[^a-z0-9-]/', '', strtolower( $job_id ) );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( esc_html__( 'Could not create a folder for this job.', 'zinn-backups' ) );
		}
		return $dir;
	}

	/**
	 * Delete a job folder and everything in it.
	 *
	 * @param string $job_id The job id.
	 * @return void
	 */
	public static function remove_job( string $job_id ): void {
		$id = preg_replace( '/[^a-z0-9-]/', '', strtolower( $job_id ) );
		if ( '' === $id ) {
			return;
		}
		self::rmdir( self::path() . '/' . $id );
	}

	/**
	 * Remove a directory tree.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	public static function rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own private work folder.
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own private work folder.
	}

	/**
	 * Folder name relative to ABSPATH, so the scanner can leave it out of a backup.
	 *
	 * @return string
	 */
	public static function relative(): string {
		try {
			$path = wp_normalize_path( self::path() );
		} catch ( \RuntimeException ) {
			return '';
		}
		$root = wp_normalize_path( ABSPATH );
		return str_starts_with( $path, $root ) ? ltrim( substr( $path, strlen( $root ) ), '/' ) : '';
	}

	/**
	 * Candidate parent folders, best first.
	 *
	 * @return string[]
	 */
	private static function parents(): array {
		$uploads = wp_upload_dir( null, false );
		return array_values(
			array_filter(
				array( untrailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) ),
				static fn( string $dir ): bool => '' !== $dir && wp_is_writable( $dir )
			)
		);
	}

	/**
	 * Make a folder unlistable and unservable.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	private static function seal( string $dir ): void {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
			'.htaccess'  => "Require all denied\nDeny from all\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $body ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- sealing our own private folder.
			}
		}
	}
}
