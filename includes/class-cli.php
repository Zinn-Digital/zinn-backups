<?php
/**
 * WP-CLI commands.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

use ZinnDigital\Backups\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * Back up, restore and import from the command line.
 *
 * ## EXAMPLES
 *
 *     wp zinn-backups destination add --type=s3 --provider=r2 --account=abc123 --bucket=my-backups --access-key=AKIA… --secret-key-env=R2_SECRET
 *     wp zinn-backups backup
 *     wp zinn-backups list
 *     wp zinn-backups restore zinn-backups/example-com-1a2b3c/20261008-101500-0a1b2c3d --yes
 *     wp zinn-backups import ./backup_2026-10-01-1200_Site_abc-db.gz ./backup_2026-10-01-1200_Site_abc-uploads.zip --yes
 *     printf '%s' "$R2_SECRET" | wp zinn-backups key set secret-key
 *     wp zinn-backups key get
 *     wp zinn-backups loader status
 */
final class Cli {

	/**
	 * Make a backup now and wait for it to finish.
	 *
	 * [--database-only]
	 * : Only the database.
	 *
	 * [--files-only]
	 * : Only the files.
	 *
	 * [--note=<note>]
	 * : A note stored with the backup.
	 *
	 * @param array<int,string>    $args  Positional.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function backup( array $args, array $assoc ): void {
		$job = Backup_Job::create(
			array(
				'database' => ! isset( $assoc['files-only'] ),
				'files'    => ! isset( $assoc['database-only'] ),
				'note'     => (string) ( $assoc['note'] ?? '' ),
				'trigger'  => 'cli',
			)
		);
		$this->run( $job );
	}

	/**
	 * List the backups in storage.
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array<int,string>    $args  Positional.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function list( array $args, array $assoc ): void {
		$rows = array_map(
			static fn( array $b ): array => array(
				'remote'      => $b['remote'],
				'created'     => gmdate( 'Y-m-d H:i:s', (int) $b['created'] ) . ' UTC',
				'type'        => $b['type'],
				'size'        => size_format( (int) $b['size'] ),
				'encrypted'   => $b['encrypted'] ? 'yes' : 'no',
				'destination' => $b['destination'],
				'site'        => $b['site'],
			),
			Catalog::all( true )
		);
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'remote', 'created', 'type', 'size', 'encrypted', 'destination', 'site' ) );
	}

	/**
	 * Restore a backup (replaces this site's files and database).
	 *
	 * <remote>
	 * : The backup folder, as `wp zinn-backups list` shows it.
	 *
	 * [--destination=<id>]
	 * : Destination id (default: the first one holding it).
	 *
	 * [--database-only]
	 * : Only the database.
	 *
	 * [--files-only]
	 * : Only the files.
	 *
	 * [--passphrase-env=<variable>]
	 * : Name of an environment variable holding the passphrase of an encrypted backup.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array<int,string>    $args  Positional.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function restore( array $args, array $assoc ): void {
		$remote      = trim( $args[0], '/' );
		$destination = (string) ( $assoc['destination'] ?? '' );
		if ( '' === $destination ) {
			foreach ( Catalog::all( true ) as $backup ) {
				if ( $backup['remote'] === $remote ) {
					$destination = $backup['destination'];
					break;
				}
			}
		}
		if ( '' === $destination ) {
			\WP_CLI::error( 'No saved destination holds that backup.' );
		}
		\WP_CLI::confirm( 'This replaces this site\'s files and database. Continue?', $assoc );
		$this->run(
			Restore_Job::from_backup(
				array(
					'destination' => $destination,
					'remote'      => $remote,
					'files'       => ! isset( $assoc['database-only'] ),
					'database'    => ! isset( $assoc['files-only'] ),
					'passphrase'  => isset( $assoc['passphrase-env'] ) ? (string) getenv( (string) $assoc['passphrase-env'] ) : '',
				)
			)
		);
	}

	/**
	 * Import archives made by another backup or migration plugin (files or links).
	 *
	 * [<source>...]
	 * : Local files or https:// links.
	 *
	 * [--deploy-env=<variable>]
	 * : Name of an environment variable holding a deploy code made by Zinn® Backups Pro on another site.
	 *
	 * [--passphrase-env=<variable>]
	 * : Name of an environment variable holding the passphrase of an encrypted backup (deploy codes only).
	 *
	 * [--database-only]
	 * : Only the database.
	 *
	 * [--files-only]
	 * : Only the files.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array<int,string>    $args  Positional.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function import( array $args, array $assoc ): void {
		\WP_CLI::confirm( 'This replaces this site\'s content and database. Continue?', $assoc );
		if ( isset( $assoc['deploy-env'] ) ) {
			$code = Deploy_Code::read( (string) getenv( (string) $assoc['deploy-env'] ) );
			$this->run(
				Restore_Job::from_backup(
					array(
						'urls'       => $code['urls'],
						'remote'     => $code['remote'],
						'files'      => ! isset( $assoc['database-only'] ),
						'database'   => ! isset( $assoc['files-only'] ),
						'passphrase' => isset( $assoc['passphrase-env'] ) ? (string) getenv( (string) $assoc['passphrase-env'] ) : '',
					)
				)
			);
			return;
		}
		if ( ! $args ) {
			\WP_CLI::error( 'Give an archive file or link, or --deploy-env.' );
		}
		$id     = Job::new_id();
		$dir    = Work_Dir::job( $id );
		$inputs = array();
		foreach ( $args as $index => $source ) {
			if ( preg_match( '#^https?://#', $source ) ) {
				$inputs[] = array(
					'url'  => Downloader::normalize( $source ),
					'name' => Downloader::name_for( $source ),
				);
				continue;
			}
			if ( ! is_readable( $source ) ) {
				\WP_CLI::error( "Cannot read {$source}." );
			}
			$target = $dir . '/upload-' . $index . '-' . sanitize_file_name( basename( $source ) );
			copy( $source, $target );
			$inputs[] = array(
				'path' => $target,
				'name' => basename( $source ),
			);
		}
		$this->run(
			Restore_Job::from_import(
				$inputs,
				array(
					'id'       => $id,
					'files'    => ! isset( $assoc['database-only'] ),
					'database' => ! isset( $assoc['files-only'] ),
				)
			)
		);
	}

	/**
	 * Manage storage destinations.
	 *
	 * <action>
	 * : list, add, test or remove.
	 *
	 * [--id=<id>]
	 * : Destination id (test, remove; add to replace one).
	 *
	 * [--type=<type>]
	 * : s3, dropbox or gdrive.
	 *
	 * [--provider=<provider>]
	 * : r2, aws, wasabi, backblaze, digitalocean or other.
	 *
	 * [--account=<account>]
	 * : Cloudflare account id (R2).
	 *
	 * [--endpoint=<url>]
	 * : Endpoint (other S3-compatible).
	 *
	 * [--region=<region>]
	 * : Region.
	 *
	 * [--bucket=<bucket>]
	 * : Bucket.
	 *
	 * [--folder=<folder>]
	 * : Folder inside the storage.
	 *
	 * [--access-key=<key>]
	 * : Access key id.
	 *
	 * [--secret-key-env=<variable>]
	 * : Name of an environment variable holding the secret key (never pass a secret itself on the command line).
	 *
	 * [--label=<label>]
	 * : Name for the destination.
	 *
	 * @param array<int,string>    $args  Positional.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function destination( array $args, array $assoc ): void {
		switch ( $args[0] ?? 'list' ) {
			case 'add':
				list( $record, $secrets ) = Destinations::from_input(
					array(
						'id'            => $assoc['id'] ?? '',
						'type'          => $assoc['type'] ?? 's3',
						'provider'      => $assoc['provider'] ?? 'r2',
						'account'       => $assoc['account'] ?? '',
						'endpoint'      => $assoc['endpoint'] ?? '',
						'region'        => $assoc['region'] ?? '',
						'bucket'        => $assoc['bucket'] ?? '',
						'folder'        => $assoc['folder'] ?? '',
						'access_key'    => $assoc['access-key'] ?? '',
						'access_secret' => isset( $assoc['secret-key-env'] ) ? (string) getenv( (string) $assoc['secret-key-env'] ) : '',
						'label'         => $assoc['label'] ?? '',
					)
				);
				$saved                    = Settings::put_destination( $record, $secrets );
				Catalog::flush();
				\WP_CLI::success( 'Saved destination ' . $saved['id'] . '.' );
				return;
			case 'test':
				$record = Settings::destination( (string) ( $assoc['id'] ?? '' ) ) ?? ( Settings::destinations()[0] ?? null );
				if ( null === $record ) {
					\WP_CLI::error( 'No such destination.' );
				}
				Destinations::client( $record )->test();
				\WP_CLI::success( 'Connected: a test file was written and removed.' );
				return;
			case 'remove':
				Settings::delete_destination( (string) ( $assoc['id'] ?? '' ) );
				\WP_CLI::success( 'Removed.' );
				return;
			default:
				\WP_CLI\Utils\format_items(
					'table',
					array_map(
						static fn( array $r ): array => array(
							'id'    => $r['id'],
							'type'  => $r['type'],
							'label' => $r['label'],
							'ready' => Destinations::is_ready( $r ) ? 'yes' : 'no',
						),
						Settings::destinations()
					),
					array( 'id', 'type', 'label', 'ready' )
				);
		}
	}

	/**
	 * Set, show or remove the keys that connect a storage destination.
	 *
	 * The value of `set` is read from STDIN (a hidden prompt on a terminal) or from the
	 * environment variable named by --from-env — never from the command line, where it would stay
	 * in the shell history. It is checked before it is saved. `get` never prints a key in full:
	 * only its last four characters (none at all for a short key).
	 *
	 * Keys: s3 — access-key, secret-key. dropbox — app-key, refresh-token. gdrive — client-id,
	 * client-secret, refresh-token.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : set, get or remove.
	 *
	 * [<name>]
	 * : Key name (required for set and remove; get shows every key when it is left out).
	 *
	 * [<value>...]
	 * : Refused. A key is never taken as an argument (see above); listed only so the refusal
	 * does not repeat the value back.
	 *
	 * [--id=<id>]
	 * : Destination id. May be left out when the site has one destination.
	 *
	 * [--from-env=<variable>]
	 * : set: read the value from this environment variable instead of STDIN.
	 *
	 * [--format=<format>]
	 * : get: table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * [--yes]
	 * : remove: do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     printf '%s' "$R2_SECRET" | wp zinn-backups key set secret-key
	 *     wp zinn-backups key set access-key --from-env=R2_ACCESS_KEY --id=d1a2b3c4d5e6
	 *     wp zinn-backups key get
	 *     wp zinn-backups key remove refresh-token --yes
	 *
	 * @param array<int,string>    $args  Positional.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function key( array $args, array $assoc ): void {
		$action = (string) ( $args[0] ?? '' );
		$name   = (string) ( $args[1] ?? '' );
		if ( ! in_array( $action, array( 'set', 'get', 'remove' ), true ) ) {
			\WP_CLI::error( __( 'Use: wp zinn-backups key set|get|remove [<name>]', 'zinn-backups' ) );
		}
		if ( isset( $args[2] ) ) {
			// A value typed as an argument is already in the shell history: refuse it, say why.
			\WP_CLI::error( __( 'Do not put a key on the command line. Pipe it in on STDIN, or name an environment variable with --from-env.', 'zinn-backups' ) );
		}
		try {
			$record = Connection_Keys::destination( (string) ( $assoc['id'] ?? '' ) );
			$type   = (string) $record['type'];
			switch ( $action ) {
				case 'get':
					$rows = Connection_Keys::shown( $record );
					if ( '' !== $name ) {
						Connection_Keys::definition( $type, $name );
						$rows = array_values( array_filter( $rows, static fn( array $row ): bool => $row['key'] === $name ) );
					}
					\WP_CLI\Utils\format_items( 'json' === ( $assoc['format'] ?? 'table' ) ? 'json' : 'table', $rows, array( 'key', 'kind', 'value' ) );
					return;
				case 'set':
					Connection_Keys::definition( $type, $name );
					$masked = Connection_Keys::set( $record, $name, $this->read_key( $assoc, $name ) );
					Catalog::flush();
					/* translators: 1: key name, 2: destination id, 3: the key with all but its last characters hidden. */
					\WP_CLI::success( sprintf( __( 'Saved %1$s for destination %2$s: %3$s', 'zinn-backups' ), $name, (string) $record['id'], $masked ) );
					return;
				default:
					Connection_Keys::definition( $type, $name );
					if ( ! isset( $assoc['yes'] ) ) {
						/* translators: 1: key name, 2: destination id. */
						\WP_CLI::confirm( sprintf( __( 'Remove %1$s from destination %2$s? Backups to it stop until a new one is set.', 'zinn-backups' ), $name, (string) $record['id'] ) );
					}
					Connection_Keys::remove( $record, $name );
					Catalog::flush();
					/* translators: 1: key name, 2: destination id. */
					\WP_CLI::success( sprintf( __( 'Removed %1$s from destination %2$s.', 'zinn-backups' ), $name, (string) $record['id'] ) );
			}
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Show, install or remove the must-use loader that keeps backups running when the plugin is off.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : status, install or remove. install and remove also switch the "Keep running" setting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp zinn-backups loader status
	 *     wp zinn-backups loader remove
	 *
	 * @param array<int,string> $args Positional.
	 * @return void
	 */
	public function loader( array $args ): void {
		switch ( $args[0] ?? 'status' ) {
			case 'install':
			case 'remove':
				Settings::set_keep_running( 'install' === $args[0] );
				$result = Mu_Loader::sync();
				if ( true !== $result ) {
					\WP_CLI::error( $result );
				}
				\WP_CLI::success( 'install' === $args[0] ? __( 'The loader is in place.', 'zinn-backups' ) : __( 'The loader is removed.', 'zinn-backups' ) );
				return;
			case 'status':
				$rows = array(
					array(
						'field' => 'setting',
						'value' => Mu_Loader::wanted() ? 'on' : 'off',
					),
					array(
						'field' => 'installed',
						'value' => Mu_Loader::installed() ? 'yes' : 'no',
					),
					array(
						'field' => 'path',
						'value' => Mu_Loader::path(),
					),
					array(
						'field' => 'loaded_by',
						'value' => Mu_Loader::loaded_by_mu() ? 'mu-loader' : 'plugin',
					),
					array(
						'field' => 'blocked',
						'value' => Mu_Loader::blocked_reason(),
					),
				);
				\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
				return;
			default:
				\WP_CLI::error( __( 'Use: wp zinn-backups loader status|install|remove', 'zinn-backups' ) );
		}
	}

	/**
	 * Read a key for `key set`: an environment variable, a hidden prompt, or STDIN.
	 *
	 * @param array<string,string> $assoc Options.
	 * @param string               $name  Key name (for the prompt).
	 * @return string
	 */
	private function read_key( array $assoc, string $name ): string {
		if ( isset( $assoc['from-env'] ) ) {
			$value = getenv( (string) $assoc['from-env'] );
			if ( false === $value || '' === $value ) {
				\WP_CLI::error( __( 'That environment variable is not set or is empty.', 'zinn-backups' ) );
			}
			return (string) $value;
		}
		if ( function_exists( 'stream_isatty' ) && stream_isatty( STDIN ) ) {
			return (string) \cli\prompt( $name, false, ': ', true );
		}
		return (string) stream_get_contents( STDIN, 4096 );
	}

	/**
	 * Show the current job.
	 *
	 * @return void
	 */
	public function status(): void {
		$job = Runner::current();
		\WP_CLI::line( null === $job ? 'No job.' : (string) wp_json_encode( $job->summary(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Cancel the current job.
	 *
	 * @return void
	 */
	public function cancel(): void {
		Runner::cancel();
		\WP_CLI::success( 'Cancelled.' );
	}

	/**
	 * Start a job and run it to the end.
	 *
	 * @param Job $job The job.
	 * @return void
	 */
	private function run( Job $job ): void {
		Runner::start( $job );
		for ( ;; ) {
			$summary = Runner::tick( 300.0 );
			if ( null === $summary ) {
				\WP_CLI::error( 'The job disappeared.' );
			}
			if ( 'running' !== $summary['status'] ) {
				break;
			}
			$current = Runner::current();
			$wait    = null !== $current ? (int) ( $current->state['retry_after'] ?? 0 ) - time() : 0;
			if ( $wait > 0 ) {
				sleep( min( $wait, 300 ) );
			}
		}
		Catalog::flush();
		if ( 'restore' === $summary['type'] && 'done' === $summary['status'] ) {
			// From the command line a cache plugin's purge hook only adds a response header that
			// nothing sends, so ask the cache plugins' own commands (measured on a hosted site,
			// 2026-10-08: LiteSpeed kept serving the pre-restore page).
			foreach ( array( 'litespeed-purge all', 'zinn-cache purge all' ) as $command ) {
				\WP_CLI::runcommand(
					$command,
					array(
						'return'     => true,
						'exit_error' => false,
						'launch'     => false,
					)
				);
			}
		}
		if ( 'done' !== $summary['status'] ) {
			\WP_CLI::error( (string) ( '' !== (string) $summary['error'] ? $summary['error'] : $summary['status'] ) );
		}
		\WP_CLI::success( (string) wp_json_encode( $summary['result'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
