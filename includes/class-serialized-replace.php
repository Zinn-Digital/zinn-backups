<?php
/**
 * Find-and-replace that keeps PHP-serialized data valid.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * ⛔⛔ NEVER `unserialize()`. A restored database is data from another site — possibly an
 * imported archive from a stranger — and unserializing it instantiates whatever classes it
 * names. So the serialized text is PARSED here as text: every `s:N:"…"` string is rewritten
 * with the replacement applied and its byte length recomputed, objects and arrays are walked
 * without being built, and anything this parser does not understand is left exactly as it was.
 *
 * Strings that are themselves serialized (plugins double-serialize) are handled recursively.
 */
final class Serialized_Replace {

	/**
	 * Apply replacements to a value from the database.
	 *
	 * @param string               $value Value.
	 * @param array<string,string> $pairs Search => replace, applied in order.
	 * @return string
	 */
	public static function replace( string $value, array $pairs ): string {
		if ( ! self::contains_any( $value, $pairs ) ) {
			return $value;
		}
		if ( self::looks_serialized( $value ) ) {
			$pos    = 0;
			$result = self::parse( $value, $pos, $pairs, 0 );
			if ( null !== $result && strlen( $value ) === $pos ) {
				return $result;
			}
			return $value; // Not a value we can rewrite safely: leave it untouched.
		}
		return strtr( $value, $pairs );
	}

	/**
	 * Whether the value contains any search string.
	 *
	 * @param string               $value Value.
	 * @param array<string,string> $pairs Pairs.
	 * @return bool
	 */
	private static function contains_any( string $value, array $pairs ): bool {
		foreach ( $pairs as $search => $unused ) {
			if ( '' !== (string) $search && str_contains( $value, (string) $search ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cheap test for serialized text.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function looks_serialized( string $value ): bool {
		return 1 === preg_match( '/^(?:[aOC]:\d+:|s:\d+:"|[idb]:[^;]*;|N;)/', $value );
	}

	/**
	 * Parse one serialized value at `$pos` and return it rewritten, or null if it cannot be.
	 *
	 * @param string               $s     Serialized text.
	 * @param int                  $pos   Position, advanced past the value.
	 * @param array<string,string> $pairs Replacements.
	 * @param int                  $depth Nesting depth.
	 * @return string|null
	 */
	private static function parse( string $s, int &$pos, array $pairs, int $depth ): ?string {
		if ( $depth > 64 || ! isset( $s[ $pos ] ) ) {
			return null;
		}
		$type = $s[ $pos ];
		switch ( $type ) {
			case 'N':
				if ( 'N;' !== substr( $s, $pos, 2 ) ) {
					return null;
				}
				$pos += 2;
				return 'N;';
			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $s, ';', $pos );
				if ( false === $end || ':' !== ( $s[ $pos + 1 ] ?? '' ) ) {
					return null;
				}
				$out = substr( $s, $pos, $end - $pos + 1 );
				$pos = $end + 1;
				return $out;
			case 's':
				$string = self::read_string( $s, $pos );
				if ( null === $string || ';' !== ( $s[ $pos ] ?? '' ) ) {
					return null;
				}
				++$pos;
				$new = self::replace( $string, $pairs );
				return 's:' . strlen( $new ) . ':"' . $new . '";';
			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$pos += strlen( $m[0] );
				$out  = $m[0];
				for ( $i = 0; $i < (int) $m[1] * 2; $i++ ) {
					$item = self::parse( $s, $pos, 0 === $i % 2 ? array() : $pairs, $depth + 1 );
					if ( null === $item ) {
						return null;
					}
					$out .= $item;
				}
				if ( '}' !== ( $s[ $pos ] ?? '' ) ) {
					return null;
				}
				++$pos;
				return $out . '}';
			case 'O':
				$head_start = $pos;
				if ( ! preg_match( '/\GO:\d+:"/', $s, $m, 0, $pos ) ) {
					return null;
				}
				$class = self::read_string( $s, $pos, 'O' );
				if ( null === $class || ! preg_match( '/\G:(\d+):\{/', $s, $n, 0, $pos ) ) {
					return null;
				}
				$pos += strlen( $n[0] );
				$out  = substr( $s, $head_start, $pos - $head_start );
				for ( $i = 0; $i < (int) $n[1] * 2; $i++ ) {
					$item = self::parse( $s, $pos, 0 === $i % 2 ? array() : $pairs, $depth + 1 );
					if ( null === $item ) {
						return null;
					}
					$out .= $item;
				}
				if ( '}' !== ( $s[ $pos ] ?? '' ) ) {
					return null;
				}
				++$pos;
				return $out . '}';
			case 'C':
			case 'E':
				// Custom-serialized objects and enums: opaque. Copy them through unchanged.
				$start = $pos;
				$name  = self::read_string( $s, $pos, $type );
				if ( null === $name ) {
					return null;
				}
				if ( 'E' === $type ) {
					if ( ';' !== ( $s[ $pos ] ?? '' ) ) {
						return null;
					}
					++$pos;
					return substr( $s, $start, $pos - $start );
				}
				if ( ! preg_match( '/\G:(\d+):\{/', $s, $c, 0, $pos ) ) {
					return null;
				}
				$pos += strlen( $c[0] ) + (int) $c[1];
				if ( '}' !== ( $s[ $pos ] ?? '' ) ) {
					return null;
				}
				++$pos;
				return substr( $s, $start, $pos - $start );
		}
		return null;
	}

	/**
	 * Read `X:N:"…"` at `$pos` and return the string, leaving `$pos` after the closing quote.
	 *
	 * @param string $s    Serialized text.
	 * @param int    $pos  Position.
	 * @param string $kind The type letter.
	 * @return string|null
	 */
	private static function read_string( string $s, int &$pos, string $kind = 's' ): ?string {
		if ( ! preg_match( '/\G' . $kind . ':(\d+):"/', $s, $m, 0, $pos ) ) {
			return null;
		}
		$length = (int) $m[1];
		$start  = $pos + strlen( $m[0] );
		if ( '"' !== ( $s[ $start + $length ] ?? '' ) ) {
			return null;
		}
		$pos = $start + $length + 1;
		return substr( $s, $start, $length );
	}

	/**
	 * Build the replacement pairs for moving a site from one address (and folder) to another,
	 * longest first so a longer match is never pre-empted by a shorter one.
	 *
	 * @param array<int,array{0:string,1:string}> $moves [old, new] URLs or paths.
	 * @return array<string,string>
	 */
	public static function pairs( array $moves ): array {
		$pairs = array();
		foreach ( $moves as list( $old, $new ) ) {
			$old = untrailingslashit( (string) $old );
			$new = untrailingslashit( (string) $new );
			if ( '' === $old || $old === $new ) {
				continue;
			}
			if ( preg_match( '#^https?://#', $old ) ) {
				$bare_old = (string) preg_replace( '#^https?:#', '', $old );
				foreach ( array( 'https:', 'http:' ) as $scheme ) {
					$pairs[ $scheme . $bare_old ]                            = $new;
					$pairs[ str_replace( '/', '\\/', $scheme . $bare_old ) ] = str_replace( '/', '\\/', $new );
					$pairs[ rawurlencode( $scheme . $bare_old ) ]            = rawurlencode( $new );
				}
				$bare_new                                      = (string) preg_replace( '#^https?:#', '', $new );
				$pairs[ $bare_old ]                            = $bare_new;
				$pairs[ str_replace( '/', '\\/', $bare_old ) ] = str_replace( '/', '\\/', $bare_new );
			} else {
				$pairs[ $old ] = $new;
			}
		}
		uksort( $pairs, static fn( $a, $b ): int => strlen( (string) $b ) <=> strlen( (string) $a ) );
		return $pairs;
	}
}
