<?php
/**
 * The nudge sent when the Connect screen opens while the site is connected.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * When an account or its owner disconnects the site on flitagent.com, flitagent.com tells it
 * (Mcp\Server::SITE_DISCONNECTED). If that notice never arrived (the site was down), the site would show itself
 * connected for good, so opening the Connect
 * screen while connected schedules one nudge (Nudge, the connection id only) at most once per INTERVAL; flitagent.com
 * then sends the notice again if the connection ended there, or checks the site's state as after any nudge. The nudge
 * goes on WP-Cron (HOOK), so the screen never waits for flitagent.com. A nudge proves nothing and changes nothing here.
 */
final class StatusNudge {

	/**
	 * Event: one nudge for the connection the site holds when it runs.
	 */
	const HOOK = 'flitagent_gateway_status_nudge';

	/**
	 * Least seconds between two nudges (an hour).
	 */
	const INTERVAL = 3600;

	/**
	 * The Connect screen opened: schedules a nudge now, while the site is connected and none was scheduled within INTERVAL.
	 *
	 * @param Store $store Store.
	 * @param int   $now   Current Unix time.
	 * @return bool Whether a nudge was scheduled.
	 */
	public static function on_view( Store $store, int $now ): bool {
		if ( null === $store->connection() ) {
			return false;
		}
		$last = (int) get_option( WpStore::OPTION_STATUS_NUDGE, 0 );
		if ( $last <= $now && $now - $last < self::INTERVAL ) {
			return false;
		}
		update_option( WpStore::OPTION_STATUS_NUDGE, $now, false );
		return true === wp_schedule_single_event( $now, self::HOOK );
	}

	/**
	 * The HOOK event: nudges flitagent.com with the connection the site holds, if any.
	 *
	 * @param Store    $store Store.
	 * @param callable $send  `fn( string $connection_id ): bool` (Nudge::send()).
	 * @return bool Whether a nudge was sent and flitagent.com took it.
	 */
	public static function run( Store $store, callable $send ): bool {
		$connection = $store->connection();
		return null !== $connection && true === call_user_func( $send, $connection->id() );
	}

	/**
	 * Hooks the event: the live store and a Nudge to the gateway.
	 *
	 * @param Store  $store   Store.
	 * @param string $gateway Gateway URL.
	 */
	public static function register( Store $store, string $gateway ): void {
		add_action(
			self::HOOK,
			static function () use ( $store, $gateway ): void {
				self::run( $store, array( new Nudge( $gateway ), 'send' ) );
			}
		);
	}

	/**
	 * Removes a scheduled nudge (on uninstall).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
