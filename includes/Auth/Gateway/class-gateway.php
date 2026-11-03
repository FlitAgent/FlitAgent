<?php
/**
 * Gateway authentication: registration.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Registers gateway pairing and signed-request authentication. The gateway is on by default (Config::url()). When an
 * override of the gateway URL is invalid, only the wp-admin forms are hooked: they say why, and Disconnect site still
 * works; nothing authenticates a request or calls the gateway. One line in Plugin::boot().
 */
final class Gateway {

	/**
	 * Hooks the wp-admin section and, when the gateway URL is valid, the authenticator and the calls to the gateway.
	 */
	public static function register(): void {
		$url     = Config::url();
		$store   = new WpStore();
		$keys    = new Keys( $store, $url );
		$section = new AdminSection( $url, $store, $keys );
		if ( '' === $url ) {
			$section->register();
			return;
		}
		( new Authenticator( new Verifier( $store, array( $keys, 'key' ) ), $store ) )->register();
		$section->register();
		add_action( Keys::CRON_HOOK, array( $keys, 'refresh_if_connected' ) );
		add_action( 'admin_init', array( $keys, 'sync_schedule' ) );
		StatusNudge::register( $store, $url );
	}
}
