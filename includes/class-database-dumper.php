<?php
/**
 * Writes the site's tables to a gzip-compressed SQL file, a window of rows at a time.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * ⛔ Written through `$wpdb`, never `mysqldump`: most shared hosts have no `mysqldump` and
 * disable `exec`, and this plugin must work on exactly those hosts.
 *
 * ⭐ Rows are read by primary key when the table has a single integer key (each window is an
 * index range, so a million-row table costs the same per window at the end as at the start),
 * and by LIMIT/OFFSET otherwise.
 *
 * ⛔ Binary columns are written as hex literals: a blob in a quoted string passes through the
 * connection character set and comes back changed.
 */
final class Database_Dumper {

	/** Rows read per query. */
	private const ROWS = 1000;

	/** Bytes per INSERT statement before a new one starts (well under `max_allowed_packet`). */
	private const STATEMENT_BYTES = 1048576;

	/**
	 * Tables to dump: every table with this site's prefix.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		global $wpdb;
		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW FULL TABLES WHERE `Tables_in_' . DB_NAME . '` LIKE %s AND Table_type = %s', $like, 'BASE TABLE' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- listing tables; the column name is the database name.
		if ( empty( $tables ) ) {
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- listing tables.
		}
		/**
		 * Filters the tables a backup includes.
		 *
		 * @param string[] $tables Table names.
		 */
		return array_values( (array) apply_filters( 'zinn_backups_tables', (array) $tables ) );
	}

	/**
	 * Dump until the deadline.
	 *
	 * @param string              $file     The .sql.gz file to append to.
	 * @param array<string,mixed> $cursor   Where we are: `tables`, `table`, `after`, `offset`, `rows`.
	 * @param float               $deadline microtime deadline.
	 * @return array<string,mixed> Updated cursor; `done` true when every table is written.
	 * @throws \RuntimeException On a failure.
	 */
	public static function dump( string $file, array $cursor, float $deadline ): array {
		global $wpdb;
		$tables = (array) $cursor['tables'];
		$gz     = gzopen( $file, 'ab6' );
		if ( false === $gz ) {
			throw new \RuntimeException( esc_html__( 'The database file could not be written. Check that wp-content has free space.', 'zinn-backups' ) );
		}
		try {
			if ( 0 === (int) $cursor['table'] && ! isset( $cursor['header'] ) ) {
				gzwrite( $gz, "-- Zinn® Backups database dump\n-- Table prefix: " . $wpdb->prefix . "\n-- Site: " . home_url( '/' ) . "\nSET NAMES utf8mb4;\nSET foreign_key_checks = 0;\nSET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n\n" );
				$cursor['header'] = true;
			}
			$count = count( $tables );
			while ( (int) $cursor['table'] < $count && microtime( true ) < $deadline ) {
				$table = (string) $tables[ (int) $cursor['table'] ];
				if ( empty( $cursor['started'] ) ) {
					$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading the schema.
					if ( empty( $create[1] ) ) {
						++$cursor['table'];
						continue;
					}
					gzwrite( $gz, 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $create[1] . ";\n" );
					$cursor['started'] = true;
					$cursor['after']   = null;
					$cursor['offset']  = 0;
					$cursor['meta']    = self::table_meta( $table );
				}
				$rows = self::window( $table, (array) $cursor['meta'], $cursor['after'], (int) $cursor['offset'] );
				if ( empty( $rows ) ) {
					gzwrite( $gz, "\n" );
					++$cursor['table'];
					$cursor['started'] = false;
					continue;
				}
				self::write_inserts( $gz, $table, $rows, (array) $cursor['meta'] );
				$cursor['rows']   = (int) ( $cursor['rows'] ?? 0 ) + count( $rows );
				$cursor['offset'] = (int) $cursor['offset'] + count( $rows );
				if ( null !== $cursor['meta']['key'] ) {
					$last            = end( $rows );
					$cursor['after'] = $last[ (int) $cursor['meta']['key_index'] ];
				}
			}
		} finally {
			gzclose( $gz );
		}
		$cursor['done'] = (int) $cursor['table'] >= count( $tables );
		return $cursor;
	}

	/**
	 * Columns, which are binary, and the single integer primary key if there is one.
	 *
	 * @param string $table Table.
	 * @return array{columns:string[],binary:bool[],key:?string,key_index:int}
	 */
	private static function table_meta( string $table ): array {
		global $wpdb;
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading the schema.
		$names   = array();
		$binary  = array();
		$keys    = array();
		foreach ( (array) $columns as $index => $column ) {
			$names[]  = (string) $column['Field'];
			$type     = strtolower( (string) $column['Type'] );
			$binary[] = (bool) preg_match( '/^(tiny|medium|long)?blob|^(var)?binary|^bit/', $type );
			if ( 'PRI' === $column['Key'] ) {
				$keys[] = array( $index, (string) $column['Field'], $type );
			}
		}
		$key = null;
		$pos = 0;
		if ( 1 === count( $keys ) && preg_match( '/int/', $keys[0][2] ) ) {
			$key = $keys[0][1];
			$pos = $keys[0][0];
		}
		return array(
			'columns'   => $names,
			'binary'    => $binary,
			'key'       => $key,
			'key_index' => $pos,
		);
	}

	/**
	 * The next window of rows.
	 *
	 * @param string          $table  Table.
	 * @param array           $meta   Table meta.
	 * @param int|string|null $after  Last key written (keyset paging).
	 * @param int             $offset Rows written (offset paging).
	 * @return array<int,array<int,string|null>>
	 */
	private static function window( string $table, array $meta, $after, int $offset ): array {
		global $wpdb;
		if ( null !== $meta['key'] ) {
			if ( null === $after ) {
				return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY %i ASC LIMIT %d', $table, $meta['key'], self::ROWS ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dumping rows.
			}
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %d ORDER BY %i ASC LIMIT %d', $table, $meta['key'], (int) $after, $meta['key'], self::ROWS ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dumping rows.
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d, %d', $table, $offset, self::ROWS ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dumping rows.
	}

	/**
	 * Write rows as INSERT statements of bounded size.
	 *
	 * @param resource $gz    Open gzip handle.
	 * @param string   $table Table.
	 * @param array    $rows  Rows.
	 * @param array    $meta  Table meta.
	 * @return void
	 */
	private static function write_inserts( $gz, string $table, array $rows, array $meta ): void {
		$head  = 'INSERT INTO `' . $table . '` (`' . implode( '`,`', $meta['columns'] ) . '`) VALUES ';
		$parts = array();
		$bytes = 0;
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $row as $index => $value ) {
				$values[] = self::literal( $value, ! empty( $meta['binary'][ $index ] ) );
			}
			$tuple   = '(' . implode( ',', $values ) . ')';
			$parts[] = $tuple;
			$bytes  += strlen( $tuple );
			if ( $bytes >= self::STATEMENT_BYTES ) {
				gzwrite( $gz, $head . implode( ",\n", $parts ) . ";\n" );
				$parts = array();
				$bytes = 0;
			}
		}
		if ( $parts ) {
			gzwrite( $gz, $head . implode( ",\n", $parts ) . ";\n" );
		}
	}

	/**
	 * An SQL literal for a value.
	 *
	 * @param string|null $value  Value.
	 * @param bool        $binary Whether the column is binary.
	 * @return string
	 */
	public static function literal( ?string $value, bool $binary = false ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( $binary ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		return "'" . strtr(
			$value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		) . "'";
	}
}
