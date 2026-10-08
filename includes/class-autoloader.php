<?php
/**
 * Class autoloader for the plugin's namespace.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `ZinnDigital\Backups\Storage\Google_Drive` to `includes/storage/class-google-drive.php`
 * (or `interface-google-drive.php` for an interface).
 */
final class Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @param string $base Absolute path of the `includes` directory.
	 * @return void
	 */
	public static function register( string $base ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base ): void {
				$prefix = __NAMESPACE__ . '\\';
				if ( ! str_starts_with( $class_name, $prefix ) ) {
					return;
				}
				$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
				$leaf  = strtolower( str_replace( '_', '-', (string) array_pop( $parts ) ) );
				$dir   = '';
				foreach ( $parts as $part ) {
					$dir .= strtolower( str_replace( '_', '-', $part ) ) . '/';
				}
				foreach ( array( 'class-', 'interface-', 'trait-' ) as $kind ) {
					$path = $base . '/' . $dir . $kind . $leaf . '.php';
					if ( is_readable( $path ) ) {
						require_once $path;
						return;
					}
				}
			}
		);
	}
}
