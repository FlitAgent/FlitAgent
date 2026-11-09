<?php
/**
 * Builds the Gateway for WordPress.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Wp;

use FlitAgent\Tools\Registry;
use FlitAgent\Writes\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Wires Gateway to the WordPress adapters and the registered write tools.
 */
final class Factory {

	/**
	 * The Gateway for this request.
	 *
	 * @return Gateway
	 */
	public static function gateway(): Gateway {
		return new Gateway( Registry::writes(), new WpStore(), new WpPolicy(), new WpIdentity(), new WpClock(), new WpSigner() );
	}
}
