<?php
/**
 * A read-only "destination" made of download links — what a deploy code carries.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups\Storage;

use ZinnDigital\Backups\Http;
use ZinnDigital\Backups\Remote_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a backup's file names to time-limited links, so a site can restore another site's
 * backup without ever holding that site's storage keys.
 */
final class Url_Source implements Destination {

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $urls Remote path => link.
	 */
	public function __construct( private array $urls ) {
	}

	/**
	 * Check the credentials and that the root folder can be written.
	 *
	 * @return void
	 * @throws \ZinnDigital\Backups\Remote_Error When it cannot.
	 */
	public function test(): void {
	}

	/**
	 * Start an upload.
	 *
	 * @param string $remote Remote path.
	 * @param int    $size   Total size in bytes.
	 * @return array<string,mixed> Session state.
	 * @throws \ZinnDigital\Backups\Remote_Error On a storage error.
	 */
	public function upload_begin( string $remote, int $size ): array {
		return $this->refuse();
	}

	/**
	 * Upload the next chunk.
	 *
	 * @param array<string,mixed> $session Session state.
	 * @param string              $local   Local file.
	 * @return array<string,mixed> Updated session.
	 * @throws \ZinnDigital\Backups\Remote_Error On a storage error.
	 */
	public function upload_chunk( array $session, string $local ): array {
		return $this->refuse();
	}

	/**
	 * Refuse a write.
	 *
	 * @return never
	 * @throws Remote_Error Always.
	 */
	private function refuse(): never {
		throw new Remote_Error( esc_html__( 'A deploy link is read-only.', 'zinn-backups' ), 400 );
	}

	/**
	 * Every object under a folder, recursively.
	 *
	 * @param string $prefix Folder path.
	 * @return array<int,array{path:string,size:int}>
	 * @throws \ZinnDigital\Backups\Remote_Error On a storage error.
	 */
	public function list_files( string $prefix ): array {
		$out = array();
		foreach ( array_keys( $this->urls ) as $path ) {
			if ( str_starts_with( $path, trim( $prefix, '/' ) . '/' ) ) {
				$out[] = array(
					'path' => $path,
					'size' => 0,
				);
			}
		}
		return $out;
	}

	/**
	 * Read a byte range of a remote object.
	 *
	 * @param string $remote Remote path.
	 * @param int    $offset First byte.
	 * @param int    $length Bytes wanted.
	 * @return string
	 * @throws \ZinnDigital\Backups\Remote_Error On a storage error.
	 */
	public function read_range( string $remote, int $offset, int $length ): string {
		$url = $this->urls[ trim( $remote, '/' ) ] ?? '';
		if ( '' === $url ) {
			throw new Remote_Error( esc_html__( 'The deploy code has no link for a file this backup needs.', 'zinn-backups' ), 404 );
		}
		$response = Http::request( 'GET', $url, array( 'range' => 'bytes=' . $offset . '-' . ( $offset + $length - 1 ) ) );
		if ( 416 === $response['status'] ) {
			return '';
		}
		if ( 200 === $response['status'] ) {
			return substr( $response['body'], $offset, $length ); // A server that ignores Range sends it all.
		}
		return Http::expect( $response, array( 206 ), __( 'The deploy link', 'zinn-backups' ) )['body'];
	}

	/**
	 * Delete an object.
	 *
	 * @param string $remote Remote path.
	 * @return void
	 * @throws \ZinnDigital\Backups\Remote_Error On a storage error.
	 */
	public function delete( string $remote ): void {
	}

	/**
	 * A time-limited download link, or null.
	 *
	 * @param string $remote  Remote path.
	 * @param int    $seconds Lifetime.
	 * @return string|null
	 */
	public function temporary_url( string $remote, int $seconds ): ?string {
		return $this->urls[ trim( $remote, '/' ) ] ?? null;
	}
}
