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
