<?php
/**
 * Amazon S3 and every S3-compatible store: Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean
 * Spaces, MinIO and others.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups\Storage;

use ZinnDigital\Backups\Http;
use ZinnDigital\Backups\Remote_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Signature Version 4 written out here rather than a vendor SDK: the AWS SDK is tens of
 * megabytes, and a WordPress plugin that ships it collides with every other plugin that does.
 */
final class S3 implements Destination {

	/**
	 * Constructor.
	 *
	 * @param string $endpoint   Endpoint URL; empty for Amazon S3 itself.
	 * @param string $region     Region (`auto` for R2).
	 * @param string $bucket     Bucket name.
	 * @param string $folder     Folder inside the bucket ('' for the bucket root).
	 * @param string $access_key Access key id.
	 * @param string $access_secret Secret access key.
	 * @param bool   $path_style Use path-style URLs (needed by most S3-compatible stores).
	 */
	public function __construct(
		private string $endpoint,
		private string $region,
		private string $bucket,
		private string $folder,
		private string $access_key,
		private string $access_secret,
		private bool $path_style
	) {
		$this->endpoint = untrailingslashit( trim( $endpoint ) );
		$this->region   = '' === trim( $region ) ? 'us-east-1' : trim( $region );
		$this->folder   = trim( str_replace( '\\', '/', $folder ), '/' );
		if ( '' === $this->endpoint ) {
			$this->endpoint   = 'https://s3.' . $this->region . '.amazonaws.com'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- the S3 API endpoint of the bucket the site owner chose for backups; no asset is served from it.
			$this->path_style = false;
		}
	}

	/**
	 * Check the credentials and that the root folder can be written.
	 *
	 * @return void
	 * @throws \ZinnDigital\Backups\Remote_Error When it cannot.
	 */
	public function test(): void {
		$probe = 'zinn-backups/.connection-test-' . wp_generate_password( 8, false );
		$this->put( $this->key( $probe ), 'ok' );
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
		$session = array(
			'remote' => $remote,
			'size'   => $size,
			'offset' => 0,
			'done'   => false,
		);
		if ( $size > self::CHUNK ) {
			$response             = $this->signed( 'POST', $this->key( $remote ), array( 'uploads' => '' ), '' );
			$session['upload_id'] = $this->xml_value( $response['body'], 'UploadId' );
			$session['etags']     = array();
			if ( '' === $session['upload_id'] ) {
				throw new Remote_Error( esc_html__( 'The storage service did not start the upload.', 'zinn-backups' ) );
			}
		}
		return $session;
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
		$size = (int) $session['size'];
		if ( empty( $session['upload_id'] ) ) {
			$body = (string) file_get_contents( $local ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local part of at most one chunk.
			$this->put( $this->key( (string) $session['remote'] ), $body );
			$session['offset'] = $size;
			$session['done']   = true;
			return $session;
		}
		$offset             = (int) $session['offset'];
		$chunk              = self::read_file( $local, $offset, self::CHUNK );
		$part               = count( $session['etags'] ) + 1;
		$res                = $this->signed(
			'PUT',
			$this->key( (string) $session['remote'] ),
			array(
				'partNumber' => (string) $part,
				'uploadId'   => (string) $session['upload_id'],
			),
			$chunk
		);
		$session['etags'][] = trim( (string) ( $res['headers']['etag'] ?? '' ), '"' );
		$session['offset']  = $offset + strlen( $chunk );
		if ( $session['offset'] >= $size ) {
			$xml = '<CompleteMultipartUpload>';
			foreach ( $session['etags'] as $index => $etag ) {
				$xml .= '<Part><PartNumber>' . ( $index + 1 ) . '</PartNumber><ETag>"' . esc_xml( $etag ) . '"</ETag></Part>';
			}
			$xml .= '</CompleteMultipartUpload>';
			$done = $this->signed( 'POST', $this->key( (string) $session['remote'] ), array( 'uploadId' => (string) $session['upload_id'] ), $xml, array( 'content-type' => 'application/xml' ) );
			if ( str_contains( $done['body'], '<Error>' ) ) {
				throw new Remote_Error( esc_html( Http::error_detail( $done['body'] ) ), 500 );
			}
			$session['done'] = true;
		}
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
		$out   = array();
		$token = '';
		$base  = '' === $this->folder ? '' : $this->folder . '/';
		do {
			$query = array(
				'list-type' => '2',
				'prefix'    => $base . trim( $prefix, '/' ) . '/',
			);
			if ( '' !== $token ) {
				$query['continuation-token'] = $token;
			}
			$response = $this->signed( 'GET', '', $query, '' );
			$xml      = $this->parse_xml( $response['body'] );
			foreach ( $xml->Contents ?? array() as $item ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3's XML element name.
				$key   = (string) $item->Key; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3's XML element name.
				$out[] = array(
					'path' => substr( $key, strlen( $base ) ),
					'size' => (int) $item->Size, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3's XML element name.
				);
			}
			$token = 'true' === (string) ( $xml->IsTruncated ?? '' ) ? (string) $xml->NextContinuationToken : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- S3's XML element names.
		} while ( '' !== $token );
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
		$response = $this->signed( 'GET', $this->key( $remote ), array(), '', array( 'range' => 'bytes=' . $offset . '-' . ( $offset + $length - 1 ) ), array( 200, 206, 416 ) );
		return 416 === $response['status'] ? '' : $response['body'];
	}

	/**
	 * Delete an object.
	 *
	 * @param string $remote Remote path.
	 * @return void
	 * @throws \ZinnDigital\Backups\Remote_Error On a storage error.
	 */
	public function delete( string $remote ): void {
		$this->signed( 'DELETE', $this->key( $remote ), array(), '', array(), array( 200, 204, 404 ) );
	}

	/**
	 * A time-limited download link, or null.
	 *
	 * @param string $remote  Remote path.
	 * @param int    $seconds Lifetime.
	 * @return string|null
	 */
	public function temporary_url( string $remote, int $seconds ): ?string {
		$seconds             = max( 60, min( 604800, $seconds ) );
		$time                = time();
		$date                = gmdate( 'Ymd', $time );
		$stamp               = gmdate( 'Ymd\THis\Z', $time );
		$scope               = $date . '/' . $this->region . '/s3/aws4_request';
		list( $host, $path ) = $this->target( $this->key( $remote ) );
		$query               = array(
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $this->access_key . '/' . $scope,
			'X-Amz-Date'          => $stamp,
			'X-Amz-Expires'       => (string) $seconds,
			'X-Amz-SignedHeaders' => 'host',
		);
		$canonical_query     = self::canonical_query( $query );
		$canonical           = "GET\n" . $path . "\n" . $canonical_query . "\nhost:" . $host . "\n\nhost\nUNSIGNED-PAYLOAD";
		$signature           = $this->signature( $date, $stamp, $scope, $canonical );
		return $this->scheme() . '://' . $host . $path . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
	}

	/**
	 * Store a small object in one request.
	 *
	 * @param string $key  Full object key.
	 * @param string $body Bytes.
	 * @return void
	 */
	private function put( string $key, string $body ): void {
		$this->signed( 'PUT', $key, array(), $body, array( 'content-type' => 'application/octet-stream' ) );
	}

	/**
	 * Full key for a remote path.
	 *
	 * @param string $remote Remote path.
	 * @return string
	 */
	private function key( string $remote ): string {
		$remote = ltrim( $remote, '/' );
		return '' === $this->folder ? $remote : $this->folder . '/' . $remote;
	}

	/**
	 * Host and canonical path for an object key ('' = the bucket itself).
	 *
	 * @param string $key Object key.
	 * @return array{0:string,1:string}
	 */
	private function target( string $key ): array {
		$host     = (string) wp_parse_url( $this->endpoint, PHP_URL_HOST );
		$port     = wp_parse_url( $this->endpoint, PHP_URL_PORT );
		$host    .= $port ? ':' . $port : '';
		$segments = '' === $key ? array() : array_map( 'rawurlencode', explode( '/', $key ) );
		if ( $this->path_style ) {
			return array( $host, '/' . rawurlencode( $this->bucket ) . ( $segments ? '/' . implode( '/', $segments ) : '/' ) );
		}
		return array( $this->bucket . '.' . $host, '/' . implode( '/', $segments ) );
	}

	/**
	 * The endpoint's scheme.
	 *
	 * @return string
	 */
	private function scheme(): string {
		return 'http' === wp_parse_url( $this->endpoint, PHP_URL_SCHEME ) ? 'http' : 'https';
	}

	/**
	 * Send a signed request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $key     Object key ('' for the bucket).
	 * @param array<string,string> $query   Query parameters.
	 * @param string               $body    Request body.
	 * @param array<string,string> $headers Extra headers (lower-case names).
	 * @param int[]                $ok      Accepted statuses.
	 * @return array{status:int, headers:array<string,string>, body:string}
	 */
	private function signed( string $method, string $key, array $query, string $body, array $headers = array(), array $ok = array( 200, 204, 206 ) ): array {
		$time                = time();
		$date                = gmdate( 'Ymd', $time );
		$stamp               = gmdate( 'Ymd\THis\Z', $time );
		$scope               = $date . '/' . $this->region . '/s3/aws4_request';
		list( $host, $path ) = $this->target( $key );

		$headers['host']                 = $host;
		$headers['x-amz-date']           = $stamp;
		$headers['x-amz-content-sha256'] = hash( 'sha256', $body );
		ksort( $headers );
		$canonical_headers = '';
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= $name . ':' . trim( $value ) . "\n";
		}
		$signed_headers  = implode( ';', array_keys( $headers ) );
		$canonical_query = self::canonical_query( $query );
		$canonical       = $method . "\n" . $path . "\n" . $canonical_query . "\n" . $canonical_headers . "\n" . $signed_headers . "\n" . $headers['x-amz-content-sha256'];
		$signature       = $this->signature( $date, $stamp, $scope, $canonical );

		$headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->access_key . '/' . $scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;
		unset( $headers['host'] );
		$url = $this->scheme() . '://' . $host . $path . ( '' === $canonical_query ? '' : '?' . $canonical_query );
		return Http::expect( Http::request( $method, $url, $headers, in_array( $method, array( 'GET', 'DELETE' ), true ) ? null : $body ), $ok, 'S3' );
	}

	/**
	 * Signature for a canonical request.
	 *
	 * @param string $date      Ymd.
	 * @param string $stamp     ISO basic timestamp.
	 * @param string $scope     Credential scope.
	 * @param string $canonical Canonical request.
	 * @return string
	 */
	private function signature( string $date, string $stamp, string $scope, string $canonical ): string {
		$to_sign = "AWS4-HMAC-SHA256\n" . $stamp . "\n" . $scope . "\n" . hash( 'sha256', $canonical );
		$key     = hash_hmac( 'sha256', $date, 'AWS4' . $this->access_secret, true );
		$key     = hash_hmac( 'sha256', $this->region, $key, true );
		$key     = hash_hmac( 'sha256', 's3', $key, true );
		$key     = hash_hmac( 'sha256', 'aws4_request', $key, true );
		return hash_hmac( 'sha256', $to_sign, $key );
	}

	/**
	 * Canonical query string (sorted, RFC 3986 encoded).
	 *
	 * @param array<string,string> $query Parameters.
	 * @return string
	 */
	private static function canonical_query( array $query ): string {
		ksort( $query, SORT_STRING );
		$pairs = array();
		foreach ( $query as $name => $value ) {
			$pairs[] = rawurlencode( (string) $name ) . '=' . rawurlencode( $value );
		}
		return implode( '&', $pairs );
	}

	/**
	 * Parse an XML response body.
	 *
	 * @param string $body XML.
	 * @return \SimpleXMLElement
	 * @throws Remote_Error When it is not XML.
	 */
	private function parse_xml( string $body ): \SimpleXMLElement {
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, \SimpleXMLElement::class, LIBXML_NONET );
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			throw new Remote_Error( esc_html__( 'The storage service sent a reply that could not be read.', 'zinn-backups' ), 500 );
		}
		return $xml;
	}

	/**
	 * The text of the first element with this name.
	 *
	 * @param string $body XML.
	 * @param string $name Element name.
	 * @return string
	 */
	private function xml_value( string $body, string $name ): string {
		return preg_match( '#<' . preg_quote( $name, '#' ) . '>(.*?)</' . preg_quote( $name, '#' ) . '>#s', $body, $m ) ? html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1 ) : '';
	}

	/**
	 * Read up to `$length` bytes of a file from `$offset`.
	 *
	 * @param string $path   File.
	 * @param int    $offset Offset.
	 * @param int    $length Length.
	 * @return string
	 * @throws Remote_Error On a storage error.
	 */
	public static function read_file( string $path, int $offset, int $length ): string {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a backup part; WP_Filesystem cannot read a byte range.
		if ( false === $handle ) {
			throw new Remote_Error( esc_html__( 'A temporary backup file could not be read.', 'zinn-backups' ), 500 );
		}
		fseek( $handle, $offset );
		$data = (string) fread( $handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		return $data;
	}
}
