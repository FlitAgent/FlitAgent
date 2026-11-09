<?php
/**
 * Pairs the site with a gateway.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

use FlitAgent\Auth\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Starts a pairing (a single-use nonce, valid 10 minutes, bound to the user who started it) and accepts the gateway's
 * signed result. WordPress-free.
 *
 * The result is accepted only when it is signed by the key it names (`kid`) in the key set fetched at Connect, carries the pending nonce (once), a scope no higher
 * than offered and this site's MCP URL, and the user completing it started it. Anything else returns null and stores
 * nothing.
 *
 * start(), complete() and cancel() each run under the store's pairing lock (Store::lock_pairing()), so pairings do not
 * overlap unless one stalls past the lock's expiry and another takes it over. The lock is a lease, not a fence, so
 * complete() also guards its writes on their own: it stores the connection add-only (Store::add_connection(), an
 * existing connection wins) and takes it back when the lock was lost by the time it landed, and after turning writes
 * on it checks it still holds the lock and its connection is still the stored row, switching writes back off if not.
 * See complete_locked() for what this does not cover.
 */
final class Pairing {

	/**
	 * How long a started pairing can be completed, in seconds (10 minutes).
	 */
	const TTL = 600;

	/**
	 * Largest accepted pairing result, in bytes.
	 */
	const MAX_RESULT = 4096;

	/**
	 * The refusal codes flitagent.com puts on the return URL.
	 */
	const ERRORS = array( 'cancelled', 'site_cap_reached', 'site_on_other_account', 'invalid_request' );

	/**
	 * Why start() refused while another request holds the pairing lock (shown to the administrator).
	 */
	const LOCKED = 'Another connection attempt is finishing. Try again in a moment.';

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * See chosen_access().
	 *
	 * @var string
	 */
	private $chosen_access = '';

	/**
	 * See access_failed().
	 *
	 * @var bool
	 */
	private $access_failed = false;

	/**
	 * Constructor.
	 *
	 * @param Store $store Store.
	 */
	public function __construct( Store $store ) {
		$this->store = $store;
	}

	/**
	 * Starts a pairing and returns the gateway URL to send the user to: `/pair?` with site, scope, `access_choice=1` (for
	 * a write offer only), nonce and return. The pending pairing is stored under the pairing lock, released before this
	 * returns.
	 *
	 * @param int    $user_id    User starting the pairing.
	 * @param string $scope      Scope offered.
	 * @param string $gateway    Gateway base URL.
	 * @param string $site       Site's MCP URL.
	 * @param string $return_url Where the gateway sends the user back.
	 * @param int    $now        Current Unix time.
	 * @return string
	 * @throws \InvalidArgumentException For an unknown scope.
	 * @throws \RuntimeException While another request holds the pairing lock, while a connection exists or when no key
	 *                           set has been fetched.
	 */
	public function start( int $user_id, string $scope, string $gateway, string $site, string $return_url, int $now ): string {
		if ( ! in_array( $scope, array( Context::SCOPE_READ, Context::SCOPE_WRITE ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown scope.' );
		}
		// The lock covers the connection check and the save, so no completion can store a connection in between.
		if ( ! $this->store->lock_pairing( $now ) ) {
			// A literal, not self::LOCKED, so Plugin Check sees a plain message; a test keeps the two the same.
			throw new \RuntimeException( 'Another connection attempt is finishing. Try again in a moment.' );
		}
		try {
			if ( null !== $this->store->connection() ) {
				throw new \RuntimeException( 'This site is already connected. Disconnect first.' );
			}
			if ( $this->store->keys()->is_empty() ) {
				throw new \RuntimeException( 'No gateway keys have been fetched.' );
			}

			$nonce = bin2hex( random_bytes( 32 ) );
			$this->store->save_pending(
				array(
					'nonce_hash' => hash( 'sha256', $nonce ),
					'user_id'    => $user_id,
					'scope'      => $scope,
					'expires_at' => $now + self::TTL,
				)
			);
		} finally {
			$this->store->unlock_pairing();
		}

		// `access_choice=1` on a write offer tells flitagent.com this plugin reads the Access chosen on its Approve page
		// (see complete()), so it may ask; a read offer has no choice to make.
		return $gateway . '/pair?' . http_build_query(
			array_merge(
				array(
					'site'  => $site,
					'scope' => $scope,
				),
				Context::SCOPE_WRITE === $scope ? array( 'access_choice' => '1' ) : array(),
				array(
					'nonce'  => $nonce,
					'return' => $return_url,
				)
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * The Access the customer chose on flitagent.com's Approve page, as the last complete() accepted it from the signed
	 * result: `read` or `write`. '' when the last complete() was refused, the result carried no valid `access` (an older
	 * flitagent.com), or the connection was granted `read` (the site's setting is then left as it is).
	 *
	 * @return string
	 */
	public function chosen_access(): string {
		return $this->chosen_access;
	}

	/**
	 * Whether the last complete() stored the connection but Read and propose changes was chosen and does not hold for
	 * it: the pairing lock was lost just before it (not applied, the setting is as it was); the apply returned false or
	 * its option hook threw (the setting is as it was, or as the hook left it); or the check after it found the lock
	 * lost or the stored connection no longer this one, and Read only was applied again (unless that apply threw too).
	 *
	 * @return bool
	 */
	public function access_failed(): bool {
		return $this->access_failed;
	}

	/**
	 * Accepts a signed pairing result and stores the connection. Takes the pairing lock before it reads the pending
	 * pairing and releases it on every path.
	 *
	 * @param string        $encoded      `flitagent_pairing`: base64url JSON with connection_id, nonce, scope, site and
	 *                                    kid, and optionally account_hint and access (see chosen_access()).
	 * @param string        $signature    `flitagent_signature`: base64url Ed25519 signature.
	 * @param int           $user_id      User completing the pairing.
	 * @param string        $site         Site's MCP URL.
	 * @param int           $now          Current Unix time.
	 * @param callable|null $apply_access fn( string $access ): bool, called with the chosen access (see chosen_access())
	 *                                    after every check passes, each time right after checking this request still
	 *                                    holds the pairing lock: `read` before the connection is stored (false or a
	 *                                    throw refuses the pairing: fail closed), `write` only after it is stored (its
	 *                                    answer is ignored and a throw is caught: the pairing stands). Called once more
	 *                                    with `read` when the check after a `write` fails (see complete_locked()).
	 * @return Connection|null Null when refused (this pairing's connection is not left stored; a Read only choice may
	 *                         already be stored), and
	 *                         while another request holds the pairing lock (the pending pairing is then left as it is).
	 */
	public function complete( string $encoded, string $signature, int $user_id, string $site, int $now, ?callable $apply_access = null ): ?Connection {
		$this->chosen_access = '';
		$this->access_failed = false;
		if ( ! $this->store->lock_pairing( $now ) ) {
			return null;
		}
		try {
			return $this->complete_locked( $encoded, $signature, $user_id, $site, $now, $apply_access );
		} finally {
			$this->store->unlock_pairing();
		}
	}

	/**
	 * The body of complete(), run under the pairing lock. Right before each change it makes (Read only, the connection,
	 * Read and propose changes) it checks the lock is still its own: one taken over after its expiry means another
	 * pairing may be under way, so it stops. Before the connection is stored that refuses the pairing; after, Read and
	 * propose changes is not applied and access_failed() says so.
	 *
	 * A check and the write after it are separate steps, so a request can pass a check, stall past the lock's expiry
	 * inside the write and resume after another pairing took over. A lock still this request's own and not expired at a
	 * check was never taken over before it, so the writes are guarded by checks after them:
	 * - The connection is stored add-only: a connection already stored when the insert runs wins and this pairing is
	 *   refused. The lock is checked again right after: if it was lost, the insert may have landed after another pairing
	 *   connected and the site was disconnected, so this exact connection is taken back (Store::remove_connection()) and
	 *   the pairing refused. Read only is not applied again then, so a successor's own choice is not changed.
	 * - Read and propose changes is checked again once applied (see apply_write()) and switched back to Read only if
	 *   the lock was lost or the stored connection is not this one. That can turn back a successor's own Read and
	 *   propose changes choice (the safe direction).
	 * - A Read only apply that lands late can leave a successor's Read and propose changes choice off; re-applying
	 *   write would be unsafe, so it is not checked again.
	 * Not covered: a request that stalls past the expiry inside the write apply and then dies before its re-check (or
	 * whose Read only revert throws) leaves writes on until Access for AI tools is next saved; so does a Read only
	 * completion whose connection lands after the expiry (a successor connected with write and was disconnected
	 * meanwhile) and that dies before its check after storing, leaving its connection stored. The pending pairing is
	 * deleted under the same lease, so a completion that stalls past the expiry before deleting it can delete one a
	 * later start() stored (that pairing's return is then refused).
	 *
	 * @param string        $encoded      See complete().
	 * @param string        $signature    See complete().
	 * @param int           $user_id      See complete().
	 * @param string        $site         See complete().
	 * @param int           $now          See complete().
	 * @param callable|null $apply_access See complete().
	 * @return Connection|null
	 */
	private function complete_locked( string $encoded, string $signature, int $user_id, string $site, int $now, ?callable $apply_access ): ?Connection {
		$pending = $this->store->pending();
		if ( null === $pending || strlen( $encoded ) > self::MAX_RESULT ) {
			return null;
		}
		$json = Signature::decode( $encoded );
		$sig  = Signature::decode( $signature );
		if ( '' === $json ) {
			return null;
		}
		$result = json_decode( $json, true );
		if ( ! is_array( $result ) || ! isset( $result['kid'] ) || ! is_string( $result['kid'] ) ) {
			return null;
		}
		$key = $this->store->keys()->get( $result['kid'] );
		if ( null === $key || ! Signature::verify( Signature::pairing_message( $json ), $sig, $key ) ) {
			return null;
		}

		foreach ( array( 'connection_id', 'nonce', 'scope', 'site' ) as $field ) {
			if ( ! is_array( $result ) || ! isset( $result[ $field ] ) || ! is_string( $result[ $field ] ) ) {
				return null;
			}
		}
		if ( ! hash_equals( (string) $pending['nonce_hash'], hash( 'sha256', $result['nonce'] ) ) ) {
			return null;
		}

		// The nonce matched: it is used up, whatever happens next.
		$this->store->delete_pending();

		$offered = (string) $pending['scope'];
		$granted = $result['scope'];
		if (
			$now > (int) $pending['expires_at']
			|| (int) $pending['user_id'] !== $user_id
			|| ! in_array( $granted, array( Context::SCOPE_READ, Context::SCOPE_WRITE ), true )
			|| ( Context::SCOPE_READ === $offered && Context::SCOPE_READ !== $granted )
			|| ! hash_equals( $site, $result['site'] )
			|| null !== $this->store->connection()
		) {
			return null;
		}

		try {
			$connection = new Connection( $result['connection_id'], $user_id, $granted, $now, isset( $result['account_hint'] ) && is_string( $result['account_hint'] ) ? $result['account_hint'] : '' );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
		// `scope` is the ceiling granted; `access` is the customer's choice under it, so only a write grant carries one.
		$access = $result['access'] ?? null;
		$chosen = Context::SCOPE_WRITE === $granted && in_array( $access, array( Context::SCOPE_READ, Context::SCOPE_WRITE ), true ) ? $access : '';
		// Read only goes first and must hold (fail closed), so the connection never goes live with writes on. Read and
		// propose changes goes only once the connection is stored, so a refused or failed pairing never turns writes on;
		// if it does not hold, the setting stays as it was and the pairing stands.
		if ( Context::SCOPE_READ === $chosen && null !== $apply_access ) {
			if ( ! $this->store->holds_pairing_lock() ) {
				return null;
			}
			try {
				$held = true === call_user_func( $apply_access, $chosen );
			} catch ( \Throwable $e ) {
				// Another plugin's option hook threw: refuse the pairing like any Read only that did not hold.
				$held = false;
			}
			if ( ! $held ) {
				return null;
			}
		}
		if ( ! $this->store->holds_pairing_lock() ) {
			return null;
		}
		// Add-only: a connection stored meanwhile (by a pairing that took the lock over) wins.
		if ( ! $this->store->add_connection( $connection ) || ! $this->is_stored( $connection ) ) {
			// The store may also fail without saying so (an option write that did not land): only a read-back counts.
			return null;
		}
		// The insert itself may have stalled past the lock's expiry while another pairing connected and the site was
		// disconnected again, so it landed on an empty row: take this connection back and refuse. Read only is not
		// applied again: with no connection of this pairing left, the setting belongs to whoever pairs next, and a
		// live successor's own choice must not be changed.
		if ( ! $this->store->holds_pairing_lock() ) {
			$this->store->remove_connection( $connection );
			return null;
		}
		$this->chosen_access = $chosen;
		if ( Context::SCOPE_WRITE === $chosen && null !== $apply_access && ! $this->apply_write( $connection, $apply_access ) ) {
			$this->chosen_access = '';
			$this->access_failed = true;
		}
		return $connection;
	}

	/**
	 * Turns writes on for a stored connection, only right after checking the lock is still this request's, and checks
	 * again once the write is applied: if the lock was lost meanwhile (the write stalled past its expiry while another
	 * pairing took over) or the stored row is no longer this connection (the site was disconnected, by this request or
	 * another), it applies Read only, the safe direction; a throw there is caught, and the setting is then as that hook
	 * left it. When the re-check passes, no other pairing held the lock while the write was applied; a disconnect right
	 * after the re-check leaves writes on with no connection for them to reach.
	 *
	 * @param Connection $connection   The connection this pairing stored.
	 * @param callable   $apply_access See complete().
	 * @return bool Whether Read and propose changes now holds for this pairing.
	 */
	private function apply_write( Connection $connection, callable $apply_access ): bool {
		// A lock lost by now may already belong to a pairing that chose Read only: do not turn writes on then.
		if ( ! $this->store->holds_pairing_lock() ) {
			return false;
		}
		try {
			$held = true === call_user_func( $apply_access, Context::SCOPE_WRITE );
		} catch ( \Throwable $e ) {
			// Another plugin's option hook threw: the connection stands, and the setting is as the hook left it.
			$held = false;
		}
		if ( $this->store->holds_pairing_lock() && $this->is_stored( $connection ) ) {
			return $held;
		}
		try {
			call_user_func( $apply_access, Context::SCOPE_READ );
		} catch ( \Throwable $e ) {
			// Another plugin's option hook threw; nothing more can be done here.
			return false;
		}
		return false;
	}

	/**
	 * Whether the stored connection, read from its row (never a copy cached earlier in this request, which would miss a
	 * disconnect by another request), is this one.
	 *
	 * @param Connection $connection Connection.
	 */
	private function is_stored( Connection $connection ): bool {
		$stored = $this->store->stored_connection();
		return null !== $stored && hash_equals( $connection->id(), $stored->id() );
	}

	/**
	 * Drops the pending pairing when the nonce is the one it was started with (the gateway refused or the admin
	 * cancelled there). Anything else changes nothing, so a crafted link cannot cancel someone's pairing.
	 *
	 * Under the pairing lock, like every other read-then-change of the pending pairing, so a start() cannot store a new
	 * pending pairing between this cancel's read and its delete while it holds the lock. The lock is a lease: a cancel
	 * that stalls past its expiry between the two can still delete a pending pairing a later start() stored (that
	 * pairing's return is then refused). While another request holds the lock it changes nothing.
	 *
	 * @param string $nonce Nonce from the return URL.
	 * @param int    $now   Current Unix time.
	 * @return bool Whether a pending pairing was dropped.
	 */
	public function cancel( string $nonce, int $now ): bool {
		if ( '' === $nonce || ! $this->store->lock_pairing( $now ) ) {
			return false;
		}
		try {
			$pending = $this->store->pending();
			if ( null === $pending || ! hash_equals( (string) $pending['nonce_hash'], hash( 'sha256', $nonce ) ) ) {
				return false;
			}
			$this->store->delete_pending();
			return true;
		} finally {
			$this->store->unlock_pairing();
		}
	}
}
