<?php
/**
 * The write layer's database tables.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Wp;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades `{prefix}flitagent_changes`, `{prefix}flitagent_receipts` and `{prefix}flitagent_idempotency` with
 * dbDelta(). Portable to the SQLite integration: no ENUM, FULLTEXT, triggers or engine options; times are Unix
 * integers. Tables are kept on deactivation and dropped on uninstall (\FlitAgent\Uninstall) only when "Remove data on
 * uninstall" is on.
 */
final class Tables {

	/**
	 * Schema version. Bump it when the schema changes.
	 */
	const VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const VERSION_OPTION = 'flitagent_db_version';

	/**
	 * Table keys.
	 */
	const TABLES = array( 'changes', 'receipts', 'idempotency' );

	/**
	 * Full table name.
	 *
	 * @param string $table Table key.
	 * @return string
	 */
	public static function name( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . 'flitagent_' . $table;
	}

	/**
	 * CREATE TABLE statements in dbDelta() format.
	 *
	 * @param string $prefix  Table prefix.
	 * @param string $collate Charset and collation clause.
	 * @return string[]
	 */
	public static function schema( string $prefix, string $collate ): array {
		$id   = "  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n";
		$time = "bigint(20) unsigned NOT NULL DEFAULT 0,\n";

		return array(
			"CREATE TABLE {$prefix}flitagent_changes (\n"
				. $id
				. "  uuid varchar(36) NOT NULL,\n"
				. "  tool varchar(64) NOT NULL,\n"
				. "  args_json longtext NOT NULL,\n"
				. "  args_hash char(64) NOT NULL,\n"
				. "  requester_user_id bigint(20) unsigned NOT NULL DEFAULT 0,\n"
				. "  client_id varchar(64) NOT NULL DEFAULT '',\n"
				. "  client_name varchar(255) NOT NULL DEFAULT '',\n"
				. "  tier varchar(20) NOT NULL,\n"
				. "  summary text NOT NULL,\n"
				. "  preview_json longtext NOT NULL,\n"
				. "  preconditions_json longtext NOT NULL,\n"
				. "  status varchar(20) NOT NULL,\n"
				. "  approved_by bigint(20) unsigned NOT NULL DEFAULT 0,\n"
				. '  approved_at ' . $time
				. '  expires_at ' . $time
				. "  binding varchar(64) NOT NULL DEFAULT '',\n"
				. "  receipt_id varchar(36) NOT NULL DEFAULT '',\n"
				. '  created_at ' . $time
				. "  PRIMARY KEY  (id),\n"
				. "  UNIQUE KEY uuid (uuid),\n"
				. "  KEY status (status),\n"
				. "  KEY requester_user_id (requester_user_id)\n"
				. ") {$collate};",
			"CREATE TABLE {$prefix}flitagent_receipts (\n"
				. $id
				. "  uuid varchar(36) NOT NULL,\n"
				. "  change_id varchar(36) NOT NULL DEFAULT '',\n"
				. "  idempotency_key varchar(128) NOT NULL DEFAULT '',\n"
				. "  user_id bigint(20) unsigned NOT NULL DEFAULT 0,\n"
				. "  client_id varchar(64) NOT NULL DEFAULT '',\n"
				. "  client_name varchar(255) NOT NULL DEFAULT '',\n"
				. "  tool varchar(64) NOT NULL,\n"
				. "  tier varchar(20) NOT NULL,\n"
				. "  args_json longtext NOT NULL,\n"
				. "  args_hash char(64) NOT NULL,\n"
				. "  approval varchar(32) NOT NULL,\n"
				. "  status varchar(32) NOT NULL,\n"
				. "  result_json longtext NOT NULL,\n"
				. "  rollback_json longtext NOT NULL,\n"
				. "  rolls_back varchar(36) NOT NULL DEFAULT '',\n"
				. "  rolled_back_by varchar(36) NOT NULL DEFAULT '',\n"
				. '  created_at ' . $time
				. "  PRIMARY KEY  (id),\n"
				. "  UNIQUE KEY uuid (uuid),\n"
				. "  KEY tool (tool),\n"
				. "  KEY status (status)\n"
				. ") {$collate};",
			"CREATE TABLE {$prefix}flitagent_idempotency (\n"
				. $id
				. "  scope char(64) NOT NULL,\n"
				. "  args_hash char(64) NOT NULL,\n"
				. "  status varchar(20) NOT NULL,\n"
				. "  response_json longtext NOT NULL,\n"
				. '  created_at ' . $time
				. "  PRIMARY KEY  (id),\n"
				. "  UNIQUE KEY scope (scope)\n"
				. ") {$collate};",
		);
	}

	/**
	 * Activation: create the tables. Deleting the plugin runs uninstall.php (\FlitAgent\Uninstall), so no uninstall hook is
	 * registered: an update never runs activation, and a hook stored on one site of a network is not read on another.
	 */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Creates or upgrades the tables and records the schema version.
	 */
	public static function install(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) && is_readable( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( self::schema( $wpdb->prefix, $wpdb->get_charset_collate() ) );
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Upgrades when the stored schema version differs (plugin updated without reactivation).
	 */
	public static function maybe_upgrade(): void {
		if ( self::VERSION !== get_option( self::VERSION_OPTION, '' ) ) {
			self::install();
		}
	}

	/**
	 * The uninstall hook earlier 1.0.0 builds registered on activation. WordPress keeps the callback
	 * `array( Tables::class, 'uninstall' )` in `uninstall_plugins` on those sites, but since the plugin has uninstall.php,
	 * uninstall_plugin() runs that file instead, removes the entry and never calls this; it stays so a stored entry still
	 * names a method, and only runs \FlitAgent\Uninstall::run(). WordPress would call it through
	 * `do_action( "uninstall_{$file}" )`, which passes ''. It declares no parameter, so PHP ignores that argument; a typed
	 * parameter here would be a TypeError.
	 */
	public static function uninstall(): void {
		\FlitAgent\Uninstall::run();
	}
}
