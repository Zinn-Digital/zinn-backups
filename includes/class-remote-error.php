<?php
/**
 * An error a remote storage service returned.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * The exception code is the HTTP status (0 for a transport failure), so a caller can tell
 * "not found" (404) from "try again" (429, 5xx) without parsing a message.
 */
class Remote_Error extends \RuntimeException {

	/**
	 * Whether repeating the same call later may succeed.
	 *
	 * @return bool
	 */
	public function is_transient(): bool {
		$code = $this->getCode();
		return 0 === $code || 408 === $code || 429 === $code || $code >= 500;
	}
}
