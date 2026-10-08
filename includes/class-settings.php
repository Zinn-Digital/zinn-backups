<?php
/**
 * Plugin settings and the saved storage destinations.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * One option holds everything; secrets inside it are sealed by {@see Secrets}.
 */
final class Settings {

	public const OPTION = 'zinn_backups_settings';

	/**
	 * Folders (relative to the WordPress root) left out of every backup by default: caches,
	 * other backup plugins' own archives, and update scratch space.
	 */
	public const DEFAULT_EXCLUDES = array(
		'wp-content/cache',
		'wp-content/upgrade',
		'wp-content/upgrade-temp-backup',
		'wp-content/litespeed',
		'wp-content/et-cache',
		'wp-content/updraft',
		'wp-content/ai1wm-backups',
		'wp-content/backups-dup-lite',
		'wp-content/backups-dup-pro',
		'wp-content/wpvividbackups',
		'wp-content/backwpup-temp',
		'wp-content/uploads/backwpup',
		'wp-content/uploads/zinn-migrate',
		'wp-content/uploads/zinn-backups-import',
	);

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array{destinations: array<int,array<string,mixed>>, include_core: bool, leave_out: string, part_mb: int}
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'destinations' => isset( $saved['destinations'] ) && is_array( $saved['destinations'] ) ? array_values( $saved['destinations'] ) : array(),
			'include_core' => ! empty( $saved['include_core'] ),
			'leave_out'    => isset( $saved['leave_out'] ) ? (string) $saved['leave_out'] : '',
			'part_mb'      => isset( $saved['part_mb'] ) ? max( 10, min( 2000, (int) $saved['part_mb'] ) ) : 100,
		);
	}

	/**
	 * Save the general settings (not destinations).
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return void
	 */
	public static function save_general( array $input ): void {
		$all                 = self::all();
		$all['include_core'] = ! empty( $input['include_core'] );
		$all['leave_out']    = implode( "\n", self::clean_lines( (string) ( $input['leave_out'] ?? '' ) ) );
		$all['part_mb']      = max( 10, min( 2000, (int) ( $input['part_mb'] ?? 100 ) ) );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Every exclusion, defaults first, as paths relative to the WordPress root.
	 *
	 * @return string[]
	 */
	public static function excludes(): array {
		$list   = array_merge( self::DEFAULT_EXCLUDES, self::clean_lines( self::all()['leave_out'] ) );
		$work   = Work_Dir::relative();
		$list[] = '' !== $work ? $work : 'wp-content/zinn-backups-';
		/**
		 * Filters the paths (relative to the WordPress root) that a backup leaves out.
		 *
		 * @param string[] $list Relative paths.
		 */
		return array_values( array_unique( (array) apply_filters( 'zinn_backups_excludes', $list ) ) );
	}

	/**
	 * How many destinations may be saved. One in the free plugin.
	 *
	 * @return int
	 */
	public static function destination_limit(): int {
		/**
		 * Filters how many storage destinations may be saved. Zinn® Backups Pro raises it.
		 *
		 * @param int $limit The limit.
		 */
		return max( 1, (int) apply_filters( 'zinn_backups_destination_limit', 1 ) );
	}

	/**
	 * The saved destinations, without secrets.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function destinations(): array {
		return self::all()['destinations'];
	}

	/**
	 * One destination by id.
	 *
	 * @param string $id Destination id.
	 * @return array<string,mixed>|null
	 */
	public static function destination( string $id ): ?array {
		foreach ( self::destinations() as $destination ) {
			if ( ( $destination['id'] ?? '' ) === $id ) {
				return $destination;
			}
		}
		return null;
	}

	/**
	 * Add or replace a destination.
	 *
	 * @param array<string,mixed>  $destination Destination record (id, type, label, config, enabled).
	 * @param array<string,string> $secrets     Secrets to seal; keys absent keep their saved value.
	 * @return array<string,mixed> The saved record.
	 * @throws \InvalidArgumentException When the limit is reached.
	 */
	public static function put_destination( array $destination, array $secrets ): array {
		$all      = self::all();
		$id       = (string) ( $destination['id'] ?? '' );
		$existing = null;
		foreach ( $all['destinations'] as $index => $row ) {
			if ( '' !== $id && ( $row['id'] ?? '' ) === $id ) {
				$existing = $index;
			}
		}
		if ( null === $existing && count( $all['destinations'] ) >= self::destination_limit() ) {
			throw new \InvalidArgumentException( esc_html__( 'Backups go to one storage destination. Edit or remove the one you have to use a different one.', 'zinn-backups' ) );
		}
		$old_secrets = null !== $existing ? Secrets::open( (string) ( $all['destinations'][ $existing ]['secret'] ?? '' ) ) : array();
		$record      = array(
			'id'      => '' !== $id ? $id : 'd' . bin2hex( random_bytes( 6 ) ),
			'type'    => sanitize_key( (string) ( $destination['type'] ?? '' ) ),
			'label'   => sanitize_text_field( (string) ( $destination['label'] ?? '' ) ),
			'config'  => is_array( $destination['config'] ?? null ) ? $destination['config'] : array(),
			'enabled' => ! isset( $destination['enabled'] ) || (bool) $destination['enabled'],
			'secret'  => Secrets::seal( array_merge( $old_secrets, array_filter( $secrets, static fn( $v ): bool => '' !== $v ) ) ),
		);
		if ( null !== $existing ) {
			$all['destinations'][ $existing ] = $record;
		} else {
			$all['destinations'][] = $record;
		}
		update_option( self::OPTION, $all, false );
		return $record;
	}

	/**
	 * Update only the secrets of a destination (e.g. a refreshed OAuth token).
	 *
	 * @param string               $id      Destination id.
	 * @param array<string,string> $secrets Secrets to merge.
	 * @return void
	 */
	public static function merge_secrets( string $id, array $secrets ): void {
		$all = self::all();
		foreach ( $all['destinations'] as $index => $row ) {
			if ( ( $row['id'] ?? '' ) === $id ) {
				$all['destinations'][ $index ]['secret'] = Secrets::seal( array_merge( Secrets::open( (string) ( $row['secret'] ?? '' ) ), $secrets ) );
				update_option( self::OPTION, $all, false );
				return;
			}
		}
	}

	/**
	 * Remove a destination.
	 *
	 * @param string $id Destination id.
	 * @return void
	 */
	public static function delete_destination( string $id ): void {
		$all                 = self::all();
		$all['destinations'] = array_values( array_filter( $all['destinations'], static fn( $row ): bool => ( $row['id'] ?? '' ) !== $id ) );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Split a textarea into clean relative paths.
	 *
	 * @param string $text Raw text.
	 * @return string[]
	 */
	public static function clean_lines( string $text ): array {
		$out = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( str_replace( '\\', '/', sanitize_text_field( $line ) ), "/ \t" );
			if ( '' !== $line && ! str_contains( $line, '..' ) ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
