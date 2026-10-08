<?php
/**
 * Runs an SQL dump into temporary tables, statement by statement, resumably.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * ⛔⛔ NOTHING IS WRITTEN TO THE LIVE TABLES. Every table in the dump is created under a
 * temporary prefix (`zbr1a2b_wp_posts`); the site keeps running on its own tables until the
 * whole dump is in and checked, and only then are the two sets swapped by one
 * `RENAME TABLE` ({@see Table_Swap}). A dump that fails halfway leaves the site as it was.
 *
 * Table names are rewritten from the dump's prefix to this site's (a backup from a site with
 * `wp_` restores into a site with `wp7x_`). Tables without the dump's prefix belong to some
 * other application sharing that database and are skipped.
 *
 * ⭐ Reads plain `.sql` and gzip `.sql.gz`/`.gz` (gzip members concatenated is still gzip).
 * The cursor is a byte offset into the UNCOMPRESSED stream; resuming a gzip file reads up to
 * it again, which costs time and never correctness.
 */
final class Sql_Importer {

	/** Bytes read per read call. */
	private const READ = 1048576;

	/**
	 * Find the table prefix a dump was written with.
	 *
	 * @param string $file Dump file.
	 * @return string Prefix ('' when the dump has no WordPress options table).
	 */
	public static function detect_prefix( string $file ): string {
		$head = self::head( $file, 4194304 );
		if ( str_contains( $head, 'SERVMASK_PREFIX_' ) ) {
			return 'SERVMASK_PREFIX_'; // All-in-One WP Migration writes this placeholder.
		}
		if ( preg_match( '/^# Table prefix: (\S+)/m', $head, $m ) ) {
			return $m[1]; // UpdraftPlus.
		}
		if ( preg_match( '/^-- Table prefix: (\S+)/m', $head, $m ) ) {
			return $m[1]; // Ours.
		}
		if ( preg_match_all( '/(?:CREATE TABLE|INSERT INTO)(?: IF NOT EXISTS)?\s+`?([A-Za-z0-9_$]+?)(options|posts|usermeta)`?[\s(]/', $head, $all, PREG_SET_ORDER ) ) {
			return $all[0][1];
		}
		return '';
	}

	/**
	 * The site address a dump was taken from, read from the dump itself when it says so.
	 *
	 * @param string $file Dump file.
	 * @return string
	 */
	public static function detect_home( string $file ): string {
		$head = self::head( $file, 1048576 );
		if ( preg_match( '/^# (?:Home URL|Backup of): (\S+)/m', $head, $m ) ) {
			return untrailingslashit( $m[1] );
		}
		return '';
	}

	/**
	 * Import until the deadline.
	 *
	 * @param string              $file     Dump file.
	 * @param array<string,mixed> $cursor   `offset`, `from` (dump prefix), `to` (temporary + site prefix), `tables`, `statements`.
	 * @param float               $deadline microtime deadline.
	 * @return array<string,mixed> Updated cursor; `done` at the end of the file.
	 * @throws \RuntimeException On a failure.
	 */
	public static function import( string $file, array $cursor, float $deadline ): array {
		global $wpdb;
		$gz = self::is_gzip( $file );
		$in = $gz ? gzopen( $file, 'rb' ) : fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a database dump.
		if ( false === $in ) {
			throw new \RuntimeException( esc_html__( 'The database file could not be opened.', 'zinn-backups' ) );
		}
		$offset = (int) ( $cursor['offset'] ?? 0 );
		if ( $gz ) {
			gzseek( $in, $offset );
		} else {
			fseek( $in, $offset );
		}
		self::session();
		$buffer    = '';
		$statement = '';
		$state     = array(
			'quote'   => '',
			'comment' => '',
		);
		$consumed  = $offset;
		$eof       = false;
		while ( microtime( true ) < $deadline ) {
			$chunk = $gz ? gzread( $in, self::READ ) : fread( $in, self::READ ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
			if ( false === $chunk || '' === $chunk ) {
				$eof = true;
				break;
			}
			$buffer .= $chunk;
			$start   = 0;
			$length  = strlen( $buffer );
			for ( $i = 0; $i < $length; $i++ ) {
				$c = $buffer[ $i ];
				if ( '' !== $state['comment'] ) {
					if ( ( 'line' === $state['comment'] && "\n" === $c ) || ( 'block' === $state['comment'] && '*' === $c && '/' === ( $buffer[ $i + 1 ] ?? '' ) ) ) {
						if ( 'block' === $state['comment'] ) {
							++$i;
						}
						$state['comment'] = '';
					}
					continue;
				}
				if ( '' !== $state['quote'] ) {
					if ( '\\' === $c && '`' !== $state['quote'] ) {
						++$i;
					} elseif ( $c === $state['quote'] ) {
						$state['quote'] = '';
					}
					continue;
				}
				if ( "'" === $c || '"' === $c || '`' === $c ) {
					$state['quote'] = $c;
				} elseif ( '-' === $c && '-' === ( $buffer[ $i + 1 ] ?? '' ) && in_array( $buffer[ $i + 2 ] ?? '', array( ' ', "\t", "\n", "\r" ), true ) && self::at_line_start( $buffer, $i, $start ) ) {
					$state['comment'] = 'line';
				} elseif ( '#' === $c && self::at_line_start( $buffer, $i, $start ) ) {
					$state['comment'] = 'line';
				} elseif ( '/' === $c && '*' === ( $buffer[ $i + 1 ] ?? '' ) && '!' !== ( $buffer[ $i + 2 ] ?? '' ) ) {
					$state['comment'] = 'block';
					++$i;
				} elseif ( ';' === $c ) {
					$sql       = trim( $statement . substr( $buffer, $start, $i - $start ) );
					$start     = $i + 1;
					$statement = '';
					if ( '' !== $sql ) {
						self::run( $sql, $cursor );
					}
					$consumed = $offset + $start;
					if ( microtime( true ) >= $deadline ) {
						break 2;
					}
				}
			}
			// Keep the unfinished statement; drop what was consumed.
			$statement .= substr( $buffer, $start );
			$offset    += strlen( $buffer );
			$buffer     = '';
		}
		if ( $gz ) {
			gzclose( $in );
		} else {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		if ( $eof && '' !== trim( self::strip_comments( $statement ) ) ) {
			self::run( trim( $statement ), $cursor );
		}
		$cursor['offset'] = $eof ? $offset : $consumed;
		$cursor['done']   = $eof;
		return $cursor;
	}

	/**
	 * Whether position `$i` starts a line (only whitespace before it on the line).
	 *
	 * @param string $buffer Buffer.
	 * @param int    $i      Position.
	 * @param int    $start  Start of the current statement in the buffer.
	 * @return bool
	 */
	private static function at_line_start( string $buffer, int $i, int $start ): bool {
		for ( $j = $i - 1; $j >= $start; $j-- ) {
			if ( "\n" === $buffer[ $j ] ) {
				return true;
			}
			if ( ' ' !== $buffer[ $j ] && "\t" !== $buffer[ $j ] && "\r" !== $buffer[ $j ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Remove leading comments from a statement fragment.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	private static function strip_comments( string $sql ): string {
		// Leading whitespace, `-- `/`#` line comments and plain `/* */` block comments (never
		// the `/*!40101 … */` conditional ones, which are statements).
		return trim( (string) preg_replace( '#^(?:\s+|--[^\n]*(?:\n|$)|\#[^\n]*(?:\n|$)|/\*(?!!).*?\*/)*#s', '', $sql ) );
	}

	/**
	 * Run one statement, rewritten for the temporary tables. Statements that could touch
	 * anything other than the dump's own tables are skipped.
	 *
	 * @param string              $sql    Statement.
	 * @param array<string,mixed> $cursor Cursor (counts are updated).
	 * @return void
	 * @throws \RuntimeException When the database refuses a statement.
	 */
	private static function run( string $sql, array &$cursor ): void {
		global $wpdb;
		$sql = self::strip_comments( $sql );
		if ( '' === $sql ) {
			return;
		}
		$verb = strtoupper( (string) strtok( ltrim( $sql, '(/*!0123456789 ' ), " \n\t(" ) );
		if ( preg_match( '#^/\*!\d+\s+(SET|ALTER)#i', $sql, $m ) ) {
			$verb = strtoupper( $m[1] );
		}
		switch ( $verb ) {
			case 'SET':
				if ( preg_match( '/SQL_LOG_BIN|GTID_PURGED|@@GLOBAL/i', $sql ) ) {
					return;
				}
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- a session SET from the dump the administrator chose to restore.
				return;
			case 'CREATE':
				if ( ! preg_match( '/^CREATE\s+TABLE/i', $sql ) ) {
					return; // Views, triggers, procedures, databases: not ours to create.
				}
				break;
			case 'INSERT':
			case 'REPLACE':
			case 'DROP':
			case 'ALTER':
				break;
			default:
				return; // LOCK/UNLOCK TABLES, USE, START TRANSACTION, COMMIT and the like.
		}
		$rewritten = self::rewrite_table( $sql, (string) $cursor['from'], (string) $cursor['to'] );
		if ( null === $rewritten ) {
			return;
		}
		if ( 'CREATE' === $verb ) {
			$rewritten = self::compatible_collation( $rewritten );
			if ( preg_match( '/^CREATE\s+TABLE\s+(?:IF NOT EXISTS\s+)?`([^`]+)`/i', $rewritten, $m ) ) {
				$cursor['tables'][ $m[1] ] = true;
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $m[1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- a temporary table of this restore.
			}
		}
		// ⛔ `check_current_query = false`: otherwise wpdb re-reads every non-ASCII INSERT
		// through `SELECT CONVERT(...)` to strip "invalid" text — twice the work, and it would
		// alter the data being restored.
		$wpdb->check_current_query = false;
		$quiet                     = $wpdb->suppress_errors( true );
		$ok                        = $wpdb->query( $rewritten ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- a statement from the dump the administrator chose to restore, rewritten onto temporary tables.
		$wpdb->suppress_errors( $quiet );
		if ( false === $ok ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: database error, 2: start of the statement. */
					esc_html__( 'The database refused a statement from the backup: %1$s (%2$s…)', 'zinn-backups' ),
					esc_html( $wpdb->last_error ),
					esc_html( substr( $sql, 0, 80 ) )
				)
			);
		}
		$cursor['statements'] = (int) ( $cursor['statements'] ?? 0 ) + 1;
	}

	/**
	 * Rewrite the table name of a statement from the dump prefix to the target prefix, or
	 * return null when the table is not one of the dump's WordPress tables.
	 *
	 * @param string $sql  Statement.
	 * @param string $from Dump prefix.
	 * @param string $to   Target prefix (temporary + site).
	 * @return string|null
	 */
	public static function rewrite_table( string $sql, string $from, string $to ): ?string {
		$pattern = '/^((?:\/\*!\d+\s+)?(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT(?:\s+IGNORE)?(?:\s+INTO)?|REPLACE(?:\s+INTO)?|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|ALTER\s+TABLE)\s+)`?([A-Za-z0-9_$]+)`?/i';
		if ( ! preg_match( $pattern, $sql, $m ) ) {
			return null;
		}
		$table = $m[2];
		if ( '' !== $from && ! str_starts_with( $table, $from ) ) {
			return null;
		}
		$new = $to . substr( $table, strlen( $from ) );
		if ( strlen( $new ) > 64 ) {
			return null;
		}
		return $m[1] . '`' . $new . '`' . substr( $sql, strlen( $m[0] ) );
	}

	/**
	 * Swap MySQL 8 collations for ones a MariaDB or older MySQL server knows.
	 *
	 * @param string $sql A CREATE TABLE statement.
	 * @return string
	 */
	private static function compatible_collation( string $sql ): string {
		static $has_0900 = null;
		if ( null === $has_0900 ) {
			global $wpdb;
			$has_0900 = (bool) $wpdb->get_var( "SHOW COLLATION LIKE 'utf8mb4_0900_ai_ci'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- capability probe.
		}
		if ( ! $has_0900 ) {
			$sql = (string) preg_replace( '/utf8mb4_0900_[a-z_]+/', 'utf8mb4_unicode_520_ci', $sql );
		}
		return $sql;
	}

	/**
	 * Session settings for a restore.
	 *
	 * @return void
	 */
	private static function session(): void {
		global $wpdb;
		$wpdb->query( 'SET foreign_key_checks = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- restore session.
		$wpdb->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- restore session.
		$wpdb->query( 'SET NAMES utf8mb4' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- restore session.
	}

	/**
	 * Whether a file is gzip-compressed (by its magic bytes, not its name).
	 *
	 * @param string $file File.
	 * @return bool
	 */
	public static function is_gzip( string $file ): bool {
		$head = (string) file_get_contents( $file, false, null, 0, 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- two magic bytes of a local file.
		return "\x1f\x8b" === $head;
	}

	/**
	 * The first bytes of a dump, decompressed.
	 *
	 * @param string $file  File.
	 * @param int    $bytes How many.
	 * @return string
	 */
	private static function head( string $file, int $bytes ): string {
		if ( self::is_gzip( $file ) ) {
			$gz   = gzopen( $file, 'rb' );
			$data = (string) gzread( $gz, $bytes );
			gzclose( $gz );
			return $data;
		}
		return (string) file_get_contents( $file, false, null, 0, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- head of a local file.
	}
}
