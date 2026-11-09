<?php
/**
 * WordPress storage for gateway authentication.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * The connection, the pending pairing, the AI apps seen, the revoked ones and each tool disconnected on this site (one
 * option per tool) live in options (not autoloaded); request nonces in the small table
 * `{prefix}flitagent_gateway_nonces`, whose primary key makes recording a nonce atomic (a replay, even a concurrent one,
 * fails the insert). SQLite-compatible: no ENUM, Unix-integer times. No secret is stored: the connection holds
 * ids only, the key set public keys, the pending pairing a hash of its nonce, the table hashes of request nonces. The
 * key set, its last fetch attempt and the refresh lock are separate options, so no write of one rewrites another. The
 * key set is stored with the start time of the fetch that produced it and replaced only by compare-and-swap with a
 * later-started one (its first save is an INSERT IGNORE), and always read from its row, never from the object cache;
 * the lock only saves duplicate fetches. The revoked apps are changed the same way (compare-and-swap, read from the row),
 * so two revokes of different apps at once both land. The pairing lock is an option row taken by one INSERT IGNORE
 * and released by a delete of its own value (see lock_pairing()); add_connection() stores the connection the same
 * add-only way.
 * Everything is removed on uninstall when "Remove data on uninstall" is on (`Writes\Wp\Tables::uninstall()` calls
 * uninstall()); without it everything but the nonce table and its version still goes (disconnect()).
 */
final class WpStore implements Store {

	const OPTION_CONNECTION   = 'flitagent_gateway_connection';
	const OPTION_PENDING      = 'flitagent_gateway_pending';
	const OPTION_DB           = 'flitagent_gateway_db_version';
	const OPTION_KEYS         = 'flitagent_gateway_keys';
	const OPTION_KEY_ATTEMPT  = 'flitagent_gateway_key_attempt';
	const OPTION_KEY_LOCK     = 'flitagent_gateway_key_lock';
	const OPTION_LAST_REQUEST = 'flitagent_gateway_last_request';
	const OPTION_APPS         = 'flitagent_gateway_apps';
	const OPTION_BLOCKED      = 'flitagent_gateway_blocked_';
	const OPTION_REVOKED_APPS = 'flitagent_gateway_revoked_apps';
	const OPTION_ENDED        = 'flitagent_gateway_ended';
	const OPTION_STATUS_NUDGE = 'flitagent_gateway_status_nudge';
	const OPTION_PAIRING_LOCK = 'flitagent_gateway_pairing_lock';
	const OPTIONS             = array( self::OPTION_CONNECTION, self::OPTION_PENDING, self::OPTION_DB, self::OPTION_KEYS, self::OPTION_KEY_ATTEMPT, self::OPTION_KEY_LOCK, self::OPTION_LAST_REQUEST, self::OPTION_APPS, self::OPTION_BLOCKED . Apps::CHATGPT, self::OPTION_BLOCKED . Apps::CLAUDE, self::OPTION_BLOCKED . Apps::MANUAL, self::OPTION_REVOKED_APPS, self::OPTION_ENDED, self::OPTION_STATUS_NUDGE, self::OPTION_PAIRING_LOCK );

	/**
	 * Seconds a key refresh lock is held before another request may take it over.
	 */
	const KEY_LOCK_TTL = 30;

	/**
	 * Seconds a pairing lock is held before another request may take it over.
	 */
	const PAIRING_LOCK_TTL = 60;

	/**
	 * Read-and-swap attempts save_keys() makes before giving up.
	 */
	const SAVE_ATTEMPTS = 10;

	/**
	 * Seconds a stored fetch start time may lie ahead of this server's clock before it is treated as stale.
	 */
	const CLOCK_SKEW = 60;

	/**
	 * Schema version of the nonce table.
	 */
	const DB_VERSION = '1';

	/**
	 * Nonce table, without the prefix.
	 */
	const TABLE = 'flitagent_gateway_nonces';

	/**
	 * Expiry of the key refresh lock this store holds, 0 when it holds none.
	 *
	 * @var int
	 */
	private $lock = 0;

	/**
	 * The pairing lock's row value this store wrote (expiry and token), '' when it holds none.
	 *
	 * @var string
	 */
	private $pairing_lock = '';

	/**
	 * {@inheritDoc}
	 */
	public function connection(): ?Connection {
		return Connection::from_array( get_option( self::OPTION_CONNECTION, null ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Connection $connection Connection.
	 */
	public function save_connection( Connection $connection ): void {
		update_option( self::OPTION_CONNECTION, $connection->to_array(), false );
		delete_option( self::OPTION_ENDED );
	}

	/**
	 * {@inheritDoc}
	 *
	 * One INSERT IGNORE on the option row, past the object cache, like the pairing lock. A row that holds no connection
	 * (connection() reads it as none: an older format or a corrupt value) is deleted by its exact value and the insert
	 * tried once more, so it cannot block connecting.
	 *
	 * @param Connection $connection Connection.
	 */
	public function add_connection( Connection $connection ): bool {
		global $wpdb;
		$value = maybe_serialize( $connection->to_array() );
		$added = self::insert_option( self::OPTION_CONNECTION, $value );
		if ( ! $added ) {
			$raw = self::raw_option( self::OPTION_CONNECTION );
			if ( null !== $raw && null === Connection::from_array( maybe_unserialize( $raw ) ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deletes only the unreadable value read just now; uncached below.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION_CONNECTION, $raw ) );
				$added = self::insert_option( self::OPTION_CONNECTION, $value );
			}
		}
		self::uncache_option( self::OPTION_CONNECTION );
		if ( $added ) {
			delete_option( self::OPTION_ENDED );
		}
		return $added;
	}

	/**
	 * {@inheritDoc}
	 */
	public function stored_connection(): ?Connection {
		$raw = self::raw_option( self::OPTION_CONNECTION );
		return null === $raw ? null : Connection::from_array( maybe_unserialize( $raw ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A delete of the row's exact value (what add_connection() inserted), past the object cache, then cleaned from it.
	 *
	 * @param Connection $connection Connection.
	 */
	public function remove_connection( Connection $connection ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deletes only this exact connection; uncached below.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION_CONNECTION, maybe_serialize( $connection->to_array() ) ) );
		self::uncache_option( self::OPTION_CONNECTION );
		return is_int( $deleted ) && $deleted > 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete_connection(): void {
		delete_option( self::OPTION_CONNECTION );
		delete_option( self::OPTION_LAST_REQUEST );
		delete_option( self::OPTION_APPS );
		delete_option( self::OPTION_REVOKED_APPS );
	}

	/**
	 * {@inheritDoc}
	 */
	public function apps(): array {
		$apps = get_option( self::OPTION_APPS, array() );
		return is_array( $apps ) ? $apps : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $apps Apps by key.
	 */
	public function save_apps( array $apps ): void {
		update_option( self::OPTION_APPS, $apps, false );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Read from the row, never through get_option() or the object cache: update_revoked_apps() swaps the row, and a
	 * copy cached earlier in this request (or in a persistent cache) can miss a revoke that landed since.
	 */
	public function revoked_apps(): array {
		$raw     = self::raw_option( self::OPTION_REVOKED_APPS );
		$revoked = null === $raw ? null : maybe_unserialize( $raw );
		return is_array( $revoked ) ? $revoked : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Compare-and-swap on the raw row, as save_keys() does (the first save is an INSERT IGNORE): a lost insert or swap
	 * means another revoke landed, so read again, change again and retry, up to SAVE_ATTEMPTS times.
	 *
	 * @param callable $change Revocations in, revocations to store out.
	 */
	public function update_revoked_apps( callable $change ): void {
		global $wpdb;
		for ( $try = 0; $try < self::SAVE_ATTEMPTS; ++$try ) {
			$raw    = self::raw_option( self::OPTION_REVOKED_APPS );
			$stored = null === $raw ? null : maybe_unserialize( $raw );
			$value  = maybe_serialize( $change( is_array( $stored ) ? $stored : array() ) );
			if ( $value === $raw ) {
				// Nothing to change (an UPDATE would match no changed row and look like a lost swap).
				return;
			}
			if ( null === $raw ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic first insert; uncached below.
				$written = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPTION_REVOKED_APPS, $value ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap; uncached below.
				$written = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::OPTION_REVOKED_APPS, $raw ) );
			}
			if ( is_int( $written ) && $written > 0 ) {
				self::uncache_option( self::OPTION_REVOKED_APPS );
				return;
			}
		}
		// Every attempt lost to another revoke that did land; give up rather than spin.
	}

	/**
	 * {@inheritDoc}
	 */
	public function blocked_tools(): array {
		$tools = array();
		foreach ( Apps::TOOLS as $tool ) {
			if ( false !== get_option( self::OPTION_BLOCKED . $tool, false ) ) {
				$tools[] = $tool;
			}
		}
		return $tools;
	}

	/**
	 * {@inheritDoc}
	 *
	 * One option per tool, added (or deleted) on its own: no read-modify-write of a shared list.
	 *
	 * @param string $tool Tool.
	 */
	public function block_tool( string $tool ): void {
		if ( in_array( $tool, Apps::TOOLS, true ) ) {
			add_option( self::OPTION_BLOCKED . $tool, 1, '', false );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tool Tool.
	 */
	public function unblock_tool( string $tool ): void {
		if ( in_array( $tool, Apps::TOOLS, true ) ) {
			delete_option( self::OPTION_BLOCKED . $tool );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function pending(): ?array {
		$pending = get_option( self::OPTION_PENDING, null );
		if ( ! is_array( $pending ) || ! isset( $pending['nonce_hash'], $pending['user_id'], $pending['scope'], $pending['expires_at'] ) ) {
			return null;
		}
		return $pending;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Read from the row, never through get_option() or the object cache: save_keys() fences the row, and a
	 * (persistent) cache can still hold an older set with a revoked key.
	 */
	public function keys(): KeySet {
		$raw    = self::raw_keys();
		$stored = null === $raw ? null : maybe_unserialize( $raw );
		return KeySet::from_array( is_array( $stored ) && isset( $stored['keys'] ) ? $stored['keys'] : null );
	}

	/**
	 * The key set option's row value (serialized), or null when there is none. The only read of that option.
	 */
	private static function raw_keys(): ?string {
		return self::raw_option( self::OPTION_KEYS );
	}

	/**
	 * An option's row value (serialized), or null when there is none, read past the object cache.
	 *
	 * @param string $name Option name.
	 */
	private static function raw_option( string $name ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the row is authoritative; the cache may be stale.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		return null === $raw ? null : (string) $raw;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param KeySet $keys    Key set.
	 * @param float  $started When the fetch that produced it started.
	 */
	public function save_keys( KeySet $keys, float $started ): void {
		global $wpdb;
		$value = maybe_serialize(
			array(
				'keys'               => $keys->to_array(),
				'fetched_started_at' => $started,
			)
		);
		// Compare-and-swap on the raw row, bypassing the option cache: two requests can both hold the refresh lock
		// (add_option() upserts when its cached read misses), so the start time is what orders their writes. A lost
		// swap only proves that another write landed, not that it was newer, so read again and retry.
		for ( $try = 0; $try < self::SAVE_ATTEMPTS; ++$try ) {
			$raw = self::raw_keys();
			if ( null === $raw ) {
				// A concurrent first insert wins the primary key; this one then inserts nothing and swaps instead.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic first insert; cached below.
				$written = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPTION_KEYS, $value ) );
			} elseif ( self::stored_is_newer( $raw, $started ) ) {
				return;
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap; cached below.
				$written = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::OPTION_KEYS, $raw ) );
			}
			if ( is_int( $written ) && $written > 0 ) {
				self::uncache_option( self::OPTION_KEYS );
				return;
			}
		}
		// Every attempt lost to another write: give up rather than spin; the next refresh tries again.
	}

	/**
	 * Whether a stored key set came from a fetch that started at or after $started. A start time more than
	 * CLOCK_SKEW seconds ahead of this server's clock is stale (written under a clock that ran fast), so it cannot
	 * block refreshes until the clock catches up.
	 *
	 * @param string $raw     Stored option value, serialized.
	 * @param float  $started When this fetch started.
	 */
	private static function stored_is_newer( string $raw, float $started ): bool {
		$stored = maybe_unserialize( $raw );
		if ( ! is_array( $stored ) || ! isset( $stored['fetched_started_at'] ) ) {
			return false;
		}
		$stored_started = (float) $stored['fetched_started_at'];
		return $stored_started >= $started && $stored_started <= microtime( true ) + self::CLOCK_SKEW;
	}

	/**
	 * Removes the option from the object cache after a write: its value and its notoptions entry. Publishing the
	 * value instead could race (an older save's cache write landing after a newer save), so nothing is published;
	 * keys() reads the row anyway.
	 *
	 * @param string $name Option name.
	 */
	private static function uncache_option( string $name ): void {
		wp_cache_delete( $name, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function key_attempt(): int {
		return (int) get_option( self::OPTION_KEY_ATTEMPT, 0 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $now Current time.
	 */
	public function save_key_attempt( int $now ): void {
		update_option( self::OPTION_KEY_ATTEMPT, $now, false );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The lock is an option holding its expiry: add_option() refuses an option that exists.
	 *
	 * @param int $now Current time.
	 */
	public function lock_key_refresh( int $now ): bool {
		// Saves duplicate fetches only: core's add_option() upserts when its cached read misses, so two requests can
		// both get here. save_keys() is what keeps the newest set.
		$expires = $now + self::KEY_LOCK_TTL;
		if ( ! add_option( self::OPTION_KEY_LOCK, $expires, '', false ) ) {
			if ( (int) get_option( self::OPTION_KEY_LOCK, 0 ) >= $now ) {
				return false;
			}
			delete_option( self::OPTION_KEY_LOCK );
			if ( ! add_option( self::OPTION_KEY_LOCK, $expires, '', false ) ) {
				return false;
			}
		}
		$this->lock = $expires;
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function unlock_key_refresh(): void {
		if ( 0 !== $this->lock && (int) get_option( self::OPTION_KEY_LOCK, 0 ) === $this->lock ) {
			delete_option( self::OPTION_KEY_LOCK );
		}
		$this->lock = 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The lock is the option row `<expiry>:<token>` (32 hex), taken by one INSERT IGNORE on the options table: the
	 * unique option name makes the insert fail while the row exists, on MySQL and on the SQLite integration (which runs
	 * it as INSERT OR IGNORE and reports the rows it changed). Not add_option(): it upserts when its cached read misses.
	 * A stale row (past its expiry, unreadable, or expiring more than PAIRING_LOCK_TTL + CLOCK_SKEW seconds ahead, so a
	 * lock written under a clock that ran fast cannot block connecting) is deleted by its exact value, so a lock another
	 * request took since stays, and the insert is tried once more.
	 *
	 * @param int $now Current time.
	 */
	public function lock_pairing( int $now ): bool {
		global $wpdb;
		$value = ( $now + self::PAIRING_LOCK_TTL ) . ':' . bin2hex( random_bytes( 16 ) );
		if ( ! self::insert_option( self::OPTION_PAIRING_LOCK, $value ) ) {
			$raw = self::raw_option( self::OPTION_PAIRING_LOCK );
			if ( null !== $raw && self::pairing_lock_expiry( $raw ) >= $now && self::pairing_lock_expiry( $raw ) <= $now + self::PAIRING_LOCK_TTL + self::CLOCK_SKEW ) {
				return false;
			}
			if ( null !== $raw ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deletes only the stale value read just now; uncached below.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION_PAIRING_LOCK, $raw ) );
			}
			if ( ! self::insert_option( self::OPTION_PAIRING_LOCK, $value ) ) {
				// Another request took the stale lock over first.
				return false;
			}
		}
		self::uncache_option( self::OPTION_PAIRING_LOCK );
		$this->pairing_lock = $value;
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Read from the row, never through get_option() or the object cache; the expiry is checked against this server's
	 * clock, since a lock past it may be taken over at any moment.
	 */
	public function holds_pairing_lock(): bool {
		return '' !== $this->pairing_lock
			&& self::raw_option( self::OPTION_PAIRING_LOCK ) === $this->pairing_lock
			&& self::pairing_lock_expiry( $this->pairing_lock ) >= time();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Deletes the row only while it still holds this store's value.
	 */
	public function unlock_pairing(): void {
		global $wpdb;
		if ( '' !== $this->pairing_lock ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deletes only this store's own lock; uncached below.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION_PAIRING_LOCK, $this->pairing_lock ) );
			self::uncache_option( self::OPTION_PAIRING_LOCK );
		}
		$this->pairing_lock = '';
	}

	/**
	 * Inserts an option row (not autoloaded) unless one with that name exists, past the object cache.
	 *
	 * @param string $name  Option name.
	 * @param string $value Row value.
	 * @return bool Whether this call inserted it.
	 */
	private static function insert_option( string $name, string $value ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic insert; the caller uncaches.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
		return is_int( $inserted ) && $inserted > 0;
	}

	/**
	 * The expiry of a stored pairing lock, 0 when the value is not one (so it counts as stale).
	 *
	 * @param string $raw Row value.
	 */
	private static function pairing_lock_expiry( string $raw ): int {
		return 1 === preg_match( '/^([0-9]{1,19}):[0-9a-f]{32}$/', $raw, $match ) ? (int) $match[1] : 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function ended(): int {
		return (int) get_option( self::OPTION_ENDED, 0 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $now Current Unix time.
	 */
	public function save_ended( int $now ): void {
		update_option( self::OPTION_ENDED, $now, false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function last_request(): int {
		return (int) get_option( self::OPTION_LAST_REQUEST, 0 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $now Current time.
	 */
	public function save_last_request( int $now ): void {
		if ( $now < $this->last_request() || $now - $this->last_request() >= 60 ) {
			update_option( self::OPTION_LAST_REQUEST, $now, false );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $pending Pending pairing.
	 */
	public function save_pending( array $pending ): void {
		update_option( self::OPTION_PENDING, $pending, false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete_pending(): void {
		delete_option( self::OPTION_PENDING );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key        Nonce key.
	 * @param int    $expires_at Expiry.
	 * @param int    $now        Current time.
	 */
	public function remember_nonce( string $key, int $expires_at, int $now ): bool {
		global $wpdb;
		self::maybe_install();
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- replay-protection table; fixed table name, nothing to cache.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %d", $now ) );

		$suppress = $wpdb->suppress_errors( true );
		// A duplicate key fails the insert: that is the replay check. Errors are suppressed so it is not printed or logged.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see above.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (nonce_key, expires_at) VALUES (%s, %d)", $key, $expires_at ) );
		$wpdb->suppress_errors( $suppress );
		return 1 === $inserted;
	}

	/**
	 * Nonce table name with the prefix.
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Creates the nonce table when its schema version is not the current one.
	 */
	public static function maybe_install(): void {
		global $wpdb;
		if ( self::DB_VERSION === get_option( self::OPTION_DB, '' ) ) {
			return;
		}
		if ( ! function_exists( 'dbDelta' ) && is_readable( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta(
			'CREATE TABLE ' . self::table() . " (\n"
			. "  nonce_key char(64) NOT NULL,\n"
			. "  expires_at bigint(20) unsigned NOT NULL,\n"
			. "  PRIMARY KEY  (nonce_key),\n"
			. "  KEY expires_at (expires_at)\n"
			. ') ' . $wpdb->get_charset_collate() . ';'
		);
		update_option( self::OPTION_DB, self::DB_VERSION, false );
	}

	/**
	 * Uninstall without "remove data": forgets the connection, any pending pairing and the key set, so after a
	 * reinstall the site has to pair again. The nonce table and its version stay.
	 */
	public static function disconnect(): void {
		foreach ( self::OPTIONS as $option ) {
			if ( self::OPTION_DB !== $option ) {
				delete_option( $option );
			}
		}
	}

	/**
	 * Uninstall: drops the nonce table and deletes the gateway options (the caller checks "remove data").
	 */
	public static function uninstall(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall, fixed table name.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() );
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
	}
}
