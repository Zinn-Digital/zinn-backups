<?php
/**
 * Is a PHP function safe to call on THIS host?
 *
 * ⛔⛔ Shared hosts list functions in php.ini `disable_functions` (disk_free_space, readlink,
 * getmypid, ignore_user_abort, stream_socket_client ...). On PHP 8 a disabled function is
 * UNDEFINED: calling it throws a fatal `Error`, and the `@` operator does NOT stop it. On
 * PHP 7 it still "exists" and returns null with a warning. So every call to a function a host
 * may disable goes through this check first and has a fallback (V1 ticket 13692, docs/72).
 * Enforced by scripts/php-disabled-functions-guard.py.
 *
 * @package zinn-backups
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'zinn_backups_can_call' ) ) {
	/**
	 * True only if the function exists AND is not listed in `disable_functions`.
	 *
	 * @param string $name A PHP function name.
	 * @return bool
	 */
	function zinn_backups_can_call( string $name ): bool {
		static $disabled = null;
		if ( ! function_exists( $name ) ) {
			return false;
		}
		if ( null === $disabled ) {
			$list     = function_exists( 'ini_get' ) ? (string) ini_get( 'disable_functions' ) : '';
			$disabled = array_flip( array_filter( array_map( 'trim', explode( ',', strtolower( $list ) ) ) ) );
		}
		return ! isset( $disabled[ strtolower( $name ) ] );
	}
}
