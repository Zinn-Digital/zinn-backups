<?php
/**
 * Reads entries out of zip, tar, tar.gz and All-in-One WP Migration `.wpress` archives.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups\Import;

defined( 'ABSPATH' ) || exit;

/**
 * One walker for every container format, so the formats only differ in where an entry goes
 * (the `$route` callback), never in how bytes are copied.
 *
 * ⛔ Every entry name is checked before it is written: no absolute paths, no `..`, no drive
 * letters, no NUL — an archive from a stranger must not write outside the folder it is
 * routed to (zip-slip).
 *
 * The cursor is the entry index (zip) or the byte offset of the next header (tar, wpress), so
 * a step that ends mid-archive continues at the next entry.
 */
final class Archive_Reader {

	private const WPRESS_HEADER = 4377;

	/**
	 * The container kind, from the file's bytes.
	 *
	 * @param string $file File.
	 * @return string zip|tar|tgz|wpress|gz|sql|unknown
	 */
	public static function kind( string $file ): string {
		$head = (string) file_get_contents( $file, false, null, 0, 4377 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- magic bytes of a local file.
		if ( str_starts_with( $head, "PK\x03\x04" ) || str_starts_with( $head, "PK\x05\x06" ) ) {
			return 'zip';
		}
		if ( str_starts_with( $head, "\x1f\x8b" ) ) {
			$gz    = gzopen( $file, 'rb' );
			$inner = (string) gzread( $gz, 512 );
			gzclose( $gz );
			if ( strlen( $inner ) >= 262 && 'ustar' === substr( $inner, 257, 5 ) ) {
				return 'tgz';
			}
			return 'gz';
		}
		if ( strlen( $head ) >= 262 && 'ustar' === substr( $head, 257, 5 ) ) {
			return 'tar';
		}
		if ( self::looks_wpress( $head ) ) {
			return 'wpress';
		}
		if ( preg_match( '/^(--|\/\*|CREATE|DROP|SET|INSERT|#)/m', substr( $head, 0, 2048 ) ) ) {
			return 'sql';
		}
		return 'unknown';
	}

	/**
	 * Whether a header block looks like a `.wpress` entry header.
	 *
	 * @param string $head First bytes.
	 * @return bool
	 */
	private static function looks_wpress( string $head ): bool {
		if ( strlen( $head ) < self::WPRESS_HEADER ) {
			return false;
		}
		$name = rtrim( substr( $head, 0, 255 ), "\0" );
		$size = rtrim( substr( $head, 255, 14 ), "\0" );
		return '' !== $name && ctype_digit( $size ) && ! str_contains( $name, "\0" );
	}

	/**
	 * List entry names (for detection), up to a limit.
	 *
	 * @param string $file  File.
	 * @param string $kind  Container kind.
	 * @param int    $limit Max names.
	 * @return string[]
	 */
	public static function names( string $file, string $kind, int $limit = 5000 ): array {
		$names = array();
		if ( 'zip' === $kind ) {
			$zip = new \ZipArchive();
			if ( true === $zip->open( $file, \ZipArchive::RDONLY ) ) {
				$count = min( $limit, $zip->count() );
				for ( $i = 0; $i < $count; $i++ ) {
					$names[] = (string) $zip->getNameIndex( $i );
				}
				$zip->close();
			}
			return $names;
		}
		$cursor = array( 'offset' => 0 );
		self::walk(
			$file,
			$kind,
			$cursor,
			static function ( string $name ) use ( &$names, $limit ): ?string {
				if ( count( $names ) < $limit ) {
					$names[] = $name;
				}
				return null;
			},
			microtime( true ) + 20,
			$limit
		);
		return $names;
	}

	/**
	 * Walk entries from the cursor until the deadline, writing each to where `$route` says.
	 *
	 * @param string              $file     Archive.
	 * @param string              $kind     zip|tar|tgz|wpress.
	 * @param array<string,mixed> $cursor   `index` (zip) or `offset` (others); `written`, `bytes`.
	 * @param callable            $route    fn(string $name): ?string — absolute target path or null to skip.
	 * @param float               $deadline microtime deadline.
	 * @param int                 $max      Stop after this many entries (for listing).
	 * @return array<string,mixed> Cursor; `done` at the end.
	 */
	public static function walk( string $file, string $kind, array $cursor, callable $route, float $deadline, int $max = PHP_INT_MAX ): array {
		return match ( $kind ) {
			'zip'    => self::walk_zip( $file, $cursor, $route, $deadline ),
			'tar', 'tgz' => self::walk_tar( $file, 'tgz' === $kind, $cursor, $route, $deadline, $max ),
			'wpress' => self::walk_wpress( $file, $cursor, $route, $deadline, $max ),
			default  => array( 'done' => true ) + $cursor,
		};
	}

	/**
	 * Zip archives.
	 *
	 * @param string   $file     Archive.
	 * @param array    $cursor   Cursor.
	 * @param callable $route    Router.
	 * @param float    $deadline Deadline.
	 * @return array
	 * @throws \RuntimeException On a failure.
	 */
	private static function walk_zip( string $file, array $cursor, callable $route, float $deadline ): array {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file, \ZipArchive::RDONLY ) ) {
			throw new \RuntimeException( esc_html__( 'The zip archive could not be opened. It may be damaged or incomplete.', 'zinn-backups' ) );
		}
		$index = (int) ( $cursor['index'] ?? 0 );
		$total = $zip->count();
		while ( $index < $total && microtime( true ) < $deadline ) {
			$name = (string) $zip->getNameIndex( $index );
			++$index;
			if ( str_ends_with( $name, '/' ) || ! self::safe_name( $name ) ) {
				continue;
			}
			$target = $route( $name );
			if ( null === $target ) {
				continue;
			}
			$stream = $zip->getStream( $name );
			if ( false === $stream ) {
				continue;
			}
			$bytes = self::write_stream( $stream, $target );
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- zip entry stream.
			if ( null === $bytes ) {
				$cursor = self::unwritable( $cursor, $target );
				continue;
			}
			$cursor['bytes']   = (int) ( $cursor['bytes'] ?? 0 ) + $bytes;
			$cursor['written'] = (int) ( $cursor['written'] ?? 0 ) + 1;
		}
		$zip->close();
		$cursor['index'] = $index;
		$cursor['total'] = $total;
		$cursor['done']  = $index >= $total;
		return $cursor;
	}

	/**
	 * Tar and tar.gz archives (ustar, GNU long names, pax paths).
	 *
	 * @param string   $file     Archive.
	 * @param bool     $gz       Gzip-compressed.
	 * @param array    $cursor   Cursor.
	 * @param callable $route    Router.
	 * @param float    $deadline Deadline.
	 * @param int      $max      Max entries.
	 * @return array
	 */
	private static function walk_tar( string $file, bool $gz, array $cursor, callable $route, float $deadline, int $max ): array {
		$in     = $gz ? gzopen( $file, 'rb' ) : fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming an archive.
		$offset = (int) ( $cursor['offset'] ?? 0 );
		$read   = static fn( int $n ): string => (string) ( $gz ? gzread( $in, $n ) : fread( $in, $n ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		if ( $gz ) {
			gzseek( $in, $offset );
		} else {
			fseek( $in, $offset );
		}
		$long = '';
		$seen = 0;
		$done = false;
		while ( microtime( true ) < $deadline && $seen < $max ) {
			$header = $read( 512 );
			if ( strlen( $header ) < 512 || '' === trim( $header, "\0" ) ) {
				$done = true;
				break;
			}
			$size = self::tar_size( substr( $header, 124, 12 ) );
			$type = $header[156];
			$name = rtrim( substr( $header, 0, 100 ), "\0" );
			if ( 'ustar' === substr( $header, 257, 5 ) ) {
				$prefix = rtrim( substr( $header, 345, 155 ), "\0" );
				$name   = '' !== $prefix ? $prefix . '/' . $name : $name;
			}
			// ⛔ Links, devices, FIFOs and directories carry no data, whatever their size field
			// says: BackWPup writes 4096 for a directory, and honouring it skips eight blocks of
			// the next file (measured on a real BackWPup archive, 2026-10-08).
			if ( in_array( $type, array( '1', '2', '3', '4', '5', '6' ), true ) ) {
				$size = 0;
			}
			$padded = (int) ( ceil( $size / 512 ) * 512 );
			if ( 'L' === $type || 'x' === $type || 'g' === $type ) {
				$data = $padded > 0 ? $read( $padded ) : '';
				if ( 'L' === $type ) {
					$long = rtrim( substr( $data, 0, $size ), "\0" );
				} elseif ( 'x' === $type && preg_match( '/\d+ path=([^\n]+)\n/', substr( $data, 0, $size ), $m ) ) {
					$long = $m[1];
				}
				$offset += 512 + $padded;
				continue;
			}
			if ( '' !== $long ) {
				$name = $long;
				$long = '';
			}
			$offset += 512 + $padded;
			$target  = ( '0' === $type || "\0" === $type ) && self::safe_name( $name ) ? $route( $name ) : null;
			++$seen;
			if ( null === $target ) {
				self::skip( $in, $gz, $padded, $read );
				continue;
			}
			$out = self::open_target( $target );
			if ( null === $out ) {
				$cursor = self::unwritable( $cursor, $target );
				self::skip( $in, $gz, $padded, $read );
				continue;
			}
			$remaining = $size;
			while ( $remaining > 0 ) {
				$chunk = $read( min( 1048576, $remaining ) );
				if ( '' === $chunk ) {
					break;
				}
				fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing an extracted file.
				$remaining -= strlen( $chunk );
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			if ( $padded > $size ) {
				$read( $padded - $size );
			}
			$cursor['written'] = (int) ( $cursor['written'] ?? 0 ) + 1;
			$cursor['bytes']   = (int) ( $cursor['bytes'] ?? 0 ) + $size;
		}
		if ( $gz ) {
			gzclose( $in );
		} else {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		$cursor['offset'] = $offset;
		$cursor['done']   = $done;
		return $cursor;
	}

	/**
	 * All-in-One WP Migration `.wpress`: a 4377-byte header (name 255, size 14, mtime 12,
	 * path 4088, CRC32 8) before each file's bytes; a header with an empty name ends it.
	 *
	 * @param string   $file     Archive.
	 * @param array    $cursor   Cursor.
	 * @param callable $route    Router.
	 * @param float    $deadline Deadline.
	 * @param int      $max      Max entries.
	 * @return array
	 * @throws \RuntimeException On a failure.
	 */
	private static function walk_wpress( string $file, array $cursor, callable $route, float $deadline, int $max ): array {
		$in     = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming an archive.
		$offset = (int) ( $cursor['offset'] ?? 0 );
		fseek( $in, $offset );
		$read = static fn( int $n ): string => (string) fread( $in, $n ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		$done = false;
		$seen = 0;
		while ( microtime( true ) < $deadline && $seen < $max ) {
			$header = $read( self::WPRESS_HEADER );
			$name   = rtrim( substr( $header, 0, 255 ), "\0" );
			// End of archive: v1 is a block of NULs; v2 (7.x) has an empty name and carries
			// the archive's size and CRC in the other fields.
			if ( strlen( $header ) < self::WPRESS_HEADER || '' === $name ) {
				$done = true;
				break;
			}
			$size = rtrim( substr( $header, 255, 14 ), "\0" );
			// ⛔ 4088, not 4096: since 7.x the last 8 bytes of the old path field are the
			// entry's CRC32 (`a4088a8`), and reading them as path turns every name into one
			// with a NUL in the middle — which the safety check rightly refuses.
			$path = rtrim( substr( $header, 281, 4088 ), "\0" );
			if ( ! ctype_digit( $size ) ) {
				throw new \RuntimeException( esc_html__( 'This .wpress file uses a format we cannot read (it may be compressed or encrypted by All-in-One WP Migration). Export it again without encryption.', 'zinn-backups' ) );
			}
			$size     = (int) $size;
			$relative = ( '.' === $path || '' === $path ) ? $name : trim( str_replace( '\\', '/', $path ), '/' ) . '/' . $name;
			$offset  += self::WPRESS_HEADER + $size;
			++$seen;
			$target = self::safe_name( $relative ) ? $route( $relative ) : null;
			if ( null === $target ) {
				fseek( $in, $offset );
				continue;
			}
			$out = self::open_target( $target );
			if ( null === $out ) {
				$cursor = self::unwritable( $cursor, $target );
				fseek( $in, $offset );
				continue;
			}
			$remaining = $size;
			while ( $remaining > 0 ) {
				$chunk = $read( min( 1048576, $remaining ) );
				if ( '' === $chunk ) {
					break;
				}
				fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing an extracted file.
				$remaining -= strlen( $chunk );
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			$cursor['written'] = (int) ( $cursor['written'] ?? 0 ) + 1;
			$cursor['bytes']   = (int) ( $cursor['bytes'] ?? 0 ) + $size;
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		$cursor['offset'] = $offset;
		$cursor['done']   = $done;
		return $cursor;
	}

	/**
	 * Tar size field (octal, or base-256 for very large files).
	 *
	 * @param string $field 12-byte field.
	 * @return int
	 */
	private static function tar_size( string $field ): int {
		if ( ord( $field[0] ) & 0x80 ) {
			$size = 0;
			for ( $i = 1; $i < 12; $i++ ) {
				$size = ( $size << 8 ) | ord( $field[ $i ] );
			}
			return $size;
		}
		return (int) octdec( trim( $field, "\0 " ) );
	}

	/**
	 * Skip bytes of a stream.
	 *
	 * @param resource $in    Stream.
	 * @param bool     $gz    Gzip stream.
	 * @param int      $bytes Bytes.
	 * @param callable $read  Reader.
	 * @return void
	 */
	private static function skip( $in, bool $gz, int $bytes, callable $read ): void {
		if ( ! $gz ) {
			fseek( $in, $bytes, SEEK_CUR );
			return;
		}
		while ( $bytes > 0 ) {
			$chunk  = $read( min( 1048576, $bytes ) );
			$bytes -= strlen( $chunk );
			if ( '' === $chunk ) {
				return;
			}
		}
	}

	/**
	 * Whether an entry name is safe to write anywhere.
	 *
	 * @param string $name Entry name.
	 * @return bool
	 */
	public static function safe_name( string $name ): bool {
		$name = str_replace( '\\', '/', $name );
		if ( '' === $name || str_contains( $name, "\0" ) || str_starts_with( $name, '/' ) || preg_match( '#^[A-Za-z]:#', $name ) ) {
			return false;
		}
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Open a target file for writing, creating its folder; null when the site cannot write it.
	 *
	 * ⛔ One file the site's user may not write (a security tool's root-owned `.htaccess`,
	 * measured on a hosted site 2026-10-08) must not abort the whole restore: it is skipped,
	 * counted and named in the log, and everything else is restored.
	 *
	 * @param string $target Absolute path.
	 * @return resource|null
	 */
	private static function open_target( string $target ) {
		wp_mkdir_p( dirname( $target ) );
		$out = @fopen( $target, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- writing an extracted file; a refusal is counted and reported, not a PHP warning in the site's log.
		return false === $out ? null : $out;
	}

	/**
	 * Note a file that could not be written.
	 *
	 * @param array  $cursor Cursor.
	 * @param string $target Path.
	 * @return array
	 */
	private static function unwritable( array $cursor, string $target ): array {
		$cursor['unwritable'] = (int) ( $cursor['unwritable'] ?? 0 ) + 1;
		if ( count( (array) ( $cursor['unwritable_paths'] ?? array() ) ) < 5 ) {
			$cursor['unwritable_paths'][] = $target;
		}
		return $cursor;
	}

	/**
	 * Copy a stream to a file.
	 *
	 * @param resource $stream Source.
	 * @param string   $target Target path.
	 * @return int|null Bytes, or null when the target cannot be written.
	 */
	private static function write_stream( $stream, string $target ): ?int {
		$out = self::open_target( $target );
		if ( null === $out ) {
			return null;
		}
		$bytes = (int) stream_copy_to_stream( $stream, $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		return $bytes;
	}
}
