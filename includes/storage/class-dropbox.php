<?php
/**
 * Dropbox, through the Dropbox HTTP API v2.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups\Storage;

use ZinnDigital\Backups\Http;
use ZinnDigital\Backups\Remote_Error;

defined( 'ABSPATH' ) || exit;

/**
 * ⭐ Sign-in uses OAuth 2 with PKCE and NO redirect: Dropbox shows the person a code and they
 * paste it back. That needs only a public app key — no secret in the plugin, and no server of
 * ours in the middle that could see their token.
 */
final class Dropbox implements Destination {

	/** Seconds before expiry at which the access token is refreshed. */
	private const REFRESH_MARGIN = 300;

	/**
	 * Constructor.
	 *
	 * @param string        $app_key       Dropbox app key.
	 * @param string        $folder        Folder inside the app folder ('' = its root).
	 * @param array         $tokens        `refresh_token`, `access_token`, `expires_at`.
	 * @param callable|null $save_tokens   Called with refreshed tokens so they can be stored.
	 */
	public function __construct(
		private string $app_key,
		private string $folder,
		private array $tokens,
		private $save_tokens = null
	) {
		$this->folder = trim( str_replace( '\\', '/', $folder ), '/' );
	}

	/**
	 * The page the person signs in on.
	 *
	 * @param string $app_key  App key.
	 * @param string $verifier PKCE code verifier.
	 * @return string
	 */
	public static function authorize_url( string $app_key, string $verifier ): string {
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE requires base64url.
		return add_query_arg(
			array(
				'client_id'             => rawurlencode( $app_key ),
				'response_type'         => 'code',
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'token_access_type'     => 'offline',
			),
			'https://www.dropbox.com/oauth2/authorize'
		);
	}

	/**
	 * Exchange the pasted code for tokens.
	 *
	 * @param string $app_key  App key.
	 * @param string $code     Code shown by Dropbox.
	 * @param string $verifier PKCE verifier.
	 * @return array{refresh_token:string, access_token:string, expires_at:string}
	 * @throws Remote_Error On a storage error.
	 */
	public static function exchange_code( string $app_key, string $code, string $verifier ): array {
		$response = Http::expect(
			Http::request(
				'POST',
				'https://api.dropboxapi.com/oauth2/token',
				array( 'content-type' => 'application/x-www-form-urlencoded' ),
				http_build_query(
					array(
						'code'          => trim( $code ),
						'grant_type'    => 'authorization_code',
						'client_id'     => $app_key,
						'code_verifier' => $verifier,
					)
				)
			),
			array( 200 ),
			'Dropbox'
		);
		$data     = (array) json_decode( $response['body'], true );
		if ( empty( $data['refresh_token'] ) ) {
			throw new Remote_Error( esc_html__( 'Dropbox did not return a refresh token. Start the connection again.', 'zinn-backups' ), 400 );
		}
		return array(
			'refresh_token' => (string) $data['refresh_token'],
			'access_token'  => (string) ( $data['access_token'] ?? '' ),
			'expires_at'    => (string) ( time() + (int) ( $data['expires_in'] ?? 0 ) ),
		);
	}

	/**
	 * Check the credentials and that the root folder can be written.
	 *
	 * @return void
	 * @throws \ZinnDigital\Backups\Remote_Error When it cannot.
	 */
	public function test(): void {
		$probe = 'zinn-backups/.connection-test-' . wp_generate_password( 8, false );
		$this->content(
			'files/upload',
			array(
				'path' => $this->path( $probe ),
				'mode' => 'overwrite',
				'mute' => true,
			),
			'ok'
		);
		$this->delete( $probe );
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
		$response = $this->content( 'files/upload_session/start', array( 'close' => false ), '' );
		$data     = (array) json_decode( $response['body'], true );
		return array(
			'remote'     => $remote,
			'size'       => $size,
			'offset'     => 0,
			'session_id' => (string) ( $data['session_id'] ?? '' ),
			'done'       => false,
		);
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
		$offset = (int) $session['offset'];
		$chunk  = S3::read_file( $local, $offset, self::CHUNK );
		$cursor = array(
			'session_id' => (string) $session['session_id'],
			'offset'     => $offset,
		);
		$last   = $offset + strlen( $chunk ) >= (int) $session['size'];
		if ( $last ) {
			$this->content(
				'files/upload_session/finish',
				array(
					'cursor' => $cursor,
					'commit' => array(
						'path' => $this->path( (string) $session['remote'] ),
						'mode' => 'overwrite',
						'mute' => true,
					),
				),
				$chunk
			);
			$session['done'] = true;
		} else {
			$this->content(
				'files/upload_session/append_v2',
				array(
					'cursor' => $cursor,
					'close'  => false,
				),
				$chunk
			);
		}
		$session['offset'] = $offset + strlen( $chunk );
		return $session;
	}

	/**
	 * Every object under a folder, recursively.
	 *
	 * @param string $prefix Folder path.
	 * @return array<int,array{path:string,size:int}>
	 * @throws \ZinnDigital\Backups\Remote_Error On a storage error.
	 */
	public function list_files( string $prefix ): array {
		$out  = array();
		$base = $this->path( $prefix );
		try {
			$data = $this->rpc(
				'files/list_folder',
				array(
					'path'      => $base,
					'recursive' => true,
					'limit'     => 2000,
				)
			);
		} catch ( Remote_Error $e ) {
			if ( 409 === $e->getCode() ) {
				return array(); // The folder does not exist yet.
			}
			throw $e;
		}
		$root = strtolower( $this->path( '' ) );
		while ( true ) {
			foreach ( (array) ( $data['entries'] ?? array() ) as $entry ) {
				if ( 'file' !== ( $entry['.tag'] ?? '' ) ) {
					continue;
				}
				$lower = (string) ( $entry['path_lower'] ?? '' );
				$shown = (string) ( $entry['path_display'] ?? $lower );
				$out[] = array(
					'path' => ltrim( substr( $shown, strlen( rtrim( $root, '/' ) ) ), '/' ),
					'size' => (int) ( $entry['size'] ?? 0 ),
				);
			}
			if ( empty( $data['has_more'] ) ) {
				break;
			}
			$data = $this->rpc( 'files/list_folder/continue', array( 'cursor' => (string) $data['cursor'] ) );
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
		$response = Http::request(
			'POST',
			'https://content.dropboxapi.com/2/files/download',
			array(
				'authorization'   => 'Bearer ' . $this->access_token(),
				'dropbox-api-arg' => self::api_arg( array( 'path' => $this->path( $remote ) ) ),
				'range'           => 'bytes=' . $offset . '-' . ( $offset + $length - 1 ),
			)
		);
		if ( 416 === $response['status'] ) {
			return '';
		}
		return Http::expect( $response, array( 200, 206 ), 'Dropbox' )['body'];
	}

	/**
	 * Delete an object.
	 *
	 * @param string $remote Remote path.
	 * @return void
	 * @throws \ZinnDigital\Backups\Remote_Error On a storage error.
	 */
	public function delete( string $remote ): void {
		try {
			$this->rpc( 'files/delete_v2', array( 'path' => $this->path( $remote ) ) );
		} catch ( Remote_Error $e ) {
			if ( 409 !== $e->getCode() ) {
				throw $e;
			}
		}
	}

	/**
	 * A time-limited download link, or null.
	 *
	 * @param string $remote  Remote path.
	 * @param int    $seconds Lifetime.
	 * @return string|null
	 */
	public function temporary_url( string $remote, int $seconds ): ?string {
		$data = $this->rpc( 'files/get_temporary_link', array( 'path' => $this->path( $remote ) ) );
		return isset( $data['link'] ) ? (string) $data['link'] : null; // Dropbox links last four hours.
	}

	/**
	 * Full Dropbox path for a remote path.
	 *
	 * @param string $remote Remote path.
	 * @return string
	 */
	private function path( string $remote ): string {
		$parts = array_filter( array( $this->folder, trim( $remote, '/' ) ), static fn( string $p ): bool => '' !== $p );
		return '/' . implode( '/', $parts );
	}

	/**
	 * Call an RPC endpoint.
	 *
	 * @param string $endpoint Endpoint after `/2/`.
	 * @param array  $args     JSON arguments.
	 * @return array
	 */
	private function rpc( string $endpoint, array $args ): array {
		$response = Http::expect(
			Http::request(
				'POST',
				'https://api.dropboxapi.com/2/' . $endpoint,
				array(
					'authorization' => 'Bearer ' . $this->access_token(),
					'content-type'  => 'application/json',
				),
				(string) wp_json_encode( $args )
			),
			array( 200 ),
			'Dropbox'
		);
		return (array) json_decode( $response['body'], true );
	}

	/**
	 * Call a content-upload endpoint.
	 *
	 * @param string $endpoint Endpoint after `/2/`.
	 * @param array  $args     API arguments (sent in a header).
	 * @param string $body     Bytes.
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	private function content( string $endpoint, array $args, string $body ): array {
		return Http::expect(
			Http::request(
				'POST',
				'https://content.dropboxapi.com/2/' . $endpoint,
				array(
					'authorization'   => 'Bearer ' . $this->access_token(),
					'content-type'    => 'application/octet-stream',
					'dropbox-api-arg' => self::api_arg( $args ),
				),
				$body
			),
			array( 200 ),
			'Dropbox'
		);
	}

	/**
	 * The `Dropbox-API-Arg` header value: JSON with every non-ASCII character escaped, as the
	 * API requires for HTTP headers.
	 *
	 * @param array $args Arguments.
	 * @return string
	 */
	private static function api_arg( array $args ): string {
		return (string) wp_json_encode( $args, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * A valid access token, refreshed when it is about to expire.
	 *
	 * @return string
	 * @throws Remote_Error On a storage error.
	 */
	private function access_token(): string {
		if ( '' !== (string) ( $this->tokens['access_token'] ?? '' ) && (int) ( $this->tokens['expires_at'] ?? 0 ) > time() + self::REFRESH_MARGIN ) {
			return (string) $this->tokens['access_token'];
		}
		if ( '' === (string) ( $this->tokens['refresh_token'] ?? '' ) ) {
			throw new Remote_Error( esc_html__( 'Dropbox is not connected. Connect it again on the Storage tab.', 'zinn-backups' ), 401 );
		}
		$response                     = Http::expect(
			Http::request(
				'POST',
				'https://api.dropboxapi.com/oauth2/token',
				array( 'content-type' => 'application/x-www-form-urlencoded' ),
				http_build_query(
					array(
						'grant_type'    => 'refresh_token',
						'refresh_token' => (string) $this->tokens['refresh_token'],
						'client_id'     => $this->app_key,
					)
				)
			),
			array( 200 ),
			'Dropbox'
		);
		$data                         = (array) json_decode( $response['body'], true );
		$this->tokens['access_token'] = (string) ( $data['access_token'] ?? '' );
		$this->tokens['expires_at']   = (string) ( time() + (int) ( $data['expires_in'] ?? 0 ) );
		if ( is_callable( $this->save_tokens ) ) {
			( $this->save_tokens )(
				array(
					'access_token' => $this->tokens['access_token'],
					'expires_at'   => $this->tokens['expires_at'],
				)
			);
		}
		return $this->tokens['access_token'];
	}
}
