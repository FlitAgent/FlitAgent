<?php
/**
 * What deleting the plugin removes.
 *
 * @package FlitAgent
 */

namespace FlitAgent;

defined( 'ABSPATH' ) || exit;

use FlitAgent\Admin\Onboarding;
use FlitAgent\Auth\Gateway\Config;
use FlitAgent\Auth\Gateway\Keys;
use FlitAgent\Auth\Gateway\Nudge;
use FlitAgent\Auth\Gateway\StatusNudge;
use FlitAgent\Auth\Gateway\Upgrade;
use FlitAgent\Auth\Gateway\WpStore;
use FlitAgent\Writes\Wp\Tables;
use FlitAgent\Writes\Wp\WpPolicy;

/**
 * Runs from uninstall.php, on every site of a network: a network uninstall runs once, on the main site, while each site
 * has its own options and tables. A site holding a connection first nudges flitagent.com with its connection id (Nudge),
 * so flitagent.com disconnects the site and its AI tools. Every site then loses the gateway key refresh and status nudge
 * crons, the pre-release OAuth tables, the flitagent.com connection and the uninstall hook FlitAgent 1.0.0 registered on
 * activation. With "Remove data on uninstall" off the settings, the write layer's tables and the install record stay, so
 * a reinstall skips the setup guide; with it on (WpPolicy::DEFAULT_REMOVE_DATA) the site also loses the install record
 * (#134, so a reinstall is a fresh installation), the changes, receipts and idempotency tables, the settings, the
 * gateway's data and the upgrade state.
 *
 * WordPress's uninstall_plugin() runs uninstall.php when the plugin has one and then never calls a registered uninstall
 * hook, so this runs once per uninstall. Tables::uninstall(), the hook 1.0.0 stored, only calls run().
 */
final class Uninstall {

	/**
	 * Seconds an uninstall may spend nudging (Ruling 7); after that no other site of the network is nudged.
	 */
	const NUDGE_BUDGET = 10;

	/**
	 * The uninstall hook FlitAgent 1.0.0 registered on activation, as WordPress stored it in `uninstall_plugins`.
	 */
	const STORED_HOOK = array( Tables::class, 'uninstall' );

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
	 * Uninstall on the current site: removes the crons and, while the site holds a connection, nudges flitagent.com with
	 * its id before anything is deleted.
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
		self::forget_stored_hook();
		if ( ! WpPolicy::is_on( get_option( WpPolicy::OPTION_REMOVE_DATA, WpPolicy::DEFAULT_REMOVE_DATA ) ) ) {
			// The gateway has to pair again after a reinstall; the OAuth leftovers go either way. The settings, the
			// receipts and the install record stay, so a reinstall skips the setup guide.
			Upgrade::drop_legacy_oauth();
			WpStore::disconnect();
			return;
		}
		// #134: with the data removed, a reinstall is a fresh installation.
		delete_option( Onboarding::OPTION );
		foreach ( Tables::TABLES as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall, fixed table names.
			$wpdb->query( 'DROP TABLE IF EXISTS ' . Tables::name( $table ) );
		}
		foreach ( WpPolicy::OPTIONS as $option ) {
			delete_option( $option );
		}
		Upgrade::drop_legacy_oauth();
		WpStore::uninstall();
		delete_option( Upgrade::OPTION );
		delete_option( Upgrade::RETRY_OPTION );
	}

	/**
	 * Removes the uninstall hook 1.0.0 registered on activation from this site's `uninstall_plugins`, matched by its
	 * callback (so whatever the plugin's folder is called). WordPress removes it itself on the site uninstall_plugin()
	 * runs on; the other sites of a network keep theirs until this runs.
	 */
	private static function forget_stored_hook(): void {
		$hooks = get_option( 'uninstall_plugins' );
		if ( ! is_array( $hooks ) ) {
			return;
		}
		$kept = array_filter(
			$hooks,
			static function ( $callback ): bool {
				return self::STORED_HOOK !== $callback;
			}
		);
		if ( count( $kept ) !== count( $hooks ) ) {
			update_option( 'uninstall_plugins', $kept );
		}
	}
}
