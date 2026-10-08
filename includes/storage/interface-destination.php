<?php
/**
 * What every storage destination can do.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Paths are `/`-separated and relative to the destination's own root folder
 * (`zinn-backups/<site>/<backup>/<file>`).
 *
 * ⛔ Uploads and downloads are CHUNKED and RESUMABLE: a step may stop between any two chunks
 * because the host ends the request, and the next step continues from the saved session.
 */
interface Destination {

	/** Size of one upload or download chunk, in bytes (8 MiB: S3's 5 MiB minimum part, Drive's 256 KiB multiple). */
	public const CHUNK = 8388608;

	/**
	 * Check the credentials and that the root folder can be written.
	 *
	 * @return void
	 * @throws \ZinnDigital\Backups\Remote_Error When it cannot.
	 */
	public function test(): void;

	/**
	 * Start an upload.
	 *
	 * @param string $remote Remote path.
	 * @param int    $size   Total size in bytes.
	 * @return array<string,mixed> Session state; carries at least `offset`.
	 */
	public function upload_begin( string $remote, int $size ): array;

	/**
	 * Upload the next chunk of `$local`. Sets `done` when the object is complete.
	 *
	 * @param array<string,mixed> $session Session state from {@see upload_begin()}.
	 * @param string              $local   Local file.
	 * @return array<string,mixed> Updated session.
	 */
	public function upload_chunk( array $session, string $local ): array;

	/**
	 * Every object under a folder, recursively.
	 *
	 * @param string $prefix Folder path, without a trailing slash.
	 * @return array<int,array{path:string,size:int}>
	 */
	public function list_files( string $prefix ): array;

	/**
	 * Read a byte range of a remote object.
	 *
	 * @param string $remote Remote path.
	 * @param int    $offset First byte.
	 * @param int    $length Bytes wanted.
	 * @return string The bytes (shorter at the end of the object).
	 */
	public function read_range( string $remote, int $offset, int $length ): string;

	/**
	 * Delete an object. Deleting a missing object is not an error.
	 *
	 * @param string $remote Remote path.
	 * @return void
	 */
	public function delete( string $remote ): void;

	/**
	 * A time-limited link anyone can download the object from, or null when the provider has
	 * no such thing.
	 *
	 * @param string $remote  Remote path.
	 * @param int    $seconds How long the link should work.
	 * @return string|null
	 */
	public function temporary_url( string $remote, int $seconds ): ?string;
}
