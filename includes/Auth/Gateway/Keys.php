<?php
/**
 * Looks up the gateway's signing keys and keeps them fresh.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * The stored key set, refreshed daily by WP-Cron while the site is connected and, at most once every RETRY seconds,
 * when a request names a key id the site does not hold. A failed refresh never touches the stored set, and a
 * successful one replaces it only if no fetch that started later has saved one. The store's refresh lock usually
 * keeps concurrent requests to one fetch.
 */
final class Keys {

	const CRON_HOOK = 'flitagent_gateway_refresh_keys';
	const RETRY     = 300;

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Gateway URL.
	 *
	 * @var string
	 */
	private $gateway;

	/**
	 * Fetches the key set.
	 *
	 * @var callable
	 */
	private $fetch;

	/**
	 * Returns the Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Returns the fetch start time in seconds with microseconds.
	 *
	 * @var callable
	 */
	private $timer;

	/**
	 * Constructor.
	 *
	 * @param Store         $store   Store.
	 * @param string        $gateway Gateway URL.
	 * @param callable|null $fetch   fn( string $gateway ): KeySet; defaults to KeyFetcher::fetch().
	 * @param callable|null $clock   fn(): int; defaults to time().
	 * @param callable|null $timer   fn(): float, when a fetch starts; defaults to microtime( true ).
	 */
	public function __construct( Store $store, string $gateway, ?callable $fetch = null, ?callable $clock = null, ?callable $timer = null ) {
		$this->store   = $store;
		$this->gateway = $gateway;
		$this->fetch   = $fetch ?? array( KeyFetcher::class, 'fetch' );
		$this->clock   = $clock ?? 'time';
		$this->timer   = $timer ?? static function (): float {
			return microtime( true );
		};
	}

	/**
	 * Fetches the key set and replaces the stored one. The attempt time is recorded either way. While another
	 * request holds the store's refresh lock nothing is fetched and the stored set stays in use.
	 *
	 * @return string '' on success (or when another refresh is running and a set is stored), else the error message.
	 */
	public function refresh(): string {
		$now = (int) call_user_func( $this->clock );
		if ( ! $this->store->lock_key_refresh( $now ) ) {
			return $this->store->keys()->is_empty() ? 'Another request is fetching the gateway keys. Try again in a moment.' : '';
		}
		try {
			return $this->fetch_and_store( $now );
		} finally {
			$this->store->unlock_key_refresh();
		}
	}

	/**
	 * The raw public key of a key id, or null. An unknown id refreshes the set once when the last attempt is at
	 * least RETRY seconds old (or lies in the future after a clock step back), then looks again. The throttle is
	 * checked again once the refresh lock is held; without the lock nothing is fetched and the stored set answers.
	 * Without a connection nothing is ever fetched.
	 *
	 * @param string $kid Key id.
	 * @return string|null
	 */
	public function key( string $kid ): ?string {
		$key = $this->store->keys()->get( $kid );
		if ( null !== $key || null === $this->store->connection() ) {
			return $key;
		}
		$now = (int) call_user_func( $this->clock );
		if ( $this->throttled( $now ) || ! $this->store->lock_key_refresh( $now ) ) {
			return $this->store->keys()->get( $kid );
		}
		try {
			if ( ! $this->throttled( $now ) ) {
				$this->fetch_and_store( $now );
			}
		} finally {
			$this->store->unlock_key_refresh();
		}
		return $this->store->keys()->get( $kid );
	}

	/**
	 * Whether the last attempt is less than RETRY seconds old (an attempt in the future does not count).
	 *
	 * @param int $now Current time.
	 */
	private function throttled( int $now ): bool {
		$attempt = $this->store->key_attempt();
		return $now >= $attempt && $now - $attempt < self::RETRY;
	}

	/**
	 * Records the attempt and fetches; only a valid key set replaces the stored one, and only if no fetch that started
	 * later has saved one meanwhile (Store::save_keys()). The caller holds the lock.
	 *
	 * @param int $now Current time.
	 * @return string '' on success, else the error message.
	 */
	private function fetch_and_store( int $now ): string {
		$this->store->save_key_attempt( $now );
		$started = (float) call_user_func( $this->timer );
		try {
			$keys = call_user_func( $this->fetch, $this->gateway );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			return $e->getMessage();
		}
		$this->store->save_keys( $keys, $started );
		return '';
	}

	/**
	 * The cron callback: refreshes only while the site is connected.
	 *
	 * @return string '' on success or when not connected, else the error message.
	 */
	public function refresh_if_connected(): string {
		return null === $this->store->connection() ? '' : $this->refresh();
	}

	/**
	 * Keeps one daily refresh event while connected and none otherwise.
	 */
	public function sync_schedule(): void {
		if ( null === $this->store->connection() ) {
			self::unschedule();
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Pairing has just fetched the keys, so the first refresh is a day away.
			wp_schedule_event( (int) call_user_func( $this->clock ) + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Removes the refresh event (also on plugin deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
