<?php
/**
 * What deleting the plugin removes.
 *
 * @package FlitAgent
 */

namespace FlitAgent;

defined( 'ABSPATH' ) || exit;

use FlitAgent\Admin\Onboarding;
use FlitAgent\Admin\Settings;
use FlitAgent\Auth\Gateway\Config;
use FlitAgent\Auth\Gateway\Keys;
use FlitAgent\Auth\Gateway\Nudge;
use FlitAgent\Auth\Gateway\StatusNudge;
use FlitAgent\Auth\Gateway\Upgrade;
use FlitAgent\Auth\Gateway\WpStore;

/**
 * Runs from uninstall.php, on every site of a network: a network uninstall runs once, on the main site, while each site
 * has its own options and tables. A site holding a connection first nudges flitagent.com with its connection id (Nudge),
 * so flitagent.com disconnects the site and its AI tools. Every site then loses the gateway key refresh cron, the
 * pre-release OAuth tables and the flitagent.com connection. With "Remove data on uninstall" off the settings and the
 * install record stay, so a reinstall skips the setup guide; with it on the site also loses the install record (#134,
 * so a reinstall is a fresh installation), the gateway's data, the upgrade state and what FlitAgent 1.0.0's write layer
 * stored (its options and tables, which this version never creates but a site updated from 1.0.0 still has).
 */
final class Uninstall {

	/**
	 * Options of FlitAgent 1.0.0's write layer.
	 */
	const LEGACY_OPTIONS = array( 'flitagent_writes_enabled', 'flitagent_auto_approve', 'flitagent_approval_ttl', 'flitagent_db_version', Settings::OPTION_REMOVE_DATA );

	/**
	 * Tables of FlitAgent 1.0.0's write layer, without the table prefix.
	 */
	const LEGACY_TABLES = array( 'flitagent_changes', 'flitagent_receipts', 'flitagent_idempotency' );

	/**
	 * Seconds an uninstall may spend nudging; after that no other site of the network is nudged.
	 */
	const NUDGE_BUDGET = 10;

	/**
	 * Uninstalls on the site, or on every site of a network. Each site holding a connection is nudged until one nudge is
	 * not answered 2xx (a 429, a 5xx, an error or a refusal) or NUDGE_BUDGET seconds have passed; after that no other
	 * site is nudged (flitagent.com then learns through its own checks), and every site is uninstalled either way.
	 *
	 * @param callable|null $nudge fn( string $connection_id ): bool; defaults to Nudge::send() for Config::url() (none when
	 *                             that is '').
	 * @param callable|null $timer fn(): float, seconds; defaults to microtime( true ).
	 */
	public static function run( ?callable $nudge = null, ?callable $timer = null ): void {
		if ( null === $nudge ) {
			$gateway = Config::url();
			$nudge   = '' === $gateway ? null : array( new Nudge( $gateway ), 'send' );
		}
		$timer   = $timer ?? static function (): float {
			return microtime( true );
		};
		$started = (float) call_user_func( $timer );
		$nudging = null !== $nudge;
		$send    = static function ( string $connection_id ) use ( $nudge, $timer, $started, &$nudging ): void {
			if ( ! $nudging ) {
				return;
			}
			if ( (float) call_user_func( $timer ) - $started >= self::NUDGE_BUDGET ) {
				$nudging = false;
				return;
			}
			try {
				$sent = true === call_user_func( $nudge, $connection_id );
			} catch ( \Throwable $e ) {
				// The uninstall goes on whatever the nudge does.
				$sent = false;
			}
			if ( ! $sent ) {
				$nudging = false;
			}
		};

		if ( ! is_multisite() ) {
			self::site( $send );
			return;
		}
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::site( $send );
			restore_current_blog();
		}
	}

	/**
	 * Uninstall on the current site: removes the key refresh cron and, while the site holds a connection, nudges
	 * flitagent.com with its id before anything is deleted.
	 *
	 * @param callable $send fn( string $connection_id ): void, the nudge within the uninstall's limits.
	 */
	private static function site( callable $send ): void {
		global $wpdb;
		Keys::unschedule();
		StatusNudge::unschedule();
		$connection = ( new WpStore() )->connection();
		if ( null !== $connection ) {
			$send( $connection->id() );
		}
		if ( ! Settings::is_on( get_option( Settings::OPTION_REMOVE_DATA, Settings::DEFAULT_REMOVE_DATA ) ) ) {
			// The gateway has to pair again after a reinstall; the OAuth leftovers go either way. The settings and the
			// install record stay, so a reinstall skips the setup guide.
			Upgrade::drop_legacy_oauth();
			WpStore::disconnect();
			return;
		}
		// #134: with the data removed, a reinstall is a fresh installation.
		delete_option( Onboarding::OPTION );
		foreach ( self::LEGACY_TABLES as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall, fixed table names.
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $table ) . '`' );
		}
		foreach ( self::LEGACY_OPTIONS as $option ) {
			delete_option( $option );
		}
		Upgrade::drop_legacy_oauth();
		WpStore::uninstall();
		delete_option( Upgrade::OPTION );
		delete_option( Upgrade::RETRY_OPTION );
	}
}
