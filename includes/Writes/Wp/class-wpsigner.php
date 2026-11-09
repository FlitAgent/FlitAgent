<?php
/**
 * Approval signing with a site key.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Wp;

use FlitAgent\Writes\Signer;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-SHA256 keyed with `wp_salt( 'auth' )`. The key never leaves the site; rotating the salts invalidates
 * outstanding approvals.
 */
final class WpSigner implements Signer {

	/**
	 * Signs a message.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public function sign( string $message ): string {
		return hash_hmac( 'sha256', $message, wp_salt( 'auth' ) );
	}
}
