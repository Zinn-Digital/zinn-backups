<?php
/**
 * One step at a time.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * ⛔ An OS file lock, not a database row: the lock must hold while a restore replaces the
 * database, and it must let go by itself when a request is killed mid-step — `flock` is
 * released by the operating system when the process ends, a row is not.
 */
final class Lock {

	/**
	 * Open lock handle.
	 *
	 * @var resource|null
	 */
	private static $handle = null;

	/**
	 * Try to take the lock without waiting.
	 *
	 * @return bool
	 */
	public static function acquire(): bool {
		if ( null !== self::$handle ) {
			return true;
		}
		$handle = fopen( Work_Dir::path() . '/step.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an OS lock file; WP_Filesystem has no flock.
		if ( false === $handle ) {
			return false;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			return false;
		}
		self::$handle = $handle;
		return true;
	}

	/**
	 * Release the lock.
	 *
	 * @return void
	 */
	public static function release(): void {
		if ( null === self::$handle ) {
			return;
		}
		flock( self::$handle, LOCK_UN );
		fclose( self::$handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- an OS lock file.
		self::$handle = null;
	}
}
