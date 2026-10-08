<?php
/**
 * Encrypted backup files: libsodium secretstream (XChaCha20-Poly1305) under a key derived
 * from a passphrase with Argon2id.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * ⚖️ Restoring is never a paid feature: a site whose Pro licence has lapsed must still be able
 * to open the encrypted backups it made, so DECRYPTION lives here in the free plugin. Pro
 * decides when to encrypt.
 *
 * File layout: `ZBENC1` · 16-byte salt · 24-byte stream header · chunks of 1 MiB plain text,
 * each sealed with a 17-byte tag; the last chunk carries the FINAL tag, so a truncated file
 * fails to decrypt instead of restoring a shorter site.
 */
final class Crypto {

	private const MAGIC = 'ZBENC1';
	private const PLAIN = 1048576;

	/**
	 * Derived keys by salt, for this request.
	 *
	 * @var array<string,string>
	 */
	private static array $keys = array();

	/**
	 * Whether a file is one of ours, encrypted.
	 *
	 * @param string $path File.
	 * @return bool
	 */
	public static function is_encrypted( string $path ): bool {
		return self::MAGIC === (string) file_get_contents( $path, false, null, 0, strlen( self::MAGIC ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- magic bytes of a local file.
	}

	/**
	 * Encrypt a file.
	 *
	 * @param string $from       Plain file.
	 * @param string $to         Encrypted file.
	 * @param string $passphrase Passphrase.
	 * @return void
	 */
	public static function encrypt_file( string $from, string $to, string $passphrase ): void {
		$salt                   = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
		list( $state, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( self::key( $passphrase, $salt ) );
		$in                     = fopen( $from, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming encryption of a backup part.
		$out                    = fopen( $to, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see above.
		fwrite( $out, self::MAGIC . $salt . $header ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
		$size = (int) filesize( $from );
		$read = 0;
		do {
			$plain = (string) fread( $in, self::PLAIN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
			$read += strlen( $plain );
			$tag   = $read >= $size ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
			fwrite( $out, sodium_crypto_secretstream_xchacha20poly1305_push( $state, $plain, '', $tag ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
		} while ( $read < $size );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
	}

	/**
	 * Decrypt a file.
	 *
	 * @param string $from       Encrypted file.
	 * @param string $to         Plain file.
	 * @param string $passphrase Passphrase.
	 * @return void
	 * @throws \RuntimeException On a wrong passphrase or a damaged file.
	 */
	public static function decrypt_file( string $from, string $to, string $passphrase ): void {
		$in    = fopen( $from, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming decryption of a backup part.
		$magic = (string) fread( $in, strlen( self::MAGIC ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		$salt  = (string) fread( $in, SODIUM_CRYPTO_PWHASH_SALTBYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		$head  = (string) fread( $in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		if ( self::MAGIC !== $magic ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			throw new \RuntimeException( esc_html__( 'This file is not an encrypted Zinn® Backups file.', 'zinn-backups' ) );
		}
		$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( $head, self::key( $passphrase, $salt ) );
		$out   = fopen( $to, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see above.
		$final = false;
		while ( ! feof( $in ) ) {
			$cipher = (string) fread( $in, self::PLAIN + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
			if ( '' === $cipher ) {
				break;
			}
			$result = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $cipher );
			if ( false === $result ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
				wp_delete_file( $to );
				throw new \RuntimeException( esc_html__( 'The backup could not be decrypted: the passphrase is wrong or the file is damaged.', 'zinn-backups' ) );
			}
			fwrite( $out, $result[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
			$final = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $result[1];
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		if ( ! $final ) {
			wp_delete_file( $to );
			throw new \RuntimeException( esc_html__( 'The encrypted backup file is incomplete.', 'zinn-backups' ) );
		}
	}

	/**
	 * Key from passphrase and salt (Argon2id, interactive limits so a shared host can run it).
	 *
	 * @param string $passphrase Passphrase.
	 * @param string $salt       Salt.
	 * @return string
	 */
	private static function key( string $passphrase, string $salt ): string {
		$id = hash( 'sha256', $salt . "\0" . $passphrase );
		if ( ! isset( self::$keys[ $id ] ) ) {
			self::$keys[ $id ] = sodium_crypto_pwhash(
				SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
				$passphrase,
				$salt,
				SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
				SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
				SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
			);
		}
		return self::$keys[ $id ];
	}
}
