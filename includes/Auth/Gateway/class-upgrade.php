<?php
/**
 * One-time upgrade from the pre-release OAuth and v1 gateway storage.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once per schema version, on `admin_init`: drops the four pre-release OAuth tables and their version option,
 * deletes a v1 gateway connection or pending pairing (they held a `public_key`; a v2 connection is kept) and expires
 * the pending or approved changes queued by the removed OAuth and application-password clients (their client ids do
 * not start with `fa:`, so the gateway could never apply them anyway), and brings the connection's last request down to
 * the latest request of a connected AI tool (version 4: before it, announcements and the relay's own requests counted).
 * A failed run is retried at most once a day. The
 * table names live here, not in the OAuth module, so uninstall can still drop them once that module is gone.
 */
final class Upgrade {

	const OPTION        = 'flitagent_upgrade_version';
	const VERSION       = '4';
	const RETRY_OPTION  = 'flitagent_upgrade_retry_at';
	const RETRY_AFTER   = 86400;
	const OAUTH_TABLES  = array( 'flitagent_oauth_clients', 'flitagent_oauth_codes', 'flitagent_oauth_grants', 'flitagent_oauth_tokens' );
	const OAUTH_OPTIONS = array( 'flitagent_oauth_db_version' );

	/**
	 * Runs the upgrade when the stored version differs.
	 */
	public static function maybe_run(): void {
		if ( ! current_user_can( 'manage_options' ) || self::VERSION === get_option( self::OPTION, '' ) ) {
			return;
		}
		if ( (int) get_option( self::RETRY_OPTION, 0 ) > time() ) {
			return;
		}
		if ( self::run() ) {
			update_option( self::OPTION, self::VERSION, true );
			delete_option( self::RETRY_OPTION );
			return;
		}
		update_option( self::RETRY_OPTION, time() + self::RETRY_AFTER, false );
	}

	/**
	 * Drops the OAuth leftovers and the v1 gateway data, and expires changes queued by the removed clients.
	 *
	 * @return bool Whether every table dropped; when not, the version is not stored and the run is retried after a day.
	 */
	public static function run(): bool {
		$dropped = self::drop_legacy_oauth();
		foreach ( array( WpStore::OPTION_CONNECTION, WpStore::OPTION_PENDING ) as $option ) {
			$stored = get_option( $option, null );
			if ( is_array( $stored ) && array_key_exists( 'public_key', $stored ) ) {
				delete_option( $option );
			}
		}
		self::expire_legacy_changes();
		self::correct_last_request( new WpStore() );
		return $dropped;
	}

	/**
	 * Up to 4 the connection's last request also counted flitagent.com announcing an app and its own check page and
	 * cache refreshes, so it could show a time while every tool read "no requests yet" (e2e 2026-10-30, M12). It is
	 * lowered to the latest request of a connected tool, or to 0 (None yet) when none has made one, unless it is within
	 * a minute of it; never raised.
	 *
	 * @param Store $store Gateway store.
	 */
	public static function correct_last_request( Store $store ): void {
		$stored = $store->last_request();
		if ( 0 === $stored ) {
			return;
		}
		$latest = 0;
		foreach ( ( new Apps( $store ) )->tools() as $tool ) {
			$latest = max( $latest, (int) $tool['last_request'] );
		}
		// A tool's record is written at most once every Apps::THROTTLE seconds, so a stored time less than that after its
		// last request can be a real one.
		if ( $stored - $latest >= Apps::THROTTLE ) {
			// An earlier time is always written (save_last_request() only throttles later ones); 0 reads "None yet".
			$store->save_last_request( $latest );
		}
	}

	/**
	 * Marks pending and approved changes whose client id is not a gateway one (`fa:...`) as expired, so none can be
	 * approved or applied. `Tables::maybe_upgrade()` normally creates the table first; if it is missing anyway, the query fails and the result is ignored.
	 */
	private static function expire_legacy_changes(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- upgrade, fixed table name and constants.
		$wpdb->query( "UPDATE `{$wpdb->prefix}flitagent_changes` SET status = 'expired' WHERE status IN ('pending','approved') AND client_id NOT LIKE 'fa:%'" );
	}

	/**
	 * Drops the pre-release OAuth tables and deletes their options.
	 *
	 * @return bool False when a DROP failed.
	 */
	public static function drop_legacy_oauth(): bool {
		global $wpdb;
		$dropped = true;
		foreach ( self::OAUTH_TABLES as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- upgrade and uninstall, fixed table names.
			if ( false === $wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . $table . '`' ) ) {
				$dropped = false;
			}
		}
		foreach ( self::OAUTH_OPTIONS as $option ) {
			delete_option( $option );
		}
		return $dropped;
	}
}
