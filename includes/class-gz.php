<?php
/**
 * Gzip streams, opened only where this host's PHP can open them.
 *
 * @package ZinnBackups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * ⛔⛔ `gzopen()` is missing when PHP has no zlib and some hosts list it in `disable_functions`.
 * On PHP 8 calling it then is a fatal "Call to undefined function" that `@` does not stop, so the
 * backup or restore would die mid-run with no message. Every gzip stream opens here instead and
 * the failure is a RuntimeException the job runner reports (V1 ticket 13692, docs/72).
 */
final class Gz {

	/**
	 * Open a gzip stream or throw a sentence the customer can act on.
	 *
	 * @param string $file Path.
	 * @param string $mode gzopen mode.
	 * @return resource
	 * @throws \RuntimeException When this host cannot read or write gzip, or the file will not open.
	 */
	public static function open( string $file, string $mode ) {
		if ( ! zinn_backups_can_call( 'gzopen' ) ) {
			throw new \RuntimeException( esc_html__( 'This site\'s PHP cannot read or write compressed files (zlib is missing or gzopen is disabled by the host), so this backup step could not run.', 'zinn-backups' ) );
		}
		$gz = gzopen( $file, $mode );
		if ( false === $gz ) {
			throw new \RuntimeException( esc_html__( 'A compressed backup file could not be opened. Check that wp-content has free space.', 'zinn-backups' ) );
		}
		return $gz;
	}
}
