<?php
/**
 * Encrypts storage credentials before they are saved in the database.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * ⛔ A storage key in `wp_options` in plain text would be in every database dump — including
 * the backups this plugin makes. So it is sealed with libsodium (`secretbox`) under a key
 * derived from the site's `wp-config.php` secrets, which are not in the database.
 *
 * A database copied to another site cannot open these values there: that site reconnects
 * its own storage, which is the safe outcome.
 */
final class Secrets {

	private const PREFIX = 'zbs1:';

	/**
	 * Seal an array of secrets.
	 *
	 * @param array<string,string> $values Secret values.
	 * @return string
	 */
	public static function seal( array $values ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( (string) wp_json_encode( $values ), $nonce, self::key() );
		return self::PREFIX . sodium_bin2base64( $nonce . $box, SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	/**
	 * Open sealed secrets. Returns an empty array when they cannot be opened.
	 *
	 * @param string $sealed The sealed string.
	 * @return array<string,string>
	 */
	public static function open( string $sealed ): array {
		if ( ! str_starts_with( $sealed, self::PREFIX ) ) {
			return array();
		}
		try {
			$raw = sodium_base642bin( substr( $sealed, strlen( self::PREFIX ) ), SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException ) {
			return array();
		}
		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return array();
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);
		if ( false === $plain ) {
			return array();
		}
		$values = json_decode( $plain, true );
		return is_array( $values ) ? array_map( 'strval', $values ) : array();
	}

	/**
	 * The encryption key.
	 *
	 * @return string
	 */
	private static function key(): string {
		$material = defined( 'SECURE_AUTH_KEY' ) && '' !== (string) SECURE_AUTH_KEY
			? (string) SECURE_AUTH_KEY . ( defined( 'LOGGED_IN_KEY' ) ? (string) LOGGED_IN_KEY : '' )
			: wp_salt( 'secure_auth' );
		return sodium_crypto_generichash( 'zinn-backups-secrets|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
