<?php
/**
 * Downloads an archive from a link, in resumable chunks.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * Shared links from Google Drive and Dropbox are turned into their direct-download form, so a
 * customer can import a backup they keep there by pasting its share link ("anyone with the
 * link"). ⛔ Requests go through `wp_safe_remote_request()`, which refuses addresses on the
 * server's own private network.
 */
final class Downloader {

	/**
	 * The direct-download form of a share link.
	 *
	 * @param string $url Link as pasted.
	 * @return string
	 */
	public static function normalize( string $url ): string {
		$url = trim( $url );
		if ( preg_match( '#^https://(?:drive|docs)\.google\.com/(?:file/d/|open\?id=|uc\?(?:[^ ]*&)?id=)([A-Za-z0-9_-]{10,})#', $url, $m ) ) {
			return 'https://drive.usercontent.google.com/download?id=' . $m[1] . '&export=download&confirm=t';
		}
		if ( preg_match( '#^https://(?:www\.)?dropbox\.com/#', $url ) ) {
			return (string) preg_replace( '/([?&])dl=0/', '$1dl=1', str_contains( $url, 'dl=' ) ? $url : $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'dl=1' );
		}
		return $url;
	}

	/**
	 * A file name for a link.
	 *
	 * @param string $url Link.
	 * @return string
	 */
	public static function name_for( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( rawurldecode( basename( $path ) ) );
		return '' === $name || 'download' === $name ? 'archive-' . substr( md5( $url ), 0, 8 ) : $name;
	}

	/**
	 * Download (more of) a link into a file until the deadline.
	 *
	 * @param string $url      Link (already normalised).
	 * @param string $target   Local file; its size is where the download resumes.
	 * @param float  $deadline microtime deadline.
	 * @param Job    $job      The job (for progress).
	 * @return bool Whether the download is complete.
	 * @throws Remote_Error On an HTTP error.
	 */
	public static function fetch( string $url, string $target, float $deadline, Job $job ): bool {
		while ( microtime( true ) < $deadline - 2 ) {
			clearstatcache( true, $target );
			$have     = file_exists( $target ) ? (int) filesize( $target ) : 0;
			$response = wp_safe_remote_request(
				$url,
				array(
					'method'      => 'GET',
					'timeout'     => 120,
					'redirection' => 5,
					'headers'     => array( 'range' => 'bytes=' . $have . '-' . ( $have + Storage\Destination::CHUNK - 1 ) ),
				)
			);
			if ( is_wp_error( $response ) ) {
				throw new Remote_Error( esc_html( $response->get_error_message() ), 0 );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( 416 === $status ) {
				return true;
			}
			if ( 200 === $status ) {
				if ( 0 !== $have ) {
					throw new Remote_Error( esc_html__( 'The server stopped honouring partial downloads. Start the import again.', 'zinn-backups' ), 500 );
				}
				$body = (string) wp_remote_retrieve_body( $response );
				if ( str_contains( (string) wp_remote_retrieve_header( $response, 'content-type' ), 'text/html' ) && str_contains( substr( $body, 0, 2000 ), '<html' ) ) {
					throw new Remote_Error( esc_html__( 'The link opened a web page instead of a file. For Google Drive and Dropbox, share the file with “anyone with the link” and paste that link.', 'zinn-backups' ), 400 );
				}
				file_put_contents( $target, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the whole file, from a server without range support.
				return true;
			}
			if ( 206 !== $status ) {
				throw new Remote_Error(
					sprintf(
						/* translators: %d: HTTP status code. */
						esc_html__( 'The link answered with HTTP %d.', 'zinn-backups' ),
						(int) $status
					),
					(int) $status
				);
			}
			$body = (string) wp_remote_retrieve_body( $response );
			file_put_contents( $target, $body, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- appending a downloaded chunk.
			$total = preg_match( '#/(\d+)$#', (string) wp_remote_retrieve_header( $response, 'content-range' ), $m ) ? (int) $m[1] : 0;
			$job->progress( $have + strlen( $body ), $total );
			if ( '' === $body || ( $total > 0 && $have + strlen( $body ) >= $total ) ) {
				return true;
			}
		}
		return false;
	}
}
