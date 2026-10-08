<?php
/**
 * A backup: list the files, dump the database, pack, upload, write the manifest.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

use ZinnDigital\Backups\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Remote layout, per destination:
 *
 * Path: zinn-backups/<site>/<backup id>/database.sql.gz
 * Path: zinn-backups/<site>/<backup id>/files-0001.zip …
 * Path: zinn-backups/<site>/<backup id>/files-index.ndjson.gz
 * Path: zinn-backups/<site>/<backup id>/manifest.json (written LAST)
 *
 * ⛔ The manifest is the commit marker: a backup folder without one is an interrupted backup
 * and is never offered for restore.
 *
 * ⛔ Each part is uploaded and deleted before the next is built (owner D40), so the server
 * never holds the whole backup.
 */
final class Backup_Job extends Job {

	/**
	 * Create a backup job.
	 *
	 * @param array{database?:bool,files?:bool,note?:string,trigger?:string} $what What to back up.
	 * @return self
	 * @throws \RuntimeException When there is nowhere to send it.
	 */
	public static function create( array $what = array() ): self {
		$destinations = Destinations::active();
		if ( ! $destinations ) {
			throw new \RuntimeException( esc_html__( 'Connect a storage destination on the Storage tab first: backups are kept in your own storage, not on this server.', 'zinn-backups' ) );
		}
		if ( is_multisite() ) {
			throw new \RuntimeException( esc_html__( 'Multisite networks are not supported yet.', 'zinn-backups' ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \RuntimeException( esc_html__( 'This server’s PHP has no ZipArchive extension. Ask your host to enable it.', 'zinn-backups' ) );
		}
		$id = self::new_id();
		return new self(
			array(
				'id'           => $id,
				'database'     => $what['database'] ?? true,
				'files'        => $what['files'] ?? true,
				'note'         => sanitize_text_field( (string) ( $what['note'] ?? '' ) ),
				'trigger'      => sanitize_key( (string) ( $what['trigger'] ?? 'manual' ) ),
				'destinations' => array_map( static fn( array $pair ): string => (string) $pair[0]['id'], $destinations ),
				'remote'       => 'zinn-backups/' . self::site_key() . '/' . $id,
				'stage'        => 'init',
				'queue'        => array(),
				'uploaded'     => array(),
				'parts'        => array(),
				'part_no'      => 0,
			)
		);
	}

	/**
	 * A folder name for this site: its host and path, and a short hash of its address so two
	 * sites with the same name never share a folder.
	 *
	 * @return string
	 */
	public static function site_key(): string {
		$home = (string) home_url( '/' );
		$host = (string) wp_parse_url( $home, PHP_URL_HOST ) . (string) wp_parse_url( $home, PHP_URL_PATH );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $host ) ), '-' );
		return substr( $slug, 0, 60 ) . '-' . substr( md5( untrailingslashit( $home ) ), 0, 6 );
	}

	/**
	 * The job's kind.
	 *
	 * @return string
	 */
	public function type(): string {
		return 'backup';
	}

	/**
	 * A human label for the current stage.
	 *
	 * @return string
	 */
	public function stage_label(): string {
		return match ( $this->state['stage'] ) {
			'init'     => __( 'Starting', 'zinn-backups' ),
			'scan'     => __( 'Listing files', 'zinn-backups' ),
			'database' => __( 'Backing up the database', 'zinn-backups' ),
			'archive'  => __( 'Packing files', 'zinn-backups' ),
			'upload'   => __( 'Uploading to your storage', 'zinn-backups' ),
			'finalize' => __( 'Finishing', 'zinn-backups' ),
			default    => __( 'Backup', 'zinn-backups' ),
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
			'init'     => $this->do_init(),
			'scan'     => $this->do_scan( $deadline ),
			'database' => $this->do_database( $deadline ),
			'archive'  => $this->do_archive( $deadline ),
			'upload'   => $this->do_upload( $deadline ),
			'finalize' => $this->do_finalize(),
			default    => $this->fail( __( 'The backup reached an unknown stage.', 'zinn-backups' ) ),
		};
	}

	/**
	 * Decide what to do.
	 *
	 * @return void
	 */
	private function do_init(): void {
		/**
		 * Filters a backup before it starts. Zinn® Backups Pro uses it to make the backup
		 * incremental (`base` = the backup it builds on).
		 *
		 * @param array<string,mixed> $state The job state.
		 */
		$this->state = (array) apply_filters( 'zinn_backups_backup_start', $this->state );
		$this->log(
			sprintf(
				/* translators: %s: backup id. */
				__( 'Backup %s started.', 'zinn-backups' ),
				$this->id()
			)
		);
		if ( ! empty( $this->state['files'] ) ) {
			$this->state['scan'] = File_Scanner::begin( Settings::all()['include_core'] );
			$this->stage( 'scan' );
		} else {
			$this->begin_database();
		}
	}

	/**
	 * List files.
	 *
	 * @param float $deadline Deadline.
	 * @return void
	 */
	private function do_scan( float $deadline ): void {
		$this->state['scan'] = File_Scanner::scan( $this->dir() . '/scan.ndjson', (array) $this->state['scan'], Settings::excludes(), $deadline );
		$this->progress( (int) $this->state['scan']['files'], 0 );
		if ( $this->state['scan']['done'] ) {
			$this->log(
				sprintf(
					/* translators: 1: number of files, 2: total size. */
					__( 'Found %1$s files (%2$s).', 'zinn-backups' ),
					number_format_i18n( (int) $this->state['scan']['files'] ),
					size_format( (int) $this->state['scan']['bytes'] )
				)
			);
			$this->begin_database();
		}
	}

	/**
	 * Start the database stage (or skip it).
	 *
	 * @return void
	 */
	private function begin_database(): void {
		if ( empty( $this->state['database'] ) ) {
			$this->begin_archive();
			return;
		}
		$this->state['db'] = array(
			'tables' => Database_Dumper::tables(),
			'table'  => 0,
			'rows'   => 0,
		);
		$this->stage( 'database' );
	}

	/**
	 * Dump the database.
	 *
	 * @param float $deadline Deadline.
	 * @return void
	 */
	private function do_database( float $deadline ): void {
		$file              = $this->dir() . '/database.sql.gz';
		$this->state['db'] = Database_Dumper::dump( $file, (array) $this->state['db'], $deadline );
		$this->progress( (int) $this->state['db']['table'], count( (array) $this->state['db']['tables'] ) );
		if ( ! empty( $this->state['db']['done'] ) ) {
			$this->log(
				sprintf(
					/* translators: 1: number of tables, 2: number of rows, 3: size. */
					__( 'Database: %1$d tables, %2$s rows, %3$s compressed.', 'zinn-backups' ),
					count( (array) $this->state['db']['tables'] ),
					number_format_i18n( (int) $this->state['db']['rows'] ),
					size_format( (int) filesize( $file ) )
				)
			);
			$this->state['database_file'] = $this->enqueue( $file, 'database.sql.gz' );
			$this->state['after_upload']  = 'begin_archive';
			$this->stage( 'upload' );
		}
	}

	/**
	 * Start packing files (or skip it).
	 *
	 * @return void
	 */
	private function begin_archive(): void {
		if ( empty( $this->state['files'] ) ) {
			$this->stage( 'finalize' );
			return;
		}
		$this->state['arc'] = array(
			'offset' => 0,
			'safe'   => false,
		);
		$this->next_part();
		$this->stage( 'archive' );
	}

	/**
	 * Number the next part.
	 *
	 * @return void
	 */
	private function next_part(): void {
		++$this->state['part_no'];
		$this->state['part_name'] = sprintf( 'files-%04d.zip', (int) $this->state['part_no'] );
	}

	/**
	 * Pack files.
	 *
	 * @param float $deadline Deadline.
	 * @return void
	 */
	private function do_archive( float $deadline ): void {
		$list = $this->dir() . '/scan.ndjson';
		if ( ! file_exists( $list ) ) {
			touch( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- an empty list for an empty site.
		}
		$part               = $this->dir() . '/' . $this->state['part_name'];
		$this->state['arc'] = Archiver::add(
			$list,
			$this->dir() . '/files-index.ndjson',
			$part,
			(string) $this->state['part_name'],
			(array) $this->state['arc'],
			Settings::all()['part_mb'] * 1048576,
			$this->id(),
			$deadline
		);
		$this->progress( (int) $this->state['arc']['offset'], (int) filesize( $list ) );
		if ( ! empty( $this->state['arc']['part_full'] ) ) {
			$this->state['parts'][] = $this->enqueue( $part, (string) $this->state['part_name'] );
			$this->next_part();
			$this->state['after_upload'] = ! empty( $this->state['arc']['done'] ) ? 'finalize' : 'archive';
			$this->stage( 'upload' );
			return;
		}
		if ( ! empty( $this->state['arc']['done'] ) ) {
			$this->stage( 'finalize' );
		}
	}

	/**
	 * Hand a finished local file to the upload queue, after Pro has had the chance to
	 * encrypt it.
	 *
	 * @param string $path Local path.
	 * @param string $name Name in the backup.
	 * @return array{name:string,size:int,sha256:string,encrypted:bool}
	 */
	private function enqueue( string $path, string $name ): array {
		/**
		 * Filters a backup file before it is uploaded. Zinn® Backups Pro encrypts it here and
		 * returns the encrypted file (`path`), its name (`name`) and `encrypted` = true.
		 *
		 * @param array{path:string,name:string,encrypted:bool} $file  The file.
		 * @param array<string,mixed>                           $state The job state.
		 */
		$file = (array) apply_filters(
			'zinn_backups_prepare_upload',
			array(
				'path'      => $path,
				'name'      => $name,
				'encrypted' => false,
			),
			$this->state
		);
		if ( $file['path'] !== $path ) {
			wp_delete_file( $path );
		}
		$entry                  = array(
			'name'      => (string) $file['name'],
			'size'      => (int) filesize( (string) $file['path'] ),
			'sha256'    => (string) hash_file( 'sha256', (string) $file['path'] ),
			'encrypted' => ! empty( $file['encrypted'] ),
		);
		$this->state['queue'][] = array(
			'path'     => (string) $file['path'],
			'name'     => $entry['name'],
			'size'     => $entry['size'],
			'sessions' => array(),
		);
		return $entry;
	}

	/**
	 * Upload the head of the queue to every destination, a chunk at a time.
	 *
	 * @param float $deadline Deadline.
	 * @return void
	 */
	private function do_upload( float $deadline ): void {
		$clients = $this->clients();
		while ( $this->state['queue'] && microtime( true ) < $deadline - 2 ) {
			$item = &$this->state['queue'][0];
			$all  = true;
			foreach ( $clients as $id => $client ) {
				$session = $item['sessions'][ $id ] ?? null;
				if ( null === $session ) {
					$session                 = $client->upload_begin( $this->state['remote'] . '/' . $item['name'], (int) $item['size'] );
					$item['sessions'][ $id ] = $session;
				}
				while ( empty( $session['done'] ) && microtime( true ) < $deadline - 2 ) {
					$session                 = $client->upload_chunk( $session, (string) $item['path'] );
					$item['sessions'][ $id ] = $session;
					$this->progress( (int) $session['offset'], (int) $item['size'] );
				}
				$all = $all && ! empty( $session['done'] );
			}
			if ( ! $all ) {
				unset( $item );
				return;
			}
			wp_delete_file( (string) $item['path'] );
			$this->state['uploaded'][] = $item['name'];
			unset( $item );
			array_shift( $this->state['queue'] );
		}
		if ( ! $this->state['queue'] ) {
			$next = (string) ( $this->state['after_upload'] ?? 'finalize' );
			if ( 'begin_archive' === $next ) {
				$this->begin_archive();
			} elseif ( 'done' === $next ) {
				$this->complete();
			} else {
				$this->stage( $next );
			}
		}
	}

	/**
	 * Upload the index, then the manifest.
	 *
	 * @return void
	 */
	private function do_finalize(): void {
		$index = $this->dir() . '/files-index.ndjson';
		if ( ! empty( $this->state['files'] ) ) {
			if ( ! file_exists( $index ) ) {
				touch( $index ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- an empty index for an empty site.
			}
			copy( $index, Work_Dir::path() . '/last-index.ndjson' );
			$gz = $index . '.gz';
			self::gzip( $index, $gz );
			$this->state['index'] = $this->enqueue( $gz, 'files-index.ndjson.gz' );
		}
		$manifest = $this->manifest();
		$path     = $this->dir() . '/manifest.json';
		file_put_contents( $path, (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a small file in our private folder.
		Runner::write_json( Work_Dir::path() . '/last-manifest.json', $manifest );
		$this->state['queue'][]      = array(
			'path'     => $path,
			'name'     => 'manifest.json',
			'size'     => (int) filesize( $path ),
			'sessions' => array(),
		);
		$this->state['manifest']     = $manifest;
		$this->state['after_upload'] = 'done';
		$this->stage( 'upload' );
	}

	/**
	 * Everything a restore needs to know about this backup.
	 *
	 * @return array<string,mixed>
	 */
	private function manifest(): array {
		global $wpdb, $wp_version;
		$uses = array();
		$path = $this->dir() . '/files-index.ndjson';
		if ( is_readable( $path ) ) {
			$in = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading our own work file.
			while ( false !== ( $line = fgets( $in ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- line reader.
				$row = json_decode( $line, true );
				if ( is_array( $row ) && isset( $row[3] ) && $row[3] !== $this->id() ) {
					$uses[ (string) $row[3] ] = true;
				}
			}
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		return array(
			'format'    => 'zinn-backups',
			'version'   => 1,
			'id'        => $this->id(),
			'created'   => time(),
			'type'      => empty( $this->state['base'] ) ? 'full' : 'incremental',
			'base'      => $this->state['base'] ?? null,
			'chain'     => (int) ( $this->state['chain'] ?? 0 ),
			'targets'   => array_values( (array) $this->state['destinations'] ),
			'uses'      => array_keys( $uses ),
			'trigger'   => $this->state['trigger'],
			'note'      => $this->state['note'],
			'encrypted' => (bool) array_filter( array_merge( $this->state['parts'], array( $this->state['database_file'] ?? array() ) ), static fn( $p ): bool => ! empty( $p['encrypted'] ) ),
			'site'      => array(
				'home'      => home_url(),
				'siteurl'   => site_url(),
				'abspath'   => wp_normalize_path( ABSPATH ),
				'content'   => wp_normalize_path( WP_CONTENT_DIR ),
				'prefix'    => $wpdb->prefix,
				'wordpress' => $wp_version,
				'php'       => PHP_VERSION,
				'plugin'    => ZINN_BACKUPS_VERSION,
			),
			'database'  => $this->state['database_file'] ?? null,
			'parts'     => $this->state['parts'],
			'index'     => $this->state['index'] ?? null,
			'files'     => array(
				'count' => (int) ( $this->state['scan']['files'] ?? 0 ),
				'bytes' => (int) ( $this->state['scan']['bytes'] ?? 0 ),
			),
		);
	}

	/**
	 * Done: tidy up and tell anything listening.
	 *
	 * @return void
	 */
	private function complete(): void {
		$manifest              = (array) $this->state['manifest'];
		$size                  = (int) ( $manifest['database']['size'] ?? 0 ) + array_sum( array_map( static fn( $p ): int => (int) $p['size'], (array) $manifest['parts'] ) );
		$this->state['result'] = array(
			'backup' => $this->id(),
			'remote' => $this->state['remote'],
			'size'   => $size,
			'type'   => $manifest['type'],
		);
		Work_Dir::remove_job( $this->id() );
		$this->finish(
			sprintf(
				/* translators: 1: size of the backup, 2: number of destinations. */
				_n( 'Backup complete: %1$s stored in %2$d destination.', 'Backup complete: %1$s stored in %2$d destinations.', count( (array) $this->state['destinations'] ), 'zinn-backups' ),
				size_format( $size ),
				count( (array) $this->state['destinations'] )
			)
		);
		/**
		 * Fires when a backup is safely stored. Zinn® Backups Pro applies retention here.
		 *
		 * @param array<string,mixed> $manifest     The backup's manifest.
		 * @param string[]            $destinations Destination ids it was stored in.
		 */
		do_action( 'zinn_backups_backup_completed', $manifest, (array) $this->state['destinations'] );
	}

	/**
	 * A failed or cancelled backup removes what it already uploaded (it has no manifest, so
	 * it could never be restored, and it would only cost the customer storage).
	 *
	 * @return void
	 */
	public function cleanup(): void {
		$uploaded = (array) ( $this->state['uploaded'] ?? array() );
		if ( $uploaded ) {
			try {
				foreach ( $this->clients() as $client ) {
					foreach ( $uploaded as $name ) {
						$client->delete( $this->state['remote'] . '/' . $name );
					}
				}
			} catch ( \Throwable ) {
				$this->log( __( 'Some parts already uploaded could not be removed from your storage; they are in an incomplete backup folder and are never offered for restore.', 'zinn-backups' ), 'warning' );
			}
		}
		parent::cleanup();
	}

	/**
	 * Clients for this job's destinations.
	 *
	 * @return array<string,Storage\Destination>
	 * @throws Remote_Error When a destination was removed mid-backup.
	 */
	private function clients(): array {
		$out = array();
		foreach ( (array) $this->state['destinations'] as $id ) {
			$record = Settings::destination( (string) $id );
			if ( null === $record ) {
				throw new Remote_Error( esc_html__( 'A storage destination was removed while the backup was running.', 'zinn-backups' ), 400 );
			}
			$out[ (string) $id ] = Destinations::client( $record );
		}
		return $out;
	}

	/**
	 * Gzip a file in a streaming way.
	 *
	 * @param string $from Source.
	 * @param string $to   Target.
	 * @return void
	 */
	public static function gzip( string $from, string $to ): void {
		$in  = fopen( $from, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming compression of our own work file.
		$out = gzopen( $to, 'wb6' );
		while ( ! feof( $in ) ) {
			gzwrite( $out, (string) fread( $in, 1048576 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		gzclose( $out );
	}
}
