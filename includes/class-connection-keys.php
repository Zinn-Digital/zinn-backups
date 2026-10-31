<?php
/**
 * The keys that connect a storage destination: what they are, how they are checked and shown.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * Behind `wp zinn-backups key set|get|remove`.
 *
 * ⛔ A key is never shown in full — not by `get`, not in a success line, not in an error. Errors
 * name the key, never echo the value. `set` takes the value from an environment variable or
 * STDIN (a hidden prompt on a terminal), never as an argument, so it is not left in the shell
 * history or the process list. Secret keys are stored sealed ({@see Secrets}); identifiers (an
 * access key id, an app key, a client id) are stored in the destination's settings.
 */
final class Connection_Keys {

	/**
	 * Every key, per kind of destination: where it is stored and what a valid one looks like.
	 *
	 * `forget` lists cached secrets that stop being valid when the key changes (an access token
	 * minted with the old refresh token or client).
	 *
	 * @var array<string,array<string,array{store:string,field:string,pattern:string,forget:string[]}>>
	 */
	private const KEYS = array(
		's3'      => array(
			'access-key' => array(
				'store'   => 'config',
				'field'   => 'access_key',
				'pattern' => '/^[A-Za-z0-9]{16,128}$/D',
				'forget'  => array(),
			),
			'secret-key' => array(
				'store'   => 'secret',
				'field'   => 'access_secret',
				'pattern' => '/^[\x21-\x7E]{16,256}$/D',
				'forget'  => array(),
			),
		),
		'dropbox' => array(
			'app-key'       => array(
				'store'   => 'config',
				'field'   => 'app_key',
				'pattern' => '/^[A-Za-z0-9]{8,64}$/D',
				'forget'  => array( 'access_token', 'expires_at' ),
			),
			'refresh-token' => array(
				'store'   => 'secret',
				'field'   => 'refresh_token',
				'pattern' => '/^[A-Za-z0-9_\-]{20,1024}$/D',
				'forget'  => array( 'access_token', 'expires_at' ),
			),
		),
		'gdrive'  => array(
			'client-id'     => array(
				'store'   => 'config',
				'field'   => 'client_id',
				'pattern' => '/^[0-9]{3,32}-[a-z0-9]{8,64}\.apps\.googleusercontent\.com$/D',
				'forget'  => array( 'access_token', 'expires_at' ),
			),
			'client-secret' => array(
				'store'   => 'secret',
				'field'   => 'client_secret',
				'pattern' => '/^[A-Za-z0-9_\-]{16,128}$/D',
				'forget'  => array( 'access_token', 'expires_at' ),
			),
			'refresh-token' => array(
				'store'   => 'secret',
				'field'   => 'refresh_token',
				'pattern' => '/^[A-Za-z0-9_\-\/.]{20,1024}$/D',
				'forget'  => array( 'access_token', 'expires_at' ),
			),
		),
	);

	/**
	 * The key names a kind of destination has.
	 *
	 * @param string $type Destination type.
	 * @return string[]
	 */
	public static function names( string $type ): array {
		return array_keys( self::KEYS[ $type ] ?? array() );
	}

	/**
	 * A key's definition.
	 *
	 * @param string $type Destination type.
	 * @param string $name Key name.
	 * @return array{store:string,field:string,pattern:string,forget:string[]}
	 * @throws \InvalidArgumentException When the destination has no such key.
	 */
	public static function definition( string $type, string $name ): array {
		if ( ! isset( self::KEYS[ $type ][ $name ] ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: 1: key name typed, 2: the valid key names, comma separated. */
					esc_html__( 'This destination has no key called “%1$s”. Its keys: %2$s.', 'zinn-backups' ),
					esc_html( sanitize_key( $name ) ),
					esc_html( implode( ', ', self::names( $type ) ) )
				)
			);
		}
		return self::KEYS[ $type ][ $name ];
	}

	/**
	 * Whether a key holds a secret (sealed) rather than an identifier.
	 *
	 * @param string $type Destination type.
	 * @param string $name Key name.
	 * @return bool
	 */
	public static function is_secret( string $type, string $name ): bool {
		return 'secret' === self::definition( $type, $name )['store'];
	}

	/**
	 * Clean a value as typed or piped in, and check it is a plausible key of that kind.
	 *
	 * ⛔ The error never contains the value: it is a secret, and errors end up in logs.
	 *
	 * @param string $type  Destination type.
	 * @param string $name  Key name.
	 * @param string $value Raw value.
	 * @return string The cleaned value.
	 * @throws \InvalidArgumentException When it is empty or not a valid key of that kind.
	 */
	public static function validate( string $type, string $name, string $value ): string {
		$definition = self::definition( $type, $name );
		$value      = trim( $value, " \t\r\n\0\x0B" );
		if ( '' === $value ) {
			throw new \InvalidArgumentException( esc_html__( 'No key was given. Pipe it in on STDIN or name an environment variable with --from-env.', 'zinn-backups' ) );
		}
		if ( 1 !== preg_match( $definition['pattern'], $value ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: %s: key name, e.g. secret-key. */
					esc_html__( 'That does not look like a valid %s (wrong length, or characters a key of this kind never contains). Nothing was saved.', 'zinn-backups' ),
					esc_html( $name )
				)
			);
		}
		return $value;
	}

	/**
	 * A key as it may be shown: never in full.
	 *
	 * Sixteen characters or more show their last four; anything shorter shows nothing of itself.
	 *
	 * @param string $value The key.
	 * @return string
	 */
	public static function mask( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		return strlen( $value ) >= 16 ? '****' . substr( $value, -4 ) : '********';
	}

	/**
	 * Pick the destination a key command acts on.
	 *
	 * @param string $id Destination id, or '' for "the only one".
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When there is none, or more than one and no id.
	 */
	public static function destination( string $id ): array {
		if ( '' !== $id ) {
			$record = Settings::destination( $id );
			if ( null === $record ) {
				throw new \InvalidArgumentException( esc_html__( 'No destination with that id. See: wp zinn-backups destination list', 'zinn-backups' ) );
			}
			return $record;
		}
		$all = Settings::destinations();
		if ( 1 === count( $all ) ) {
			return $all[0];
		}
		if ( array() === $all ) {
			throw new \InvalidArgumentException( esc_html__( 'There is no storage destination yet. Add one first: wp zinn-backups destination add', 'zinn-backups' ) );
		}
		throw new \InvalidArgumentException( esc_html__( 'There is more than one destination: choose one with --id. See: wp zinn-backups destination list', 'zinn-backups' ) );
	}

	/**
	 * Every key of a destination, masked.
	 *
	 * @param array<string,mixed> $record Saved destination.
	 * @return array<int,array{key:string,kind:string,value:string}>
	 */
	public static function shown( array $record ): array {
		$type    = (string) ( $record['type'] ?? '' );
		$secrets = Secrets::open( (string) ( $record['secret'] ?? '' ) );
		$config  = is_array( $record['config'] ?? null ) ? $record['config'] : array();
		$rows    = array();
		foreach ( self::KEYS[ $type ] ?? array() as $name => $definition ) {
			$raw    = 'secret' === $definition['store'] ? (string) ( $secrets[ $definition['field'] ] ?? '' ) : (string) ( $config[ $definition['field'] ] ?? '' );
			$rows[] = array(
				'key'   => $name,
				'kind'  => 'secret' === $definition['store'] ? 'secret' : 'identifier',
				'value' => '' !== $raw ? self::mask( $raw ) : __( '(not set)', 'zinn-backups' ),
			);
		}
		return $rows;
	}

	/**
	 * Save a key on a destination.
	 *
	 * @param array<string,mixed> $record Saved destination.
	 * @param string              $name   Key name.
	 * @param string              $value  Raw value.
	 * @return string The saved value, masked.
	 * @throws \InvalidArgumentException When the key or value is not valid.
	 */
	public static function set( array $record, string $name, string $value ): string {
		$type  = (string) ( $record['type'] ?? '' );
		$value = self::validate( $type, $name, $value );
		$def   = self::definition( $type, $name );
		Settings::put_key( (string) $record['id'], $def['store'], $def['field'], $value, $def['forget'] );
		return self::mask( $value );
	}

	/**
	 * Remove a key from a destination.
	 *
	 * @param array<string,mixed> $record Saved destination.
	 * @param string              $name   Key name.
	 * @return void
	 * @throws \InvalidArgumentException When the destination has no such key.
	 */
	public static function remove( array $record, string $name ): void {
		$def = self::definition( (string) ( $record['type'] ?? '' ), $name );
		Settings::put_key( (string) $record['id'], $def['store'], $def['field'], null, $def['forget'] );
	}
}
