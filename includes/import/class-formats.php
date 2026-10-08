<?php
/**
 * Recognises backups made by other plugins, and says where each file inside them goes.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups\Import;

defined( 'ABSPATH' ) || exit;

/**
 * What each one looks like (`docs/977` §5, checked against real archives in `wp/tests/fixtures`):
 *
 * - **All-in-One WP Migration** — one `.wpress` file; `database.sql` uses the table prefix
 *   placeholder `SERVMASK_PREFIX_`; the rest is relative to `wp-content`.
 * - **UpdraftPlus** — `backup_<date>_<site>_<hash>-db.gz` plus `-plugins.zip`, `-themes.zip`,
 *   `-uploads.zip`, `-others.zip`, each relative to `wp-content`.
 * - **Duplicator** — one zip of the whole site with `dup-installer/dup-database__*.sql`.
 * - **BackWPup** — a zip/tar(.gz) of the site with the dump at its top level.
 * - **WPvivid** — zips of the site with the dump zipped beside them.
 * - **Jetpack VaultPress Backup / BlogVault** — a tar.gz or zip of the site with one or more
 *   `.sql` files.
 * - **Zinn® Migrate** — one zip with `site/` and `database.sql`.
 *
 * Everything lands in `wp-content` only: WordPress's own files and `wp-config.php` on this
 * site are never replaced by an import, and neither are cache drop-ins, which belong to the
 * old host's server.
 */
final class Formats {

	/**
	 * Paths (relative to wp-content) never written by an import.
	 */
	private const SKIP = array(
		'plugins/zinn-backups/',
		'plugins/zinn-backups-pro/',
		'cache/',
		'upgrade/',
		'ai1wm-backups/',
		'updraft/',
		'backups-dup-lite/',
		'backups-dup-pro/',
		'wpvividbackups/',
		'litespeed/',
		'zinn-backups-',
		'object-cache.php',
		'advanced-cache.php',
		'db.php',
		'maintenance.php',
	);

	/**
	 * Identify one file.
	 *
	 * @param string $path Local file.
	 * @param string $name Original file name.
	 * @return array{kind:string,format:string,label:string,role:string}
	 * @throws \RuntimeException For a format we know and cannot read.
	 */
	public static function identify( string $path, string $name ): array {
		$lower = strtolower( $name );
		if ( str_ends_with( $lower, '.daf' ) ) {
			throw new \RuntimeException( esc_html__( 'This is a Duplicator “DupArchive” (.daf) file. In Duplicator, build the package again with the archive format set to ZIP, then import that.', 'zinn-backups' ) );
		}
		if ( str_ends_with( $lower, '.crypt' ) ) {
			throw new \RuntimeException( esc_html__( 'This UpdraftPlus database backup is encrypted. Decrypt it in UpdraftPlus (or turn off database encryption and back up again), then import it.', 'zinn-backups' ) );
		}
		$kind = Archive_Reader::kind( $path );
		if ( 'sql' === $kind || ( 'gz' === $kind && preg_match( '/(\.sql\.gz|-db\d*\.gz)$/', $lower ) ) ) {
			return array(
				'kind'   => $kind,
				'format' => 'sql',
				'label'  => preg_match( '/^backup_\d{4}-\d{2}-\d{2}-\d{4}_/', $lower ) ? 'UpdraftPlus' : __( 'Database dump', 'zinn-backups' ),
				'role'   => 'sql',
			);
		}
		if ( 'wpress' === $kind ) {
			self::refuse_packed_wpress( $path );
			return array(
				'kind'   => $kind,
				'format' => 'wpress',
				'label'  => 'All-in-One WP Migration',
				'role'   => 'archive',
			);
		}
		if ( ! in_array( $kind, array( 'zip', 'tar', 'tgz' ), true ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: file name. */
					esc_html__( '%s is not a backup archive we can read (zip, tar, tar.gz, .wpress, .sql or .sql.gz).', 'zinn-backups' ),
					esc_html( $name )
				)
			);
		}
		$names = Archive_Reader::names( $path, $kind );
		$label = __( 'Website archive', 'zinn-backups' );
		$fmt   = 'site';
		$has   = static fn( string $needle ): bool => (bool) array_filter( $names, static fn( string $n ): bool => str_contains( $n, $needle ) );
		if ( preg_match( '/^backup_\d{4}-\d{2}-\d{2}-\d{4}_.*-(plugins|themes|uploads|others|mu-plugins|more)\d*\.zip$/', $lower ) ) {
			$label = 'UpdraftPlus';
			$fmt   = 'content';
		} elseif ( $has( 'dup-installer/' ) ) {
			$label = 'Duplicator';
		} elseif ( $has( 'backwpup_readme.txt' ) || ( $has( 'manifest.json' ) && str_contains( $lower, 'backwpup' ) ) ) {
			$label = 'BackWPup';
		} elseif ( $has( 'wpvivid_package_info.json' ) || str_contains( $lower, 'wpvivid' ) ) {
			$label = 'WPvivid';
		} elseif ( in_array( 'database.sql', $names, true ) && $has( 'site/' ) ) {
			$label = 'Zinn® Migrate';
			$fmt   = 'zinn-migrate';
		} elseif ( $has( 'sql/' ) && $has( 'wp-content/' ) ) {
			$label = 'Jetpack VaultPress Backup';
		} elseif ( str_contains( $lower, 'blogvault' ) || str_contains( $lower, 'bv_' ) ) {
			$label = 'BlogVault';
		}
		if ( 'site' === $fmt && ! $has( 'wp-content/' ) && ! array_filter( $names, static fn( string $n ): bool => 1 === preg_match( '#^(plugins|themes|uploads|mu-plugins)/#', $n ) ) && ! array_filter( $names, array( self::class, 'is_dump_name' ) ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: file name. */
					esc_html__( '%s has no WordPress content (no wp-content folder and no database dump) in it.', 'zinn-backups' ),
					esc_html( $name )
				)
			);
		}
		return array(
			'kind'   => $kind,
			'format' => $fmt,
			'label'  => $label,
			'role'   => 'archive',
		);
	}

	/**
	 * Refuse a `.wpress` whose contents are encrypted or compressed: its `package.json` (always
	 * the first entry, never itself encrypted) says so.
	 *
	 * @param string $path The archive.
	 * @return void
	 * @throws \RuntimeException When the archive is encrypted or compressed.
	 */
	private static function refuse_packed_wpress( string $path ): void {
		$head = (string) file_get_contents( $path, false, null, 0, 4377 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- first header of a local archive.
		if ( 'package.json' !== rtrim( substr( $head, 0, 255 ), "\0" ) ) {
			return;
		}
		$size    = (int) rtrim( substr( $head, 255, 14 ), "\0" );
		$package = json_decode( (string) file_get_contents( $path, false, null, 4377, min( $size, 1048576 ) ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- see above.
		if ( ! is_array( $package ) ) {
			return;
		}
		if ( ! empty( $package['Encrypted'] ) || ! empty( $package['Compression']['Enabled'] ) ) {
			throw new \RuntimeException( esc_html__( 'This .wpress file is encrypted or compressed by All-in-One WP Migration. Export it again with encryption and compression switched off, then import that.', 'zinn-backups' ) );
		}
	}

	/**
	 * Whether an entry is a database dump (not an `.sql` file that is part of some plugin).
	 *
	 * @param string $name Entry name.
	 * @return bool
	 */
	public static function is_dump_name( string $name ): bool {
		$name = strtolower( str_replace( '\\', '/', $name ) );
		if ( ! preg_match( '/\.sql(\.gz)?$/', $name ) || str_contains( $name, 'wp-content/' ) || preg_match( '#^(plugins|themes|uploads|mu-plugins)/#', $name ) ) {
			return false;
		}
		return substr_count( $name, '/' ) <= 3;
	}

	/**
	 * The router for an archive format: entry name → absolute target, or null to skip.
	 *
	 * @param string      $format  site|content|wpress|zinn-migrate.
	 * @param string      $sql_dir Where database dumps are collected.
	 * @param string[]    $dumps   Collected dumps (appended to).
	 * @param int         $skipped Count of skipped entries (incremented).
	 * @param string|null $content wp-content folder (default: this site's).
	 * @return callable
	 */
	public static function router( string $format, string $sql_dir, array &$dumps, int &$skipped, ?string $content = null ): callable {
		$content = untrailingslashit( wp_normalize_path( $content ?? WP_CONTENT_DIR ) );
		return static function ( string $name ) use ( $format, $sql_dir, &$dumps, &$skipped, $content ): ?string {
			$name = (string) preg_replace( '#^(\./)+#', '', str_replace( '\\', '/', $name ) );
			if ( 'zinn-migrate' === $format ) {
				if ( 'database.sql' === $name ) {
					return $dumps[] = $sql_dir . '/' . count( $dumps ) . '-database.sql'; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found -- record and return.
				}
				if ( ! str_starts_with( $name, 'site/' ) ) {
					return null;
				}
				$name = substr( $name, 5 );
			}
			if ( 'wpress' === $format ) {
				if ( 'database.sql' === $name ) {
					return $dumps[] = $sql_dir . '/' . count( $dumps ) . '-database.sql'; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found -- record and return.
				}
				if ( 'package.json' === $name ) {
					return $sql_dir . '/package.json';
				}
				if ( ! str_contains( $name, '/' ) && str_ends_with( $name, '.json' ) ) {
					return null; // multisite.json, blogs.json: metadata.
				}
				$relative = $name;
			} elseif ( self::is_dump_name( $name ) && 'content' !== $format ) {
				return $dumps[] = $sql_dir . '/' . count( $dumps ) . '-' . sanitize_file_name( basename( $name ) ); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found -- record and return.
			} elseif ( 'content' === $format ) {
				$relative = $name;
			} else {
				$at = strpos( '/' . $name, '/wp-content/' );
				if ( false !== $at ) {
					$relative = substr( '/' . $name, $at + strlen( '/wp-content/' ) );
				} elseif ( preg_match( '#^(plugins|themes|uploads|mu-plugins|languages)/#', $name ) ) {
					$relative = $name;
				} else {
					return null; // WordPress core, wp-config.php, installer files: not imported.
				}
			}
			foreach ( self::SKIP as $skip ) {
				if ( rtrim( $skip, '/' ) === $relative || str_starts_with( $relative, $skip ) ) {
					++$skipped;
					return null;
				}
			}
			return '' === $relative ? null : $content . '/' . $relative;
		};
	}
}
