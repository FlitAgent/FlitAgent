<?php
/**
 * Signing port.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * Signs approval bindings with a key that stays on the site.
 */
interface Signer {

	/**
	 * HMAC-SHA256 of a message, as 64 hex characters.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public function sign( string $message ): string;
}
