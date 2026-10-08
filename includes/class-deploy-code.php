<?php
/**
 * Reads a deploy code: another site's backup, handed over as time-limited download links.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * A deploy code is `zbdeploy1.` + the base64url of ONE link: a small JSON file, stored beside
 * the backup in the source site's storage, that lists a time-limited link for every file the
 * restore needs. So the code is short enough to paste, the receiving site never holds the
 * source site's storage keys, and the links stop working on their own (S3/R2: 24 hours;
 * Dropbox: four hours).
 *
 * ⚖️ Receiving one is a RESTORE, and restoring is never a paid feature (it is here in the
 * free plugin). Making one is Zinn® Backups Pro.
 *
 * ⛔ The code is a bearer secret for the whole site until it expires: anyone holding it can
 * download that backup. The screens that make and take it say so.
 */
final class Deploy_Code {

	public const PREFIX = 'zbdeploy1.';

	/**
	 * Read a code into what {@see Restore_Job::from_backup()} needs.
	 *
	 * @param string $code The code as pasted.
	 * @return array{remote:string, urls:array<string,string>, site:string}
	 * @throws \RuntimeException When the code is not valid or has expired.
	 */
	public static function read( string $code ): array {
		$code = trim( $code );
		if ( ! str_starts_with( $code, self::PREFIX ) ) {
			throw new \RuntimeException( esc_html__( 'That is not a deploy code. A deploy code starts with zbdeploy1.', 'zinn-backups' ) );
		}
		$body = strtr( substr( $code, strlen( self::PREFIX ) ), '-_', '+/' );
		$url  = (string) base64_decode( str_pad( $body, (int) ( ceil( strlen( $body ) / 4 ) * 4 ), '=' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the code is base64url of a link.
		if ( ! wp_http_validate_url( $url ) || ! str_starts_with( $url, 'https://' ) ) {
			throw new \RuntimeException( esc_html__( 'That deploy code is damaged. Copy it again from the other site.', 'zinn-backups' ) );
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 60,
				'limit_response_size' => 4194304,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			throw new \RuntimeException( esc_html__( 'The deploy code has expired or its backup was deleted. Make a new code on the other site.', 'zinn-backups' ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || 'zinn-backups-deploy' !== ( $data['format'] ?? '' ) ) {
			throw new \RuntimeException( esc_html__( 'That deploy code does not point at a Zinn® Backups backup.', 'zinn-backups' ) );
		}
		if ( (int) ( $data['expires'] ?? 0 ) < time() ) {
			throw new \RuntimeException( esc_html__( 'The deploy code has expired. Make a new code on the other site.', 'zinn-backups' ) );
		}
		$remote = (string) ( $data['remote'] ?? '' );
		if ( 1 !== preg_match( '#^zinn-backups/[a-z0-9-]+/[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$#', $remote ) ) {
			throw new \RuntimeException( esc_html__( 'That deploy code is damaged. Copy it again from the other site.', 'zinn-backups' ) );
		}
		$urls = array();
		foreach ( (array) ( $data['urls'] ?? array() ) as $path => $link ) {
			if ( is_string( $path ) && is_string( $link ) && str_starts_with( $path, 'zinn-backups/' ) && Import\Archive_Reader::safe_name( $path ) && str_starts_with( $link, 'https://' ) ) {
				$urls[ $path ] = $link;
			}
		}
		if ( ! isset( $urls[ $remote . '/manifest.json' ] ) ) {
			throw new \RuntimeException( esc_html__( 'That deploy code is damaged. Copy it again from the other site.', 'zinn-backups' ) );
		}
		return array(
			'remote' => $remote,
			'urls'   => $urls,
			'site'   => (string) ( $data['site'] ?? '' ),
		);
	}
}
