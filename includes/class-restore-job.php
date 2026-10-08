<?php
/**
 * Restore one of our backups, or import an archive made by another plugin.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

use ZinnDigital\Backups\Import\Archive_Reader;
use ZinnDigital\Backups\Import\Formats;
use ZinnDigital\Backups\Storage\Destination;
use ZinnDigital\Backups\Storage\Destinations;
use ZinnDigital\Backups\Storage\Url_Source;

defined( 'ABSPATH' ) || exit;

/**
 * Two ways in, one way out:
 *
 * Backup:  fetch → files (download, check, decrypt, unpack each part) → db-fetch → database
 * Import:  receive (download links) → detect → extract → database
 * Then:    replace (new address and folder) → swap → done
 *
 * ⛔⛔ THE DATABASE IS SWAPPED, NEVER OVERWRITTEN: the dump goes into temporary tables and
 * one atomic `RENAME TABLE` puts them in place at the very end ({@see Table_Swap}). A failed
 * or cancelled restore drops the temporary tables and the site's database is untouched.
 * Files are written in place as each part is unpacked (there is no room on a small host for
 * a second copy of the site); a restore never deletes files that are not in the backup.
 */
final class Restore_Job extends Job {

	/** Paths (relative to the WordPress root) a restore never writes. */
	private const NEVER = array( 'wp-config.php', '.user.ini', 'php.ini', 'wp-content/plugins/zinn-backups/', 'wp-content/plugins/zinn-backups-pro/' );

	/**
	 * Restore a backup from a saved destination or a deploy code's links.
	 *
	 * @param array<string,mixed> $args `destination` id or `urls` map, `remote` folder, `files`, `database`, `passphrase`.
	 * @return self
	 */
	public static function from_backup( array $args ): self {
		self::refuse_multisite();
		return new self(
			self::common( $args ) + array(
				'mode'        => 'backup',
				'destination' => (string) ( $args['destination'] ?? '' ),
				'urls'        => (array) ( $args['urls'] ?? array() ),
				'remote'      => trim( (string) $args['remote'], '/' ),
				'stage'       => 'fetch',
			)
		);
	}

	/**
	 * Import archives made by another plugin (or by Zinn® Migrate).
	 *
	 * @param array<int,array{path?:string,url?:string,name:string}> $inputs Local files in the job folder, or links.
	 * @param array<string,mixed>                                    $args   `id` (when files were already uploaded into its folder), `files`, `database`.
	 * @return self
	 */
	public static function from_import( array $inputs, array $args = array() ): self {
		self::refuse_multisite();
		return new self(
			self::common( $args ) + array(
				'mode'   => 'import',
				'inputs' => array_values( $inputs ),
				'input'  => 0,
				'stage'  => 'receive',
			)
		);
	}

	/**
	 * State both modes share.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string,mixed>
	 */
	private static function common( array $args ): array {
		global $wpdb;
		$tag = bin2hex( random_bytes( 2 ) );
		return array(
			'id'          => (string) ( $args['id'] ?? self::new_id() ),
			'files'       => $args['files'] ?? true,
			'database'    => $args['database'] ?? true,
			'passphrase'  => isset( $args['passphrase'] ) && '' !== $args['passphrase'] ? Secrets::seal( array( 'p' => (string) $args['passphrase'] ) ) : '',
			'tag'         => $tag,
			'temp_prefix' => 'zb' . $tag . '_' . $wpdb->prefix,
			'old_prefix'  => 'zo' . $tag . '_',
			'dumps'       => array(),
			'tables'      => array(),
			'from'        => array(),
			'work_name'   => Work_Dir::name(),
		);
	}

	/**
	 * Restoring into a multisite network is not supported.
	 *
	 * @return void
	 * @throws \RuntimeException On multisite.
	 */
	private static function refuse_multisite(): void {
		if ( is_multisite() ) {
			throw new \RuntimeException( esc_html__( 'Multisite networks are not supported yet.', 'zinn-backups' ) );
		}
	}

	/**
	 * The job's kind.
	 *
	 * @return string
	 */
	public function type(): string {
		return 'restore';
	}

	/**
	 * A human label for the current stage.
	 *
	 * @return string
	 */
	public function stage_label(): string {
		return match ( $this->state['stage'] ) {
			'fetch'    => __( 'Reading the backup', 'zinn-backups' ),
			'receive'  => __( 'Downloading the archive', 'zinn-backups' ),
			'detect'   => __( 'Recognising the archive', 'zinn-backups' ),
			'files', 'extract' => __( 'Restoring files', 'zinn-backups' ),
			'db-fetch' => __( 'Downloading the database', 'zinn-backups' ),
			'database' => __( 'Restoring the database', 'zinn-backups' ),
			'replace'  => __( 'Updating the site address', 'zinn-backups' ),
			'swap'     => __( 'Switching to the restored database', 'zinn-backups' ),
			default    => __( 'Restore', 'zinn-backups' ),
		};
	}

	/**
	 * Do work until the deadline or a stage change.
	 *
	 * @param float $deadline microtime deadline.
	 * @return void
	 * @throws \RuntimeException On a failure the job cannot continue from.
	 */
	public function step( float $deadline ): void {
		match ( $this->state['stage'] ) {
			'fetch'    => $this->do_fetch(),
			'files'    => $this->do_files( $deadline ),
			'db-fetch' => $this->do_db_fetch( $deadline ),
			'receive'  => $this->do_receive( $deadline ),
			'detect'   => $this->do_detect(),
			'extract'  => $this->do_extract( $deadline ),
			'database' => $this->do_database( $deadline ),
			'replace'  => $this->do_replace( $deadline ),
			'swap'     => $this->do_swap(),
			default    => $this->fail( __( 'The restore reached an unknown stage.', 'zinn-backups' ) ),
		};
	}

	/**
	 * Tidy up after a failure or cancellation.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		Table_Swap::drop_prefixed( 'zb' . $this->state['tag'] . '_' );
		parent::cleanup();
	}

	// ── backup mode ─────────────────────────────────────────────────────────────

	/**
	 * The storage client this restore reads from.
	 *
	 * @return Destination
	 * @throws \RuntimeException On a failure.
	 */
	private function source(): Destination {
		if ( ! empty( $this->state['urls'] ) ) {
			return new Url_Source( (array) $this->state['urls'] );
		}
		$record = Settings::destination( (string) $this->state['destination'] );
		if ( null === $record ) {
			throw new \RuntimeException( esc_html__( 'That storage destination is no longer saved on this site.', 'zinn-backups' ) );
		}
		return Destinations::client( $record );
	}

	/**
	 * Read the manifest(s) and plan which parts to unpack.
	 *
	 * @return void
	 * @throws \RuntimeException On a failure.
	 */
	private function do_fetch(): void {
		$source   = $this->source();
		$manifest = json_decode( $source->read_range( $this->state['remote'] . '/manifest.json', 0, 4194304 ), true );
		if ( ! is_array( $manifest ) || 'zinn-backups' !== ( $manifest['format'] ?? '' ) ) {
			throw new \RuntimeException( esc_html__( 'This folder has no complete Zinn® Backups backup in it (the manifest is missing).', 'zinn-backups' ) );
		}
		if ( ! empty( $manifest['encrypted'] ) && '' === $this->passphrase() ) {
			throw new \RuntimeException( esc_html__( 'This backup is encrypted. Enter its passphrase to restore it.', 'zinn-backups' ) );
		}
		$this->state['manifest'] = $manifest;
		$parent                  = dirname( $this->state['remote'] );
		$parts                   = array();
		if ( ! empty( $this->state['files'] ) && ! empty( $manifest['index'] ) ) {
			$index = $this->dir() . '/files-index.ndjson.gz';
			$this->download( $source, $this->state['remote'] . '/' . $manifest['index']['name'], $index, (array) $manifest['index'] );
			$manifests = array( $manifest['id'] => $manifest );
			foreach ( (array) ( $manifest['uses'] ?? array() ) as $other ) {
				$other_manifest = json_decode( $source->read_range( $parent . '/' . $other . '/manifest.json', 0, 4194304 ), true );
				if ( ! is_array( $other_manifest ) ) {
					throw new \RuntimeException(
						sprintf(
							/* translators: %s: backup id. */
							esc_html__( 'This incremental backup needs backup %s, which is no longer in the storage.', 'zinn-backups' ),
							esc_html( (string) $other )
						)
					);
				}
				$manifests[ (string) $other ] = $other_manifest;
			}
			// Group the wanted entries by the part that holds their current copy.
			$want = array();
			$gz   = gzopen( $index, 'rb' );
			while ( ! gzeof( $gz ) ) {
				$line = gzgets( $gz );
				$row  = is_string( $line ) ? json_decode( $line, true ) : null;
				if ( is_array( $row ) && 5 === count( $row ) ) {
					$want[ $row[3] . '/' . $row[4] ][] = $row[0];
				}
			}
			gzclose( $gz );
			$filtered = (bool) ( $manifest['uses'] ?? array() );
			foreach ( $want as $key => $names ) {
				list( $backup, $part_name ) = explode( '/', $key, 2 );
				$meta                       = null;
				foreach ( (array) ( $manifests[ $backup ]['parts'] ?? array() ) as $candidate ) {
					if ( (string) $candidate['name'] === $part_name || (string) $candidate['name'] === $part_name . '.zbenc' ) {
						$meta = $candidate;
					}
				}
				if ( null === $meta ) {
					continue;
				}
				$list = '';
				if ( $filtered ) {
					$list = $this->dir() . '/want-' . md5( $key ) . '.json';
					file_put_contents( $list, (string) wp_json_encode( array_fill_keys( $names, true ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a work file in our private folder.
				}
				$parts[] = array(
					'remote' => $parent . '/' . $backup . '/' . $meta['name'],
					'meta'   => $meta,
					'want'   => $list,
				);
			}
		}
		$this->state['parts'] = $parts;
		$this->state['part']  = 0;
		$this->log(
			sprintf(
				/* translators: 1: backup date, 2: number of parts. */
				__( 'Restoring the backup of %1$s (%2$d file parts).', 'zinn-backups' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $manifest['created'] ),
				count( $parts )
			)
		);
		$this->stage( $parts ? 'files' : 'db-fetch' );
	}

	/**
	 * Download, check and unpack the current part.
	 *
	 * @param float $deadline Deadline.
	 * @return void
	 */
	private function do_files( float $deadline ): void {
		$parts = (array) $this->state['parts'];
		$i     = (int) $this->state['part'];
		if ( $i >= count( $parts ) ) {
			$this->stage( 'db-fetch' );
			return;
		}
		$part  = $parts[ $i ];
		$local = $this->dir() . '/part.zip';
		if ( empty( $this->state['part_ready'] ) ) {
			$done = $this->download( $this->source(), (string) $part['remote'], $local, (array) $part['meta'], $deadline );
			if ( ! $done ) {
				return;
			}
			$this->state['part_ready'] = true;
			$this->state['walk']       = array();
		}
		$want                = '' !== $part['want'] ? (array) json_decode( (string) file_get_contents( $part['want'] ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our work file.
		$root                = trailingslashit( wp_normalize_path( ABSPATH ) );
		$same                = untrailingslashit( (string) ( $this->state['manifest']['site']['home'] ?? '' ) ) === untrailingslashit( home_url() );
		$route               = static function ( string $name ) use ( $want, $root, $same ): ?string {
			if ( null !== $want && ! isset( $want[ $name ] ) ) {
				return null;
			}
			foreach ( self::NEVER as $never ) {
				if ( rtrim( $never, '/' ) === $name || str_starts_with( $name, $never ) ) {
					return null;
				}
			}
			if ( ! $same && '.htaccess' === $name ) {
				return null; // Another site's server rules do not belong on this one.
			}
			return $root . $name;
		};
		$this->state['walk'] = Archive_Reader::walk( $local, 'zip', (array) $this->state['walk'], $route, $deadline );
		$this->progress( $i, count( $parts ) );
		if ( ! empty( $this->state['walk']['done'] ) ) {
			$this->state['restored_files'] = (int) ( $this->state['restored_files'] ?? 0 ) + (int) ( $this->state['walk']['written'] ?? 0 );
			$this->report_unwritable();
			wp_delete_file( $local );
			$this->state['part_ready'] = false;
			$this->state['part']       = $i + 1;
		}
	}

	/**
	 * Say which files the site's own user was not allowed to overwrite (they keep their
	 * current contents; everything else was restored).
	 *
	 * @return void
	 */
	private function report_unwritable(): void {
		$count = (int) ( $this->state['walk']['unwritable'] ?? 0 );
		if ( 0 === $count ) {
			return;
		}
		$this->state['unwritable'] = (int) ( $this->state['unwritable'] ?? 0 ) + $count;
		$this->log(
			sprintf(
				/* translators: 1: number of files, 2: example paths. */
				_n( '%1$s file could not be overwritten because this site is not allowed to change it, and was left as it is: %2$s', '%1$s files could not be overwritten because this site is not allowed to change them, and were left as they are: %2$s', $count, 'zinn-backups' ),
				number_format_i18n( $count ),
				implode( ', ', array_map( static fn( $p ): string => str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( (string) $p ) ), (array) ( $this->state['walk']['unwritable_paths'] ?? array() ) ) )
			),
			'warning'
		);
	}

	/**
	 * Download the database dump.
	 *
	 * @param float $deadline Deadline.
	 * @return void
	 */
	private function do_db_fetch( float $deadline ): void {
		if ( ! empty( $this->state['files'] ) ) {
			$this->log(
				sprintf(
					/* translators: %s: number of files. */
					__( 'Restored %s files.', 'zinn-backups' ),
					number_format_i18n( (int) ( $this->state['restored_files'] ?? 0 ) )
				)
			);
		}
		$db = $this->state['manifest']['database'] ?? null;
		if ( empty( $this->state['database'] ) || ! is_array( $db ) ) {
			$this->complete( false );
			return;
		}
		$local = $this->dir() . '/database.sql.gz';
		if ( ! $this->download( $this->source(), $this->state['remote'] . '/' . $db['name'], $local, $db, $deadline ) ) {
			return;
		}
		$this->state['dumps'] = array( $local );
		$this->state['input'] = 0;
		$this->stage( 'database' );
	}

	/**
	 * Download a remote file in chunks (resumable), check it, decrypt it if needed.
	 *
	 * @param Destination         $source   Storage.
	 * @param string              $remote   Remote path.
	 * @param string              $local    Local target (plain).
	 * @param array<string,mixed> $meta     `size`, `sha256`, `encrypted`.
	 * @param float|null          $deadline Deadline (null = finish now).
	 * @return bool Whether the file is complete.
	 * @throws \RuntimeException On a failure.
	 */
	private function download( Destination $source, string $remote, string $local, array $meta, ?float $deadline = null ): bool {
		$raw  = ! empty( $meta['encrypted'] ) ? $local . '.zbenc' : $local;
		$size = (int) ( $meta['size'] ?? 0 );
		$have = file_exists( $raw ) ? (int) filesize( $raw ) : 0;
		if ( ( $this->state['dl_remote'] ?? '' ) !== $remote ) {
			$this->state['dl_remote'] = $remote;
			$have                     = 0;
			if ( file_exists( $raw ) ) {
				wp_delete_file( $raw );
			}
		}
		while ( $have < $size ) {
			if ( null !== $deadline && microtime( true ) > $deadline - 2 ) {
				return false;
			}
			$chunk = $source->read_range( $remote, $have, Destination::CHUNK );
			if ( '' === $chunk ) {
				break;
			}
			file_put_contents( $raw, $chunk, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- appending a downloaded chunk to our work file.
			$have += strlen( $chunk );
			$this->progress( $have, $size );
		}
		clearstatcache( true, $raw );
		if ( ! file_exists( $raw ) ) {
			touch( $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- an empty file.
		}
		if ( (int) filesize( $raw ) !== $size || ( ! empty( $meta['sha256'] ) && ! hash_equals( (string) $meta['sha256'], (string) hash_file( 'sha256', $raw ) ) ) ) {
			wp_delete_file( $raw );
			$this->state['dl_remote'] = '';
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: file name. */
					esc_html__( '%s did not match its checksum after downloading. The copy in storage may be damaged.', 'zinn-backups' ),
					esc_html( basename( $remote ) )
				)
			);
		}
		if ( $raw !== $local ) {
			Crypto::decrypt_file( $raw, $local, $this->passphrase() );
			wp_delete_file( $raw );
		}
		$this->state['dl_remote'] = '';
		return true;
	}

	/**
	 * The passphrase given for this restore (or saved by Pro).
	 *
	 * @return string
	 */
	private function passphrase(): string {
		$given = '' !== $this->state['passphrase'] ? ( Secrets::open( (string) $this->state['passphrase'] )['p'] ?? '' ) : '';
		/**
		 * Filters the passphrase used to decrypt a backup. Zinn® Backups Pro supplies the
		 * one saved on this site when none was typed.
		 *
		 * @param string $given The passphrase typed for this restore ('' when none).
		 */
		return (string) apply_filters( 'zinn_backups_passphrase', $given );
	}

	// ── import mode ─────────────────────────────────────────────────────────────

	/**
	 * Download any inputs given as links.
	 *
	 * @param float $deadline Deadline.
	 * @return void
	 */
	private function do_receive( float $deadline ): void {
		$inputs = &$this->state['inputs'];
		foreach ( $inputs as $index => $input ) {
			if ( ! empty( $input['path'] ) || empty( $input['url'] ) ) {
				continue;
			}
			$target = $this->dir() . '/in-' . $index . '-' . sanitize_file_name( (string) $input['name'] );
			$done   = Downloader::fetch( (string) $input['url'], $target, $deadline, $this );
			if ( ! $done ) {
				unset( $inputs );
				return;
			}
			$inputs[ $index ]['path'] = $target;
		}
		unset( $inputs );
		$this->stage( 'detect' );
	}

	/**
	 * Recognise every input.
	 *
	 * @return void
	 * @throws \RuntimeException On a failure.
	 */
	private function do_detect(): void {
		$archives = array();
		$dumps    = array();
		$labels   = array();
		foreach ( (array) $this->state['inputs'] as $input ) {
			$path = (string) $input['path'];
			if ( ! is_readable( $path ) ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: %s: file name. */
						esc_html__( '%s did not arrive. Upload it again.', 'zinn-backups' ),
						esc_html( (string) $input['name'] )
					)
				);
			}
			$found    = Formats::identify( $path, (string) $input['name'] );
			$labels[] = $found['label'];
			if ( 'sql' === $found['role'] ) {
				$dumps[] = $path;
			} else {
				$archives[] = array(
					'path'   => $path,
					'kind'   => $found['kind'],
					'format' => $found['format'],
					'name'   => (string) $input['name'],
				);
			}
		}
		$labels = array_values( array_unique( $labels ) );
		$this->log(
			sprintf(
				/* translators: %s: list of backup formats. */
				__( 'Recognised: %s.', 'zinn-backups' ),
				implode( ', ', $labels )
			)
		);
		$this->state['source_label'] = implode( ', ', $labels );
		$this->state['archives']     = $archives;
		$this->state['dumps']        = $dumps;
		$this->state['archive']      = 0;
		$this->state['walk']         = array();
		$this->state['skipped']      = 0;
		wp_mkdir_p( $this->dir() . '/sql' );
		$this->stage( 'extract' );
	}

	/**
	 * Unpack the archives, routing content into wp-content and dumps into the job folder.
	 *
	 * @param float $deadline Deadline.
	 * @return void
	 */
	private function do_extract( float $deadline ): void {
		$archives = (array) $this->state['archives'];
		$i        = (int) $this->state['archive'];
		if ( $i >= count( $archives ) ) {
			$this->log(
				sprintf(
					/* translators: 1: number of files, 2: number of files skipped. */
					__( 'Imported %1$s files into wp-content (%2$s left out: caches, drop-ins and other backup plugins’ folders).', 'zinn-backups' ),
					number_format_i18n( (int) ( $this->state['restored_files'] ?? 0 ) ),
					number_format_i18n( (int) $this->state['skipped'] )
				)
			);
			$this->state['input'] = 0;
			$this->stage( 'database' );
			return;
		}
		$archive                = $archives[ $i ];
		$dumps                  = (array) $this->state['dumps'];
		$skipped                = (int) $this->state['skipped'];
		$files                  = ! empty( $this->state['files'] );
		$router                 = Formats::router( (string) $archive['format'], $this->dir() . '/sql', $dumps, $skipped );
		$route                  = static function ( string $name ) use ( $router, $files ): ?string {
			$target = $router( $name );
			if ( null === $target || $files ) {
				return $target;
			}
			return str_contains( $target, '/zinn-backups-' ) ? $target : null; // Database only: just the dumps.
		};
		$this->state['walk']    = Archive_Reader::walk( (string) $archive['path'], (string) $archive['kind'], (array) $this->state['walk'], $route, $deadline );
		$this->state['dumps']   = $dumps;
		$this->state['skipped'] = $skipped;
		$this->progress( $i, count( $archives ) );
		if ( ! empty( $this->state['walk']['done'] ) ) {
			$this->state['restored_files'] = (int) ( $this->state['restored_files'] ?? 0 ) + (int) ( $this->state['walk']['written'] ?? 0 );
			$this->report_unwritable();
			wp_delete_file( (string) $archive['path'] );
			$this->state['archive'] = $i + 1;
			$this->state['walk']    = array();
		}
	}

	// ── shared tail ─────────────────────────────────────────────────────────────

	/**
	 * Import every dump into the temporary tables.
	 *
	 * @param float $deadline Deadline.
	 * @return void
	 */
	private function do_database( float $deadline ): void {
		$dumps = array_values( array_filter( (array) $this->state['dumps'], static fn( $d ): bool => ! str_ends_with( (string) $d, 'package.json' ) ) );
		if ( empty( $this->state['database'] ) || ! $dumps ) {
			if ( 'import' === $this->state['mode'] && ! empty( $this->state['database'] ) && ! $dumps ) {
				$this->log( __( 'The archive has no database dump, so only files were imported.', 'zinn-backups' ), 'warning' );
			}
			$this->complete( false );
			return;
		}
		$i = (int) $this->state['input'];
		if ( $i >= count( $dumps ) ) {
			$this->log(
				sprintf(
					/* translators: %d: number of tables. */
					__( 'Database restored into %d temporary tables.', 'zinn-backups' ),
					count( (array) $this->state['tables'] )
				)
			);
			$this->begin_replace();
			return;
		}
		$file = (string) $dumps[ $i ];
		if ( empty( $this->state['sql'] ) ) {
			$from = (string) ( $this->state['manifest']['site']['prefix'] ?? '' );
			if ( '' === $from ) {
				$from = Sql_Importer::detect_prefix( $file );
			}
			if ( '' === $from ) {
				$this->log(
					sprintf(
						/* translators: %s: file name. */
						__( '%s has no WordPress tables in it and was skipped.', 'zinn-backups' ),
						basename( $file )
					),
					'warning'
				);
				$this->state['input'] = $i + 1;
				return;
			}
			$this->state['sql']    = array(
				'offset' => 0,
				'from'   => $from,
				'to'     => $this->state['temp_prefix'],
				'tables' => array(),
			);
			$this->state['from'][] = $from;
		}
		$size               = (int) filesize( $file );
		$this->state['sql'] = Sql_Importer::import( $file, (array) $this->state['sql'], $deadline );
		$this->progress( min( (int) $this->state['sql']['offset'], $size ), Sql_Importer::is_gzip( $file ) ? 0 : $size );
		if ( ! empty( $this->state['sql']['done'] ) ) {
			$this->state['tables'] = array_values( array_unique( array_merge( (array) $this->state['tables'], array_keys( (array) $this->state['sql']['tables'] ) ) ) );
			wp_delete_file( $file );
			$this->state['sql']   = array();
			$this->state['input'] = $i + 1;
		}
	}

	/**
	 * Work out the address and folder moves, then start rewriting.
	 *
	 * @return void
	 * @throws \RuntimeException On a failure.
	 */
	private function begin_replace(): void {
		global $wpdb;
		$temp = (string) $this->state['temp_prefix'];
		foreach ( array( 'options', 'users', 'posts' ) as $core ) {
			if ( ! in_array( $temp . $core, (array) $this->state['tables'], true ) ) {
				throw new \RuntimeException( esc_html__( 'The backup’s database has no WordPress tables, so nothing was replaced.', 'zinn-backups' ) );
			}
		}
		foreach ( array( 'blogs', 'site', 'sitemeta' ) as $network_table ) {
			if ( in_array( $temp . $network_table, (array) $this->state['tables'], true ) ) {
				throw new \RuntimeException( esc_html__( 'This backup is of a multisite network. Restoring a network into a single site is not supported.', 'zinn-backups' ) );
			}
		}
		foreach ( array_unique( (array) $this->state['from'] ) as $from ) {
			Table_Swap::fix_prefixed_keys( $temp, (string) $from, $wpdb->prefix );
		}
		$old_home    = untrailingslashit( Table_Swap::option( $temp, 'home' ) );
		$old_siteurl = untrailingslashit( Table_Swap::option( $temp, 'siteurl' ) );
		$moves       = array(
			array( $old_home, untrailingslashit( home_url() ) ),
			array( $old_siteurl, untrailingslashit( site_url() ) ),
		);
		$old_root    = (string) ( $this->state['manifest']['site']['abspath'] ?? '' );
		if ( '' !== $old_root ) {
			$moves[] = array( untrailingslashit( $old_root ), untrailingslashit( wp_normalize_path( ABSPATH ) ) );
		}
		$this->state['pairs']   = Serialized_Replace::pairs( $moves );
		$this->state['replace'] = array(
			'table' => 0,
			'after' => null,
		);
		if ( '' !== $old_home && untrailingslashit( home_url() ) !== $old_home ) {
			$this->log(
				sprintf(
					/* translators: 1: old address, 2: this site's address. */
					__( 'Moving the content from %1$s to %2$s.', 'zinn-backups' ),
					$old_home,
					untrailingslashit( home_url() )
				)
			);
		}
		$this->stage( $this->state['pairs'] ? 'replace' : 'swap' );
	}

	/**
	 * Rewrite addresses in the temporary tables.
	 *
	 * @param float $deadline Deadline.
	 * @return void
	 */
	private function do_replace( float $deadline ): void {
		$tables                 = (array) $this->state['tables'];
		$this->state['replace'] = Table_Swap::replace( $tables, (array) $this->state['replace'], (array) $this->state['pairs'], $this->state['temp_prefix'] . 'posts', $deadline );
		$this->progress( (int) $this->state['replace']['table'], count( $tables ) );
		if ( ! empty( $this->state['replace']['done'] ) ) {
			$this->stage( 'swap' );
		}
	}

	/**
	 * Swap the database in.
	 *
	 * @return void
	 */
	private function do_swap(): void {
		$temp = (string) $this->state['temp_prefix'];
		$this->apply_package( $temp );
		Table_Swap::preserve( $temp );
		Table_Swap::swap( $temp, (array) $this->state['tables'], (string) $this->state['old_prefix'] );
		Work_Dir::remember( (string) $this->state['work_name'] );
		$this->complete( true );
	}

	/**
	 * All-in-One WP Migration empties `active_plugins` in its dump and lists the plugins (and
	 * the theme) in `package.json` instead, re-activating them on import. Do the same, for the
	 * ones whose files are on this site.
	 *
	 * @param string $temp Temporary + site prefix.
	 * @return void
	 */
	private function apply_package( string $temp ): void {
		global $wpdb;
		$file = $this->dir() . '/sql/package.json';
		if ( ! is_readable( $file ) ) {
			return;
		}
		$package = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our work file.
		if ( ! is_array( $package ) ) {
			return;
		}
		$table   = $temp . 'options';
		$plugins = array_values(
			array_filter(
				(array) ( $package['Plugins'] ?? array() ),
				static fn( $plugin ): bool => is_string( $plugin ) && Import\Archive_Reader::safe_name( $plugin ) && is_file( WP_PLUGIN_DIR . '/' . $plugin )
			)
		);
		if ( $plugins ) {
			$active = maybe_unserialize( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, 'active_plugins' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a restored table.
			$active = array_values( array_unique( array_merge( is_array( $active ) ? $active : array(), $plugins ) ) );
			$wpdb->update( $table, array( 'option_value' => maybe_serialize( $active ) ), array( 'option_name' => 'active_plugins' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a restored table.
		}
		foreach ( array(
			'template'   => 'Template',
			'stylesheet' => 'Stylesheet',
		) as $option => $key ) {
			$theme = (string) ( $package[ $key ] ?? '' );
			if ( '' !== $theme && Import\Archive_Reader::safe_name( $theme ) && is_dir( get_theme_root() . '/' . $theme ) ) {
				$wpdb->update( $table, array( 'option_value' => $theme ), array( 'option_name' => $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a restored table.
			}
		}
	}

	/**
	 * Empty the page caches a restore has made stale (measured on a hosted site 2026-10-08:
	 * the LiteSpeed cache kept serving the pre-restore home page). Each call is the cache
	 * plugin's own public "purge everything" hook, and does nothing when it is not installed.
	 *
	 * @return void
	 */
	private static function purge_page_caches(): void {
		wp_cache_flush();
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own purge hook.
		do_action( 'zinn_cache_purge_all' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		/**
		 * Fires after a restore or import, so any other cache can empty itself.
		 */
		do_action( 'zinn_backups_restored' );
	}

	/**
	 * Done.
	 *
	 * @param bool $database Whether the database was replaced.
	 * @return void
	 */
	private function complete( bool $database ): void {
		$this->state['result'] = array(
			'files'      => (int) ( $this->state['restored_files'] ?? 0 ),
			'unwritable' => (int) ( $this->state['unwritable'] ?? 0 ),
			'database'   => $database,
			'tables'     => count( (array) $this->state['tables'] ),
			'source'     => (string) ( $this->state['source_label'] ?? 'Zinn® Backups' ),
		);
		self::purge_page_caches();
		if ( $database ) {
			delete_option( 'rewrite_rules' ); // Rebuilt for this site on the next request.
		}
		Work_Dir::remove_job( $this->id() );
		$this->finish(
			$database
				? __( 'Restore complete. If you are signed out, sign in with an account from the restored site.', 'zinn-backups' )
				: __( 'Restore complete.', 'zinn-backups' )
		);
	}
}
