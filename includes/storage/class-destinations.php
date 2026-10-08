<?php
/**
 * Builds storage clients from saved destinations, and describes each kind for the form.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups\Storage;

use ZinnDigital\Backups\Remote_Error;
use ZinnDigital\Backups\Secrets;
use ZinnDigital\Backups\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The kinds of storage and their form fields. Every kind is available in the free plugin;
 * the free plugin saves ONE destination (owner D9), Pro saves several.
 */
final class Destinations {

	/**
	 * S3-compatible providers: endpoint template, default region, path-style.
	 *
	 * @return array<string,array{label:string,endpoint:string,region:string,path_style:bool}>
	 */
	public static function s3_providers(): array {
		return array(
			'r2'           => array(
				'label'      => 'Cloudflare R2', // A product name: the same in every language.
				'endpoint'   => 'https://{account}.r2.cloudflarestorage.com',
				'region'     => 'auto',
				'path_style' => true,
			),
			'aws'          => array(
				'label'      => 'Amazon S3', // A product name: the same in every language.
				'endpoint'   => '',
				'region'     => 'us-east-1',
				'path_style' => false,
			),
			'wasabi'       => array(
				'label'      => 'Wasabi', // A product name: the same in every language.
				'endpoint'   => 'https://s3.{region}.wasabisys.com',
				'region'     => 'us-east-1',
				'path_style' => true,
			),
			'backblaze'    => array(
				'label'      => 'Backblaze B2', // A product name: the same in every language.
				'endpoint'   => 'https://s3.{region}.backblazeb2.com',
				'region'     => 'us-west-004',
				'path_style' => true,
			),
			'digitalocean' => array(
				'label'      => 'DigitalOcean Spaces', // A product name: the same in every language.
				'endpoint'   => 'https://{region}.digitaloceanspaces.com',
				'region'     => 'ams3',
				'path_style' => false,
			),
			'other'        => array(
				'label'      => __( 'Other S3-compatible storage', 'zinn-backups' ),
				'endpoint'   => '',
				'region'     => 'us-east-1',
				'path_style' => true,
			),
		);
	}

	/**
	 * Kinds of destination, for the form.
	 *
	 * @return array<string,string>
	 */
	public static function types(): array {
		return array(
			's3'      => __( 'Cloudflare R2, Amazon S3 or S3-compatible', 'zinn-backups' ),
			'dropbox' => 'Dropbox',
			'gdrive'  => 'Google Drive',
		);
	}

	/**
	 * The client for a saved destination.
	 *
	 * @param array<string,mixed> $record Saved destination.
	 * @return Destination
	 * @throws Remote_Error When the record cannot be used.
	 */
	public static function client( array $record ): Destination {
		$config  = (array) ( $record['config'] ?? array() );
		$secrets = Secrets::open( (string) ( $record['secret'] ?? '' ) );
		$id      = (string) ( $record['id'] ?? '' );
		$save    = static function ( array $tokens ) use ( $id ): void {
			Settings::merge_secrets( $id, $tokens );
		};
		switch ( $record['type'] ?? '' ) {
			case 's3':
				if ( '' === ( $secrets['access_secret'] ?? '' ) ) {
					throw new Remote_Error( esc_html__( 'The storage keys could not be read on this site. Enter the secret key again on the Storage tab.', 'zinn-backups' ), 401 );
				}
				return new S3(
					self::s3_endpoint( $config ),
					(string) ( $config['region'] ?? '' ),
					(string) ( $config['bucket'] ?? '' ),
					(string) ( $config['folder'] ?? '' ),
					(string) ( $config['access_key'] ?? '' ),
					(string) $secrets['access_secret'],
					! empty( $config['path_style'] )
				);
			case 'dropbox':
				return new Dropbox( self::dropbox_key( $config ), (string) ( $config['folder'] ?? '' ), $secrets, $save );
			case 'gdrive':
				return new Google_Drive( (string) ( $config['client_id'] ?? '' ), (string) ( $secrets['client_secret'] ?? '' ), (string) ( $config['folder'] ?? '' ), $secrets, $save );
		}
		throw new Remote_Error( esc_html__( 'This kind of storage is not known.', 'zinn-backups' ), 400 );
	}

	/**
	 * The endpoint for an S3 destination, from its provider and fields.
	 *
	 * @param array<string,mixed> $config Config.
	 * @return string
	 */
	public static function s3_endpoint( array $config ): string {
		$endpoint = trim( (string) ( $config['endpoint'] ?? '' ) );
		if ( '' !== $endpoint ) {
			return esc_url_raw( $endpoint, array( 'https', 'http' ) );
		}
		$providers = self::s3_providers();
		$provider  = $providers[ (string) ( $config['provider'] ?? 'other' ) ] ?? $providers['other'];
		return str_replace(
			array( '{account}', '{region}' ),
			array( sanitize_key( (string) ( $config['account'] ?? '' ) ), sanitize_key( (string) ( $config['region'] ?? $provider['region'] ) ) ),
			$provider['endpoint']
		);
	}

	/**
	 * The Dropbox app key: the site owner's own, or the one Zinn Digital® registered.
	 *
	 * @param array<string,mixed> $config Config.
	 * @return string
	 */
	public static function dropbox_key( array $config ): string {
		$own = trim( (string) ( $config['app_key'] ?? '' ) );
		if ( '' !== $own ) {
			return $own;
		}
		/**
		 * Filters the Dropbox app key used when the site owner has not entered their own.
		 *
		 * @param string $key App key ('' when none is configured).
		 */
		return (string) apply_filters( 'zinn_backups_dropbox_app_key', defined( 'ZINN_BACKUPS_DROPBOX_APP_KEY' ) ? (string) constant( 'ZINN_BACKUPS_DROPBOX_APP_KEY' ) : '' );
	}

	/**
	 * Turn raw form input into a record and its secrets, validated.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array{0:array<string,mixed>,1:array<string,string>}
	 * @throws \InvalidArgumentException On invalid input.
	 */
	public static function from_input( array $input ): array {
		$type = sanitize_key( (string) ( $input['type'] ?? '' ) );
		if ( ! isset( self::types()[ $type ] ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Choose a kind of storage.', 'zinn-backups' ) );
		}
		$text    = static fn( string $key ): string => sanitize_text_field( (string) ( $input[ $key ] ?? '' ) );
		$folder  = trim( str_replace( '\\', '/', $text( 'folder' ) ), '/' );
		$config  = array( 'folder' => $folder );
		$secrets = array();
		if ( 's3' === $type ) {
			$provider = $text( 'provider' );
			$provider = isset( self::s3_providers()[ $provider ] ) ? $provider : 'other';
			$config  += array(
				'provider'   => $provider,
				'account'    => $text( 'account' ),
				'endpoint'   => esc_url_raw( $text( 'endpoint' ), array( 'https', 'http' ) ),
				'region'     => '' !== $text( 'region' ) ? $text( 'region' ) : self::s3_providers()[ $provider ]['region'],
				'bucket'     => $text( 'bucket' ),
				'access_key' => $text( 'access_key' ),
				'path_style' => isset( $input['path_style'] ) ? ! empty( $input['path_style'] ) : self::s3_providers()[ $provider ]['path_style'],
			);
			if ( '' === $config['bucket'] || '' === $config['access_key'] ) {
				throw new \InvalidArgumentException( esc_html__( 'Enter the bucket name and the access key.', 'zinn-backups' ) );
			}
			if ( 'r2' === $provider && '' === $config['account'] && '' === $config['endpoint'] ) {
				throw new \InvalidArgumentException( esc_html__( 'Enter your Cloudflare account id (or the full R2 endpoint).', 'zinn-backups' ) );
			}
			if ( 'other' === $provider && '' === $config['endpoint'] ) {
				throw new \InvalidArgumentException( esc_html__( 'Enter the endpoint address of your storage.', 'zinn-backups' ) );
			}
			$secrets['access_secret'] = trim( (string) ( $input['access_secret'] ?? '' ) );
		} elseif ( 'dropbox' === $type ) {
			$config['app_key'] = $text( 'app_key' );
		} else {
			$config['client_id']      = $text( 'client_id' );
			$secrets['client_secret'] = trim( (string) ( $input['client_secret'] ?? '' ) );
			if ( '' === $config['client_id'] ) {
				throw new \InvalidArgumentException( esc_html__( 'Enter the OAuth client id from your Google Cloud project.', 'zinn-backups' ) );
			}
		}
		$record = array(
			'id'     => sanitize_key( (string) ( $input['id'] ?? '' ) ),
			'type'   => $type,
			'label'  => '' !== $text( 'label' ) ? $text( 'label' ) : self::types()[ $type ],
			'config' => $config,
		);
		return array( $record, $secrets );
	}

	/**
	 * Whether the destination has what it needs to be used (signed in, key present).
	 *
	 * @param array<string,mixed> $record Saved destination.
	 * @return bool
	 */
	public static function is_ready( array $record ): bool {
		$secrets = Secrets::open( (string) ( $record['secret'] ?? '' ) );
		return match ( $record['type'] ?? '' ) {
			's3'              => '' !== ( $secrets['access_secret'] ?? '' ),
			'dropbox', 'gdrive' => '' !== ( $secrets['refresh_token'] ?? '' ),
			default           => false,
		};
	}

	/**
	 * Saved destinations that are switched on and ready, as [record, client] pairs.
	 *
	 * @return array<int,array{0:array<string,mixed>,1:Destination}>
	 */
	public static function active(): array {
		$out = array();
		foreach ( array_slice( Settings::destinations(), 0, Settings::destination_limit() ) as $record ) {
			if ( empty( $record['enabled'] ) || ! self::is_ready( $record ) ) {
				continue;
			}
			try {
				$out[] = array( $record, self::client( $record ) );
			} catch ( Remote_Error ) {
				continue;
			}
		}
		return $out;
	}
}
