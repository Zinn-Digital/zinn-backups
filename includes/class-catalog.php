<?php
/**
 * The backups that exist in the saved destinations.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

use ZinnDigital\Backups\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * ⭐ Read from the storage itself, never from a list kept on the site: the storage is the
 * truth (a backup deleted there is gone; a backup made by this site before it was rebuilt is
 * still there), and a list kept in the database would itself be lost with the site.
 *
 * Results are cached for ten minutes per destination and cleared whenever a backup is made
 * or deleted here.
 */
final class Catalog {

	private const CACHE = 'zinn_backups_catalog_';

	/**
	 * Every complete backup, newest first.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( bool $fresh = false ): array {
		$out = array();
		foreach ( Settings::destinations() as $record ) {
			if ( ! Destinations::is_ready( $record ) ) {
				continue;
			}
			$out = array_merge( $out, self::for_destination( $record, $fresh ) );
		}
		usort( $out, static fn( $a, $b ): int => (int) $b['created'] <=> (int) $a['created'] );
		return $out;
	}

	/**
	 * Backups in one destination.
	 *
	 * @param array<string,mixed> $record Destination.
	 * @param bool                $fresh  Skip the cache.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_destination( array $record, bool $fresh = false ): array {
		$key    = self::CACHE . md5( (string) $record['id'] );
		$cached = $fresh ? false : get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$client = Destinations::client( $record );
		$out    = array();
		$mine   = Backup_Job::site_key();
		foreach ( $client->list_files( 'zinn-backups' ) as $file ) {
			if ( ! preg_match( '#^zinn-backups/([^/]+)/([^/]+)/manifest\.json$#', $file['path'], $m ) ) {
				continue;
			}
			$manifest = json_decode( $client->read_range( $file['path'], 0, 4194304 ), true );
			if ( ! is_array( $manifest ) || 'zinn-backups' !== ( $manifest['format'] ?? '' ) ) {
				continue;
			}
			$size  = (int) ( $manifest['database']['size'] ?? 0 ) + array_sum( array_map( static fn( $p ): int => (int) ( $p['size'] ?? 0 ), (array) ( $manifest['parts'] ?? array() ) ) );
			$out[] = array(
				'destination' => (string) $record['id'],
				'label'       => (string) $record['label'],
				'remote'      => 'zinn-backups/' . $m[1] . '/' . $m[2],
				'id'          => (string) ( $manifest['id'] ?? $m[2] ),
				'site'        => (string) ( $manifest['site']['home'] ?? '' ),
				'this_site'   => $m[1] === $mine,
				'created'     => (int) ( $manifest['created'] ?? 0 ),
				'type'        => (string) ( $manifest['type'] ?? 'full' ),
				'uses'        => (array) ( $manifest['uses'] ?? array() ),
				'database'    => ! empty( $manifest['database'] ),
				'files'       => ! empty( $manifest['index'] ),
				'encrypted'   => ! empty( $manifest['encrypted'] ),
				'size'        => $size,
				'note'        => (string) ( $manifest['note'] ?? '' ),
				'trigger'     => (string) ( $manifest['trigger'] ?? '' ),
			);
		}
		set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Forget the cached lists.
	 *
	 * @return void
	 */
	public static function flush(): void {
		foreach ( Settings::destinations() as $record ) {
			delete_transient( self::CACHE . md5( (string) $record['id'] ) );
		}
	}

	/**
	 * Backups (same destination) that need this one to restore.
	 *
	 * @param string $destination Destination id.
	 * @param string $remote      Backup folder.
	 * @return string[] Ids of the dependants.
	 */
	public static function dependants( string $destination, string $remote ): array {
		$id     = basename( $remote );
		$parent = dirname( $remote );
		$out    = array();
		foreach ( self::all() as $backup ) {
			if ( $backup['destination'] === $destination && dirname( $backup['remote'] ) === $parent && in_array( $id, $backup['uses'], true ) ) {
				$out[] = $backup['id'];
			}
		}
		return $out;
	}

	/**
	 * Delete a backup folder from a destination.
	 *
	 * @param string $destination Destination id.
	 * @param string $remote      Backup folder (`zinn-backups/<site>/<id>`).
	 * @param bool   $force       Delete even when later incremental backups need it.
	 * @return int Files deleted.
	 * @throws \RuntimeException When it is still needed or the folder is not a backup.
	 */
	public static function delete( string $destination, string $remote, bool $force = false ): int {
		if ( ! preg_match( '#^zinn-backups/[a-z0-9-]+/[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$#', $remote ) ) {
			throw new \RuntimeException( esc_html__( 'That is not a backup folder.', 'zinn-backups' ) );
		}
		$record = Settings::destination( $destination );
		if ( null === $record ) {
			throw new \RuntimeException( esc_html__( 'That storage destination is no longer saved on this site.', 'zinn-backups' ) );
		}
		if ( ! $force && self::dependants( $destination, $remote ) ) {
			throw new \RuntimeException( esc_html__( 'Later incremental backups are built on this one. Delete those first.', 'zinn-backups' ) );
		}
		$client = Destinations::client( $record );
		$files  = $client->list_files( $remote );
		// The manifest goes first, so an interrupted delete never leaves a "complete" backup with parts missing.
		usort( $files, static fn( $a, $b ): int => (int) str_ends_with( $b['path'], 'manifest.json' ) <=> (int) str_ends_with( $a['path'], 'manifest.json' ) );
		foreach ( $files as $file ) {
			$client->delete( $file['path'] );
		}
		self::flush();
		return count( $files );
	}
}
