<?php
/**
 * A thin wrapper over the WordPress HTTP API that turns every failure into an exception.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * ⛔ Every storage call goes through here, so a provider's error text reaches the person who
 * has to fix it (wrong key, full Dropbox, bucket missing) instead of a bare "failed".
 */
final class Http {

	/**
	 * Send a request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null          $body    Request body.
	 * @param int                  $timeout Seconds.
	 * @return array{status:int, headers:array<string,string>, body:string}
	 * @throws Remote_Error On a transport failure.
	 */
	public static function request( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = 120 ): array {
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $timeout,
			'redirection' => 0,
			'user-agent'  => 'ZinnBackups/' . ZINN_BACKUPS_VERSION . '; ' . home_url( '/' ),
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new Remote_Error(
				/* translators: %s: the network error message. */
				sprintf( esc_html__( 'The storage service could not be reached: %s', 'zinn-backups' ), esc_html( $response->get_error_message() ) ),
				0
			);
		}
		$out_headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$out_headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
		}
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $out_headers,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Throw unless the status is in the accepted set.
	 *
	 * @param array{status:int, headers:array<string,string>, body:string} $response The response.
	 * @param int[]                                                        $ok       Accepted statuses.
	 * @param string                                                       $service  Name of the service, for the message.
	 * @return array{status:int, headers:array<string,string>, body:string}
	 * @throws Remote_Error When the status is not accepted.
	 */
	public static function expect( array $response, array $ok, string $service ): array {
		if ( in_array( $response['status'], $ok, true ) ) {
			return $response;
		}
		$detail = self::error_detail( $response['body'] );
		throw new Remote_Error(
			sprintf(
				/* translators: 1: service name, 2: HTTP status code, 3: the service's own error text. */
				esc_html__( '%1$s answered with HTTP %2$d: %3$s', 'zinn-backups' ),
				esc_html( $service ),
				(int) $response['status'],
				esc_html( $detail )
			),
			(int) $response['status']
		);
	}

	/**
	 * Pull a readable message out of a JSON or XML error body.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	public static function error_detail( string $body ): string {
		$json = json_decode( $body, true );
		if ( is_array( $json ) ) {
			foreach ( array( 'error_summary', 'error_description', 'message' ) as $key ) {
				if ( isset( $json[ $key ] ) && is_string( $json[ $key ] ) ) {
					return $json[ $key ];
				}
			}
			if ( isset( $json['error']['message'] ) && is_string( $json['error']['message'] ) ) {
				return $json['error']['message'];
			}
			if ( isset( $json['error'] ) && is_string( $json['error'] ) ) {
				return $json['error'];
			}
		}
		if ( preg_match( '#<Message>(.*?)</Message>#s', $body, $m ) ) {
			$code = preg_match( '#<Code>(.*?)</Code>#s', $body, $c ) ? $c[1] . ': ' : '';
			return $code . $m[1];
		}
		$text = trim( wp_strip_all_tags( $body ) );
		return '' === $text ? __( 'no details given', 'zinn-backups' ) : substr( $text, 0, 300 );
	}
}
