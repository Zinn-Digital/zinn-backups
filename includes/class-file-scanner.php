<?php
/**
 * Lists the site's files into a work file, resumably.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * ⭐ The walk keeps an explicit queue of folders still to read, saved with the job, so a step
 * that ends mid-walk continues where it stopped instead of walking the whole tree again
 * (which would make a large site quadratic).
 *
 * Each line of the list is JSON: `[relative path, size, mtime]`.
 */
final class File_Scanner {

	/**
	 * A fresh cursor.
	 *
	 * @param bool $include_core Include WordPress's own files (wp-admin, wp-includes, root PHP files).
	 * @return array<string,mixed>
	 */
	public static function begin( bool $include_core ): array {
		return array(
			'queue'        => array( '' ),
			'files'        => 0,
			'bytes'        => 0,
			'include_core' => $include_core,
			'done'         => false,
		);
	}

	/**
	 * Walk until the deadline.
	 *
	 * @param string              $list_file     The list file to append to.
	 * @param array<string,mixed> $cursor   Cursor from {@see begin()}.
	 * @param string[]            $excludes Relative paths to leave out.
	 * @param float               $deadline microtime deadline.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On a failure.
	 */
	public static function scan( string $list_file, array $cursor, array $excludes, float $deadline ): array {
		$root   = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$handle = fopen( $list_file, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an append-only work file of unbounded size.
		if ( false === $handle ) {
			throw new \RuntimeException( esc_html__( 'The file list could not be written.', 'zinn-backups' ) );
		}
		try {
			while ( $cursor['queue'] && microtime( true ) < $deadline ) {
				$relative = (string) array_pop( $cursor['queue'] );
				$absolute = '' === $relative ? $root : $root . '/' . $relative;
				$dir      = @opendir( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder is skipped and logged by the caller's count, not a warning in the site's log.
				if ( false === $dir ) {
					continue;
				}
				$entry = readdir( $dir );
				while ( false !== $entry ) {
					if ( '.' !== $entry && '..' !== $entry ) {
						$child = '' === $relative ? $entry : $relative . '/' . $entry;
						$path  = $absolute . '/' . $entry;
						if ( ! self::excluded( $child, $excludes, (bool) $cursor['include_core'] ) && ! is_link( $path ) ) {
							if ( is_dir( $path ) ) {
								$cursor['queue'][] = $child;
							} elseif ( is_file( $path ) && is_readable( $path ) ) {
								$size = (int) filesize( $path );
								fwrite( $handle, (string) wp_json_encode( array( $child, $size, (int) filemtime( $path ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
								++$cursor['files'];
								$cursor['bytes'] += $size;
							}
						}
					}
					$entry = readdir( $dir );
				}
				closedir( $dir );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		$cursor['done'] = empty( $cursor['queue'] );
		return $cursor;
	}

	/**
	 * Whether a relative path is left out.
	 *
	 * @param string   $relative     Path relative to the WordPress root.
	 * @param string[] $excludes     Excluded paths.
	 * @param bool     $include_core Whether core files are included.
	 * @return bool
	 */
	public static function excluded( string $relative, array $excludes, bool $include_core ): bool {
		foreach ( $excludes as $exclude ) {
			if ( $relative === $exclude || str_starts_with( $relative, rtrim( $exclude, '/' ) . '/' ) ) {
				return true;
			}
			// A trailing dash matches a prefix (our own work folder before it has a name).
			if ( str_ends_with( $exclude, '-' ) && str_starts_with( $relative, $exclude ) ) {
				return true;
			}
		}
		if ( $include_core ) {
			return false;
		}
		if ( 'wp-admin' === $relative || 'wp-includes' === $relative || str_starts_with( $relative, 'wp-admin/' ) || str_starts_with( $relative, 'wp-includes/' ) ) {
			return true;
		}
		// WordPress's own root files; wp-config.php and anything else at the root are kept.
		return ! str_contains( $relative, '/' ) && 1 === preg_match( '/^(wp-(?!config\.php$)[a-z-]+\.php|index\.php|xmlrpc\.php|license\.txt|readme\.html)$/', $relative );
	}
}
