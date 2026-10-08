<?php
/**
 * Google Drive, through the Drive API v3.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups\Storage;

use ZinnDigital\Backups\Http;
use ZinnDigital\Backups\Remote_Error;

defined( 'ABSPATH' ) || exit;

/**
 * ⭐ The scope is `drive.file`: the plugin sees only the files it created, never the rest of
 * the person's Drive. Google requires a web client's secret for every token refresh, so the
 * OAuth client (id + secret) is the site owner's own, created in their Google Cloud project
 * with this site's admin page as its redirect address (`docs/977` §3).
 */
final class Google_Drive implements Destination {

	private const API    = 'https://www.googleapis.com/drive/v3/';
	private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';
	private const FOLDER = 'application/vnd.google-apps.folder';

	/**
	 * Folder ids already resolved, by path.
	 *
	 * @var array<string,string>
	 */
	private array $ids = array();

	/**
	 * Constructor.
	 *
	 * @param string        $client_id     OAuth client id.
	 * @param string        $client_secret OAuth client secret.
	 * @param string        $root_name     Name of the top folder in My Drive.
	 * @param array         $tokens        `refresh_token`, `access_token`, `expires_at`.
	 * @param callable|null $save_tokens   Called with refreshed tokens.
	 */
	public function __construct(
		private string $client_id,
		private string $client_secret,
		private string $root_name,
		private array $tokens,
		private $save_tokens = null
	) {
		$this->root_name = '' === trim( $root_name ) ? 'Zinn® Backups' : trim( $root_name );
	}

	/**
	 * The page the person signs in on.
	 *
	 * @param string $client_id OAuth client id.
	 * @param string $redirect  Redirect address (this site's admin page).
	 * @param string $state     Anti-forgery state.
	 * @param string $verifier  PKCE verifier.
	 * @return string
	 */
	public static function authorize_url( string $client_id, string $redirect, string $state, string $verifier ): string {
		return add_query_arg(
			array(
				'client_id'             => rawurlencode( $client_id ),
				'redirect_uri'          => rawurlencode( $redirect ),
				'response_type'         => 'code',
				'scope'                 => rawurlencode( 'https://www.googleapis.com/auth/drive.file' ),
				'access_type'           => 'offline',
				'prompt'                => 'consent',
				'state'                 => rawurlencode( $state ),
				'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE requires base64url.
				'code_challenge_method' => 'S256',
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		);
	}

	/**
	 * Exchange the returned code for tokens.
	 *
	 * @param string $client_id     Client id.
	 * @param string $client_secret Client secret.
	 * @param string $code          Code.
	 * @param string $redirect      Redirect address used to sign in.
	 * @param string $verifier      PKCE verifier.
	 * @return array{refresh_token:string, access_token:string, expires_at:string}
	 * @throws Remote_Error On a storage error.
	 */
	public static function exchange_code( string $client_id, string $client_secret, string $code, string $redirect, string $verifier ): array {
		$response = Http::expect(
			Http::request(
				'POST',
				'https://oauth2.googleapis.com/token',
				array( 'content-type' => 'application/x-www-form-urlencoded' ),
				http_build_query(
					array(
						'code'          => $code,
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'redirect_uri'  => $redirect,
						'grant_type'    => 'authorization_code',
						'code_verifier' => $verifier,
					)
				)
			),
			array( 200 ),
			'Google'
		);
		$data     = (array) json_decode( $response['body'], true );
		if ( empty( $data['refresh_token'] ) ) {
			throw new Remote_Error( esc_html__( 'Google did not return a refresh token. Remove this site from your Google account’s third-party access and connect again.', 'zinn-backups' ), 400 );
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
		$this->folder_id( 'zinn-backups', true );
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
		$parent   = $this->folder_id( self::dirname( $remote ), true );
		$name     = basename( $remote );
		$existing = $this->child( $parent, $name, false );
		if ( null !== $existing ) {
			$this->api( 'DELETE', 'files/' . rawurlencode( $existing ), array(), null, array( 204, 404 ) );
		}
		$response = Http::expect(
			Http::request(
				'POST',
				self::UPLOAD . '?uploadType=resumable',
				array(
					'authorization'           => 'Bearer ' . $this->access_token(),
					'content-type'            => 'application/json; charset=UTF-8',
					'x-upload-content-type'   => 'application/octet-stream',
					'x-upload-content-length' => (string) $size,
				),
				(string) wp_json_encode(
					array(
						'name'    => $name,
						'parents' => array( $parent ),
					)
				)
			),
			array( 200 ),
			'Google Drive'
		);
		return array(
			'remote'   => $remote,
			'size'     => $size,
			'offset'   => 0,
			'location' => (string) ( $response['headers']['location'] ?? '' ),
			'done'     => false,
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
		$size   = (int) $session['size'];
		$chunk  = S3::read_file( $local, $offset, self::CHUNK );
		$range  = 0 === $size ? 'bytes */0' : 'bytes ' . $offset . '-' . ( $offset + strlen( $chunk ) - 1 ) . '/' . $size;
		$res    = Http::request(
			'PUT',
			(string) $session['location'],
			array(
				'authorization' => 'Bearer ' . $this->access_token(),
				'content-range' => $range,
			),
			$chunk
		);
		if ( 308 === $res['status'] ) {
			$received          = preg_match( '/bytes=0-(\d+)/', (string) ( $res['headers']['range'] ?? '' ), $m ) ? (int) $m[1] + 1 : 0;
			$session['offset'] = $received;
			return $session;
		}
		Http::expect( $res, array( 200, 201 ), 'Google Drive' );
		$session['offset'] = $size;
		$session['done']   = true;
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
		$start = $this->folder_id( trim( $prefix, '/' ), false );
		if ( null === $start ) {
			return array();
		}
		$out   = array();
		$queue = array( array( $start, trim( $prefix, '/' ) ) );
		while ( $queue ) {
			list( $id, $path ) = array_shift( $queue );
			foreach ( $this->children( $id ) as $item ) {
				$child = $path . '/' . $item['name'];
				if ( self::FOLDER === $item['mimeType'] ) {
					$this->ids[ $child ] = $item['id'];
					$queue[]             = array( $item['id'], $child );
				} else {
					$out[] = array(
						'path' => $child,
						'size' => (int) ( $item['size'] ?? 0 ),
					);
				}
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
		$id       = $this->file_id( $remote );
		$response = Http::request(
			'GET',
			self::API . 'files/' . rawurlencode( $id ) . '?alt=media',
			array(
				'authorization' => 'Bearer ' . $this->access_token(),
				'range'         => 'bytes=' . $offset . '-' . ( $offset + $length - 1 ),
			)
		);
		if ( 416 === $response['status'] ) {
			return '';
		}
		return Http::expect( $response, array( 200, 206 ), 'Google Drive' )['body'];
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
			$id = $this->file_id( $remote );
		} catch ( Remote_Error $e ) {
			if ( 404 === $e->getCode() ) {
				return;
			}
			throw $e;
		}
		$this->api( 'DELETE', 'files/' . rawurlencode( $id ), array(), null, array( 204, 404 ) );
	}

	/**
	 * A time-limited download link, or null.
	 *
	 * @param string $remote  Remote path.
	 * @param int    $seconds Lifetime.
	 * @return string|null
	 */
	public function temporary_url( string $remote, int $seconds ): ?string {
		return null; // Drive has no expiring link without changing the file's sharing.
	}

	/**
	 * The id of a file by path.
	 *
	 * @param string $remote Remote path.
	 * @return string
	 * @throws Remote_Error 404 when missing.
	 */
	private function file_id( string $remote ): string {
		$parent = $this->folder_id( self::dirname( $remote ), false );
		$id     = null === $parent ? null : $this->child( $parent, basename( $remote ), false );
		if ( null === $id ) {
			throw new Remote_Error( esc_html__( 'That file is not in Google Drive any more.', 'zinn-backups' ), 404 );
		}
		return $id;
	}

	/**
	 * The id of a folder by path below the top folder, creating it when asked.
	 *
	 * @param string $path   Folder path ('' = the top folder).
	 * @param bool   $create Create missing folders.
	 * @return string|null
	 */
	private function folder_id( string $path, bool $create ): ?string {
		$path = trim( $path, '/' );
		if ( isset( $this->ids[ $path ] ) ) {
			return $this->ids[ $path ];
		}
		$id = $this->child( 'root', $this->root_name, true );
		if ( null === $id ) {
			if ( ! $create ) {
				return null;
			}
			$id = $this->make_folder( 'root', $this->root_name );
		}
		$this->ids[''] = $id;
		$walked        = '';
		foreach ( '' === $path ? array() : explode( '/', $path ) as $segment ) {
			$walked = ltrim( $walked . '/' . $segment, '/' );
			if ( isset( $this->ids[ $walked ] ) ) {
				$id = $this->ids[ $walked ];
				continue;
			}
			$next = $this->child( $id, $segment, true );
			if ( null === $next ) {
				if ( ! $create ) {
					return null;
				}
				$next = $this->make_folder( $id, $segment );
			}
			$id                   = $next;
			$this->ids[ $walked ] = $id;
		}
		return $id;
	}

	/**
	 * Find a child by name.
	 *
	 * @param string $parent_id Parent id.
	 * @param string $name   Name.
	 * @param bool   $folder Only folders (true) or only files (false).
	 * @return string|null
	 */
	private function child( string $parent_id, string $name, bool $folder ): ?string {
		$q    = "'" . self::q( $parent_id ) . "' in parents and name = '" . self::q( $name ) . "' and trashed = false and mimeType " . ( $folder ? '=' : '!=' ) . " '" . self::FOLDER . "'";
		$data = $this->api(
			'GET',
			'files',
			array(
				'q'        => $q,
				'fields'   => 'files(id)',
				'pageSize' => '1',
				'spaces'   => 'drive',
			)
		);
		return isset( $data['files'][0]['id'] ) ? (string) $data['files'][0]['id'] : null;
	}

	/**
	 * Every child of a folder.
	 *
	 * @param string $parent_id Folder id.
	 * @return array<int,array{id:string,name:string,mimeType:string,size?:string}>
	 */
	private function children( string $parent_id ): array {
		$out   = array();
		$token = '';
		do {
			$query = array(
				'q'        => "'" . self::q( $parent_id ) . "' in parents and trashed = false",
				'fields'   => 'nextPageToken,files(id,name,mimeType,size)',
				'pageSize' => '1000',
				'spaces'   => 'drive',
			);
			if ( '' !== $token ) {
				$query['pageToken'] = $token;
			}
			$data  = $this->api( 'GET', 'files', $query );
			$out   = array_merge( $out, (array) ( $data['files'] ?? array() ) );
			$token = (string) ( $data['nextPageToken'] ?? '' );
		} while ( '' !== $token );
		return $out;
	}

	/**
	 * Create a folder.
	 *
	 * @param string $parent_id Parent id.
	 * @param string $name   Name.
	 * @return string The new id.
	 */
	private function make_folder( string $parent_id, string $name ): string {
		$data = $this->api(
			'POST',
			'files',
			array( 'fields' => 'id' ),
			array(
				'name'     => $name,
				'mimeType' => self::FOLDER,
				'parents'  => array( $parent_id ),
			)
		);
		return (string) ( $data['id'] ?? '' );
	}

	/**
	 * Call the Drive API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path after `/drive/v3/`.
	 * @param array      $query  Query parameters.
	 * @param array|null $json   JSON body.
	 * @param int[]      $ok     Accepted statuses.
	 * @return array
	 */
	private function api( string $method, string $path, array $query = array(), ?array $json = null, array $ok = array( 200 ) ): array {
		$url     = self::API . $path . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
		$headers = array( 'authorization' => 'Bearer ' . $this->access_token() );
		$body    = null;
		if ( null !== $json ) {
			$headers['content-type'] = 'application/json; charset=UTF-8';
			$body                    = (string) wp_json_encode( $json );
		}
		$response = Http::expect( Http::request( $method, $url, $headers, $body ), $ok, 'Google Drive' );
		return (array) json_decode( $response['body'], true );
	}

	/**
	 * Escape a value for a Drive query string literal.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function q( string $value ): string {
		return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value );
	}

	/**
	 * Folder part of a path ('' at the top).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function dirname( string $path ): string {
		$dir = dirname( trim( $path, '/' ) );
		return '.' === $dir ? '' : $dir;
	}

	/**
	 * A valid access token, refreshed when it is about to expire.
	 *
	 * @return string
	 * @throws Remote_Error On a storage error.
	 */
	private function access_token(): string {
		if ( '' !== (string) ( $this->tokens['access_token'] ?? '' ) && (int) ( $this->tokens['expires_at'] ?? 0 ) > time() + 300 ) {
			return (string) $this->tokens['access_token'];
		}
		if ( '' === (string) ( $this->tokens['refresh_token'] ?? '' ) ) {
			throw new Remote_Error( esc_html__( 'Google Drive is not connected. Connect it again on the Storage tab.', 'zinn-backups' ), 401 );
		}
		$response                     = Http::expect(
			Http::request(
				'POST',
				'https://oauth2.googleapis.com/token',
				array( 'content-type' => 'application/x-www-form-urlencoded' ),
				http_build_query(
					array(
						'grant_type'    => 'refresh_token',
						'refresh_token' => (string) $this->tokens['refresh_token'],
						'client_id'     => $this->client_id,
						'client_secret' => $this->client_secret,
					)
				)
			),
			array( 200 ),
			'Google'
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
