<?php
/**
 * Gateway authentication: registration.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Registers gateway pairing and signed-request authentication. The gateway is on by default (Config::url()); it is
 * left unhooked only when an override of the gateway URL is invalid. One line in Plugin::boot().
 */
final class Gateway {

	/**
	 * Hooks the authenticator and the wp-admin section when the gateway URL is valid.
	 */
	public static function register(): void {
		$url = Config::url();
		if ( '' === $url ) {
			return;
		}
		$store = new WpStore();
		$keys  = new Keys( $store, $url );
		( new Authenticator( new Verifier( $store, array( $keys, 'key' ) ), $store ) )->register();
		( new AdminSection( $url, $store, $keys ) )->register();
		add_action( Keys::CRON_HOOK, array( $keys, 'refresh_if_connected' ) );
		add_action( 'admin_init', array( $keys, 'sync_schedule' ) );
		StatusNudge::register( $store, $url );
	}
}
