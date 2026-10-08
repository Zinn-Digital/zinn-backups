<?php
/**
 * Packs listed files into numbered zip parts.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * ⛔⛔ THE CURSOR MOVES ONLY AFTER `ZipArchive::close()` SUCCEEDS. `close()` is where libzip
 * actually reads and compresses the files added in this step; if one vanished in between,
 * `close()` fails and the part on disk is left as it was before the step. So the step is
 * retried from the same place — with every file read up front this time, so a file that
 * vanished is skipped instead of failing again.
 *
 * Index lines (`[path, size, mtime, backup id, part]`) are written to the index file only
 * after the close succeeds, for the same reason.
 */
final class Archiver {

	/** Bytes of input added per step before the part is closed (bounds the time `close()` takes). */
	private const STEP_BYTES = 67108864;

	/** Already-compressed formats, stored rather than deflated. */
	private const STORED = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'm4v', 'mov', 'webm', 'mp3', 'm4a', 'ogg', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'woff', 'woff2', 'pdf', 'wpress' );

	/**
	 * Add files until the deadline, the step budget or the part limit.
	 *
	 * @param string              $list_file      The scan list.
	 * @param string              $index     The index file to append to.
	 * @param string              $part_path The current part.
	 * @param string              $part_name Its name in the backup.
	 * @param array<string,mixed> $cursor    `offset` (bytes into the list), `safe` (read files up front).
	 * @param int                 $part_max  Bytes after which the part is sealed.
	 * @param string              $backup_id This backup's id.
	 * @param float               $deadline  microtime deadline.
	 * @return array<string,mixed> Cursor; `part_full` when the part should be uploaded, `done` at the end of the list.
	 * @throws \RuntimeException On a failure.
	 */
	public static function add( string $list_file, string $index, string $part_path, string $part_name, array $cursor, int $part_max, string $backup_id, float $deadline ): array {
		$root   = trailingslashit( wp_normalize_path( ABSPATH ) );
		$in     = fopen( $list_file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading our own work file from a byte offset.
		$zip    = new \ZipArchive();
		$opened = $zip->open( $part_path, \ZipArchive::CREATE );
		if ( false === $in || true !== $opened ) {
			throw new \RuntimeException( esc_html__( 'A backup part could not be opened for writing. Check that wp-content has free space.', 'zinn-backups' ) );
		}
		fseek( $in, (int) $cursor['offset'] );
		$lines   = array();
		$added   = 0;
		$offset  = (int) $cursor['offset'];
		$current = file_exists( $part_path ) ? (int) filesize( $part_path ) : 0;
		$safe    = ! empty( $cursor['safe'] );
		$skipped = 0;
		while ( microtime( true ) < $deadline - 3 && $added < self::STEP_BYTES && $current + $added < $part_max ) {
			$line = fgets( $in );
			if ( false === $line ) {
				break;
			}
			$offset += strlen( $line );
			$row     = json_decode( $line, true );
			if ( ! is_array( $row ) || 3 !== count( $row ) ) {
				continue;
			}
			list( $relative, $size, $mtime ) = $row;
			/**
			 * Lets an incremental backup leave out a file that has not changed since an earlier
			 * backup. Return `[backup id, part name]` of the copy to use, or null to pack it.
			 *
			 * @param array{0:string,1:string}|null $previous Copy to reuse.
			 * @param string                        $relative Path relative to the WordPress root.
			 * @param int                           $size     Size in bytes.
			 * @param int                           $mtime    Modification time.
			 */
			$previous = apply_filters( 'zinn_backups_unchanged_file', null, (string) $relative, (int) $size, (int) $mtime );
			if ( is_array( $previous ) && 2 === count( $previous ) ) {
				$lines[] = array( $relative, (int) $size, (int) $mtime, (string) $previous[0], (string) $previous[1] );
				continue;
			}
			$absolute = $root . $relative;
			if ( ! is_readable( $absolute ) ) {
				++$skipped;
				continue;
			}
			if ( $safe ) {
				$data = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- re-reading after a failed close; see the class comment.
				if ( false === $data ) {
					++$skipped;
					continue;
				}
				$zip->addFromString( $relative, $data );
			} else {
				$zip->addFile( $absolute, $relative );
			}
			if ( in_array( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ), self::STORED, true ) ) {
				$zip->setCompressionName( $relative, \ZipArchive::CM_STORE );
			}
			$lines[] = array( $relative, (int) $size, (int) $mtime, $backup_id, $part_name );
			$added  += max( 1, (int) $size );
		}
		$at_end = false === fgets( $in );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.

		$has_files = $zip->count() > 0;
		if ( ! $zip->close() ) {
			if ( $safe ) {
				throw new \RuntimeException( esc_html__( 'A backup part could not be written. Check that wp-content has free space.', 'zinn-backups' ) );
			}
			$cursor['safe'] = true;
			return $cursor;
		}
		if ( $lines ) {
			$out = fopen( $index, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- append-only work file.
			foreach ( $lines as $entry ) {
				fwrite( $out, (string) wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		clearstatcache( true, $part_path );
		$cursor['offset']    = $offset;
		$cursor['safe']      = false;
		$cursor['skipped']   = (int) ( $cursor['skipped'] ?? 0 ) + $skipped;
		$cursor['done']      = $at_end && $offset >= (int) filesize( $list_file );
		$cursor['part_full'] = $has_files && file_exists( $part_path ) && ( (int) filesize( $part_path ) >= $part_max || $cursor['done'] );
		$cursor['part_has']  = $has_files && file_exists( $part_path );
		return $cursor;
	}
}
