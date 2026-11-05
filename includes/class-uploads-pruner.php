<?php
/**
 * Opt-in: after a files restore, delete the uploads files that are not in the backup.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * A restore never deletes a file by default: whatever is on the site and not in the backup is
 * kept. A site owner who wants the uploads folder to match the backup exactly (media uploaded
 * after the backup, a malicious file dropped into uploads) adds
 * `define( 'ZINN_BACKUPS_RESTORE_DELETE_LEFTOVER_UPLOADS', true );` to wp-config.php. Then, after the files are restored, every file under the uploads folder that the backup's own
 * file index does not list is deleted. Never touched: anything outside the uploads folder, a
 * symbolic link, and every path a backup leaves out (Settings::excludes(): the work folder,
 * caches and the site's own "leave out" list), because those were never in a backup to compare.
 *
 * ⭐ Resumable like File_Scanner: the walk keeps an explicit queue of folders still to read in
 * the job's state, so a step that ends at its deadline continues where it stopped.
 */
final class Uploads_Pruner {

	/**
	 * The wp-config.php constant that turns it on.
	 */
	public const CONSTANT = 'ZINN_BACKUPS_RESTORE_DELETE_LEFTOVER_UPLOADS';

	/**
	 * Whether the site owner turned it on.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return defined( self::CONSTANT ) && true === (bool) constant( self::CONSTANT );
	}

	/**
	 * The uploads folder relative to the WordPress root, or '' when it lies outside it.
	 *
	 * @param string $root    The WordPress root (ABSPATH).
	 * @param string $basedir The uploads base folder.
	 * @return string
	 */
	public static function relative_uploads( string $root, string $basedir ): string {
		$root    = trailingslashit( wp_normalize_path( $root ) );
		$basedir = untrailingslashit( wp_normalize_path( $basedir ) );
		if ( '' === $basedir || ! str_starts_with( $basedir . '/', $root ) || $basedir . '/' === $root ) {
			return '';
		}
		return substr( $basedir, strlen( $root ) );
	}

	/**
	 * A fresh cursor for the uploads folder.
	 *
	 * @param string $uploads Uploads folder relative to the root.
	 * @return array<string,mixed>
	 */
	public static function begin( string $uploads ): array {
		return array(
			'queue'   => array( $uploads ),
			'deleted' => 0,
			'failed'  => 0,
			'done'    => false,
		);
	}

	/**
	 * The relative paths a backup's file index lists, as a set.
	 *
	 * @param string $index_gz The downloaded files-index.ndjson.gz.
	 * @return array<string,bool>
	 * @throws \RuntimeException When the index cannot be read (nothing is deleted then).
	 */
	public static function keep_set( string $index_gz ): array {
		$gz = ( zinn_backups_can_call( 'gzopen' ) && is_readable( $index_gz ) ) ? gzopen( $index_gz, 'rb' ) : false;
		if ( false === $gz ) {
			throw new \RuntimeException( esc_html__( 'The backup’s file list could not be read, so no leftover uploads were deleted.', 'zinn-backups' ) );
		}
		$keep = array();
		while ( ! gzeof( $gz ) ) {
			$line = gzgets( $gz );
			$row  = is_string( $line ) ? json_decode( $line, true ) : null;
			if ( is_array( $row ) && isset( $row[0] ) && is_string( $row[0] ) ) {
				$keep[ $row[0] ] = true;
			}
		}
		gzclose( $gz );
		return $keep;
	}

	/**
	 * Walk and delete until the deadline.
	 *
	 * @param string              $root     The WordPress root (ABSPATH).
	 * @param array<string,mixed> $cursor   Cursor from {@see begin()}.
	 * @param array<string,bool>  $keep     Paths the backup holds ({@see keep_set()}).
	 * @param string[]            $excludes Paths a backup leaves out (never deleted).
	 * @param float               $deadline microtime deadline.
	 * @return array<string,mixed>
	 */
	public static function step( string $root, array $cursor, array $keep, array $excludes, float $deadline ): array {
		$root = untrailingslashit( wp_normalize_path( $root ) );
		while ( $cursor['queue'] && microtime( true ) < $deadline ) {
			$relative = (string) array_pop( $cursor['queue'] );
			$absolute = $root . '/' . $relative;
			if ( is_link( $absolute ) ) {
				continue;
			}
			$dir = @opendir( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder is left alone.
			if ( false === $dir ) {
				continue;
			}
			$doomed = array();
			$entry  = readdir( $dir );
			while ( false !== $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					$child = $relative . '/' . $entry;
					$path  = $absolute . '/' . $entry;
					if ( ! File_Scanner::excluded( $child, $excludes, true ) && ! is_link( $path ) ) {
						if ( is_dir( $path ) ) {
							$cursor['queue'][] = $child;
						} elseif ( ! isset( $keep[ $child ] ) ) {
							$doomed[] = $path;
						}
					}
				}
				$entry = readdir( $dir );
			}
			closedir( $dir );
			foreach ( $doomed as $path ) {
				wp_delete_file( $path );
				if ( file_exists( $path ) ) {
					++$cursor['failed'];
				} else {
					++$cursor['deleted'];
				}
			}
		}
		$cursor['done'] = empty( $cursor['queue'] );
		return $cursor;
	}
}
