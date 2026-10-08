<?php
/**
 * Work on the restored (temporary) tables, then swap them in.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * Address rewriting, prefix-bound keys, the settings that must survive, and the swap.
 */
final class Table_Swap {

	/** Rows per search-replace query. */
	private const ROWS = 500;

	/**
	 * The site address recorded in a restored options table.
	 *
	 * @param string $temp_prefix Temporary + site prefix.
	 * @param string $name        `home` or `siteurl`.
	 * @return string
	 */
	public static function option( string $temp_prefix, string $name ): string {
		global $wpdb;
		$table = $temp_prefix . 'options';
		if ( ! self::exists( $table ) ) {
			return '';
		}
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $table, $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- reading a restored table.
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table.
	 * @return bool
	 */
	public static function exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema probe.
	}

	/**
	 * Search and replace through the temporary tables until the deadline.
	 *
	 * @param string[]             $tables   Temporary tables.
	 * @param array<string,mixed>  $cursor   `table` index, `after` last key, `changed`.
	 * @param array<string,string> $pairs    Replacements.
	 * @param string               $posts    The temporary posts table (its `guid` is left alone).
	 * @param float                $deadline microtime deadline.
	 * @return array<string,mixed>
	 */
	public static function replace( array $tables, array $cursor, array $pairs, string $posts, float $deadline ): array {
		global $wpdb;
		$searches = array_keys( $pairs );
		$count    = count( $tables );
		while ( (int) $cursor['table'] < $count && microtime( true ) < $deadline ) {
			$table = (string) $tables[ (int) $cursor['table'] ];
			$meta  = self::text_columns( $table, $table === $posts );
			if ( null === $meta['key'] || ! $meta['columns'] ) {
				self::replace_in_sql( $table, $meta['columns'], $pairs );
				++$cursor['table'];
				$cursor['after'] = null;
				continue;
			}
			$where        = array();
			$where_params = array();
			foreach ( $meta['columns'] as $column ) {
				foreach ( $searches as $search ) {
					$where[]        = '%i LIKE %s';
					$where_params[] = $column;
					$where_params[] = '%' . $wpdb->esc_like( (string) $search ) . '%';
				}
			}
			$select = array_merge( array( $meta['key'] ), $meta['columns'] );
			$sql    = 'SELECT ' . implode( ', ', array_fill( 0, count( $select ), '%i' ) ) . ' FROM %i WHERE ';
			$params = array_merge( $select, array( $table ) );
			if ( null !== ( $cursor['after'] ?? null ) ) {
				$sql     .= '%i > %s AND ';
				$params[] = $meta['key'];
				$params[] = (string) $cursor['after'];
			}
			$sql   .= '(' . implode( ' OR ', $where ) . ') ORDER BY %i LIMIT %d';
			$params = array_merge( $params, $where_params, array( $meta['key'], self::ROWS ) );
			$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- built from %i/%s placeholders only, then prepared.
			if ( empty( $rows ) ) {
				++$cursor['table'];
				$cursor['after'] = null;
				continue;
			}
			foreach ( $rows as $row ) {
				$update = array();
				foreach ( $meta['columns'] as $column ) {
					if ( null === $row[ $column ] ) {
						continue;
					}
					$new = Serialized_Replace::replace( (string) $row[ $column ], $pairs );
					if ( $new !== $row[ $column ] ) {
						$update[ $column ] = $new;
					}
				}
				if ( $update ) {
					$wpdb->update( $table, $update, array( $meta['key'] => $row[ $meta['key'] ] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rewriting a restored table.
					$cursor['changed'] = (int) ( $cursor['changed'] ?? 0 ) + 1;
				}
				$cursor['after'] = $row[ $meta['key'] ];
			}
			if ( count( $rows ) < self::ROWS ) {
				++$cursor['table'];
				$cursor['after'] = null;
			}
		}
		$cursor['done'] = (int) $cursor['table'] >= count( $tables );
		return $cursor;
	}

	/**
	 * For a table without a single-column key: plain SQL REPLACE on non-serialized values.
	 *
	 * @param string               $table   Table.
	 * @param string[]             $columns Text columns.
	 * @param array<string,string> $pairs   Pairs.
	 * @return void
	 */
	private static function replace_in_sql( string $table, array $columns, array $pairs ): void {
		global $wpdb;
		foreach ( $columns as $column ) {
			foreach ( $pairs as $search => $replace ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i = REPLACE(%i, %s, %s) WHERE %i LIKE %s AND %i NOT REGEXP %s', $table, $column, $column, $search, $replace, $column, '%' . $wpdb->esc_like( (string) $search ) . '%', $column, '^(a|O|s):[0-9]+:' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rewriting a restored table.
			}
		}
	}

	/**
	 * Text columns and the single primary key (any type) of a table.
	 *
	 * @param string $table     Table.
	 * @param bool   $skip_guid Leave out `guid` (WordPress says a post's GUID never changes).
	 * @return array{key:?string,columns:string[]}
	 */
	private static function text_columns( string $table, bool $skip_guid ): array {
		global $wpdb;
		$columns = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema.
		$keys    = array();
		$text    = array();
		foreach ( $columns as $column ) {
			if ( 'PRI' === $column['Key'] ) {
				$keys[] = (string) $column['Field'];
			}
			if ( preg_match( '/char|text|json/i', (string) $column['Type'] ) && ! ( $skip_guid && 'guid' === $column['Field'] ) ) {
				$text[] = (string) $column['Field'];
			}
		}
		return array(
			'key'     => 1 === count( $keys ) ? $keys[0] : null,
			'columns' => $text,
		);
	}

	/**
	 * Fix names that carry the table prefix inside the data (`wp_user_roles`, `wp_capabilities`).
	 *
	 * @param string $temp_prefix Temporary + site prefix.
	 * @param string $from        The dump's prefix.
	 * @param string $to          This site's prefix.
	 * @return void
	 */
	public static function fix_prefixed_keys( string $temp_prefix, string $from, string $to ): void {
		global $wpdb;
		if ( $from === $to || '' === $from ) {
			return;
		}
		$like = $wpdb->esc_like( $from ) . '%';
		if ( self::exists( $temp_prefix . 'options' ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_name = CONCAT(%s, SUBSTRING(option_name, %d)) WHERE option_name LIKE %s', $temp_prefix . 'options', $to, strlen( $from ) + 1, $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rewriting a restored table.
		}
		if ( self::exists( $temp_prefix . 'usermeta' ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s', $temp_prefix . 'usermeta', $to, strlen( $from ) + 1, $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rewriting a restored table.
		}
	}

	/**
	 * Carry this site's own settings for this plugin (and Pro) across the restore, and keep
	 * the plugin active, so the person is not left disconnected from their storage.
	 *
	 * @param string $temp_prefix Temporary + site prefix.
	 * @return void
	 */
	public static function preserve( string $temp_prefix ): void {
		global $wpdb;
		$temp = $temp_prefix . 'options';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'zinn_backups' ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- copying our settings across the restore.
		foreach ( (array) $rows as $row ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $temp, $row['option_name'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above.
			$wpdb->insert( $temp, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above.
		}
		$active  = maybe_unserialize( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $temp, 'active_plugins' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above.
		$active  = is_array( $active ) ? $active : array();
		$current = (array) get_option( 'active_plugins', array() );
		foreach ( $current as $plugin ) {
			if ( str_starts_with( (string) $plugin, 'zinn-backups' ) && ! in_array( $plugin, $active, true ) ) {
				$active[] = $plugin;
			}
		}
		// A restored plugin list may name plugins whose files are not on this site; WordPress
		// deactivates those itself on the next admin load.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $temp, 'active_plugins' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- see above.
			$temp,
			array(
				'option_name'  => 'active_plugins',
				'option_value' => maybe_serialize( array_values( $active ) ),
				'autoload'     => 'yes',
			)
		);
	}

	/**
	 * Swap the temporary tables in, in one atomic RENAME, then drop the old ones.
	 *
	 * @param string   $temp_prefix Temporary + site prefix.
	 * @param string[] $tables      Temporary tables.
	 * @param string   $old_prefix  Prefix the replaced tables are moved to before being dropped.
	 * @return int Tables swapped in.
	 * @throws \RuntimeException When the restored set has no WordPress core tables.
	 */
	public static function swap( string $temp_prefix, array $tables, string $old_prefix ): int {
		global $wpdb;
		foreach ( array( 'options', 'users', 'posts' ) as $core ) {
			if ( ! in_array( $temp_prefix . $core, $tables, true ) ) {
				throw new \RuntimeException( esc_html__( 'The backup’s database has no WordPress tables, so nothing was replaced.', 'zinn-backups' ) );
			}
		}
		$renames = array();
		$drop    = array();
		foreach ( $tables as $temp ) {
			$live = $wpdb->prefix . substr( $temp, strlen( $temp_prefix ) );
			if ( self::exists( $live ) ) {
				$old       = substr( $old_prefix . $live, 0, 64 );
				$renames[] = $wpdb->prepare( '%i TO %i', $live, $old );
				$drop[]    = $old;
			}
			$renames[] = $wpdb->prepare( '%i TO %i', $temp, $live );
		}
		$ok = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- each pair was prepared with %i; one statement so the swap is atomic.
		if ( false === $ok ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: database error. */
					esc_html__( 'The restored tables could not be swapped in: %s. Your site is unchanged.', 'zinn-backups' ),
					esc_html( $wpdb->last_error )
				)
			);
		}
		foreach ( $drop as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- the replaced tables.
		}
		wp_cache_flush();
		return count( $tables );
	}

	/**
	 * Drop leftover temporary tables (a failed or cancelled restore).
	 *
	 * @param string $prefix Temporary prefix.
	 * @return void
	 */
	public static function drop_prefixed( string $prefix ): void {
		global $wpdb;
		if ( strlen( $prefix ) < 5 ) {
			return;
		}
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) as $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- our own temporary tables.
		}
	}
}
