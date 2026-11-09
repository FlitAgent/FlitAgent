<?php
/**
 * Storage port for gateway authentication.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Where the site keeps its gateway connection, the pending pairing and the request nonces it has seen. Nothing
 * stored here is a secret: the gateway's public keys are published, the pending pairing holds only a hash of its nonce.
 */
interface Store {

	/**
	 * The active connection, or null when the site is not paired.
	 */
	public function connection(): ?Connection;

	/**
	 * Stores the connection, replacing any other (one connection per site).
	 *
	 * @param Connection $connection Connection.
	 */
	public function save_connection( Connection $connection ): void;

	/**
	 * Stores the connection only when none is stored, in one atomic step: of two concurrent calls at most one returns
	 * true, and an existing connection is never replaced (Pairing stores with it, so a pairing that lost its lock cannot
	 * overwrite the connection of the pairing that took it over). Like save_connection(), a connection added clears
	 * ended().
	 *
	 * @param Connection $connection Connection.
	 * @return bool Whether this call stored it.
	 */
	public function add_connection( Connection $connection ): bool;

	/**
	 * The stored connection read from its row, never a copy cached earlier in this request (another request may have
	 * deleted or replaced it since), or null.
	 */
	public function stored_connection(): ?Connection;

	/**
	 * Deletes the stored connection only while it is exactly this one, in one atomic step, and leaves everything else
	 * (the last request, the apps, the revocations, the end time) as it is. Pairing takes back a connection it added
	 * after losing the pairing lock.
	 *
	 * @param Connection $connection Connection.
	 * @return bool Whether this call deleted it.
	 */
	public function remove_connection( Connection $connection ): bool;

	/**
	 * Deletes the connection and forgets the last request time and the AI apps seen. The disconnected tools stay: their
	 * grants on flitagent.com survive a local disconnect, so connecting again must not let them back in.
	 */
	public function delete_connection(): void;

	/**
	 * The pending pairing, or null.
	 *
	 * @return array{nonce_hash: string, user_id: int, scope: string, expires_at: int}|null
	 */
	public function pending(): ?array;

	/**
	 * Stores the pending pairing, replacing any other.
	 *
	 * @param array{nonce_hash: string, user_id: int, scope: string, expires_at: int} $pending Pending pairing.
	 */
	public function save_pending( array $pending ): void;

	/**
	 * Deletes the pending pairing.
	 */
	public function delete_pending(): void;

	/**
	 * Records a request nonce. Atomic: of two concurrent calls with the same key, at most one returns true.
	 *
	 * @param string $key        Nonce key (a SHA-256 hex digest).
	 * @param int    $expires_at Unix time after which the nonce may be forgotten.
	 * @param int    $now        Current Unix time; expired nonces may be pruned.
	 * @return bool False when the nonce was already recorded (a replay).
	 */
	public function remember_nonce( string $key, int $expires_at, int $now ): bool;

	/**
	 * The stored gateway key set (empty when none was fetched yet).
	 */
	public function keys(): KeySet;

	/**
	 * Stores the gateway key set, unless the stored one came from a fetch that started at or after $started (so an
	 * older fetch finishing last never restores a removed key). Atomic against concurrent saves. A stored start time
	 * far ahead of the clock does not count. Never touches the attempt time.
	 *
	 * @param KeySet $keys    Key set.
	 * @param float  $started When the fetch that produced it started (microtime( true )).
	 */
	public function save_keys( KeySet $keys, float $started ): void;

	/**
	 * Unix time of the last key set fetch attempt, 0 when none.
	 */
	public function key_attempt(): int;

	/**
	 * Records the time of a key set fetch attempt. Never touches the stored key set.
	 *
	 * @param int $now Current Unix time.
	 */
	public function save_key_attempt( int $now ): void;

	/**
	 * Takes the key refresh lock, so concurrent requests usually fetch the key set once. A lock past its expiry is
	 * stale and taken over. Best effort: correctness comes from save_keys().
	 *
	 * @param int $now Current Unix time.
	 * @return bool False when another request holds the lock.
	 */
	public function lock_key_refresh( int $now ): bool;

	/**
	 * Releases the key refresh lock this store took, unless another request has taken it over since.
	 */
	public function unlock_key_refresh(): void;

	/**
	 * Takes the pairing lock, so only one request at a time starts, completes or cancels a pairing (Pairing), unless a
	 * holder stalls past the lock's expiry. Unlike the key refresh lock it is a mutex: of two concurrent calls, at most
	 * one returns true. A lock past its expiry (its holder stalled or died), or one expiring implausibly far ahead (it
	 * was written under a clock that ran fast), is stale and taken over, atomically too. Each lock carries its own
	 * random token, so its holder can tell whether it still holds it (holds_pairing_lock()). Holding it is a lease, not
	 * a fence: a holder that checked it and then stalls past its expiry can still write after another request took it
	 * over, so Pairing guards its writes on their own (add_connection() with a re-check after it, and a re-check after
	 * turning writes on).
	 *
	 * @param int $now Current Unix time.
	 * @return bool False when another request holds the lock.
	 */
	public function lock_pairing( int $now ): bool;

	/**
	 * Whether this store still holds the pairing lock it took: the stored lock (never a cached copy) is still the one it
	 * took, and has not expired, so no other request can have taken it over.
	 */
	public function holds_pairing_lock(): bool;

	/**
	 * Releases the pairing lock this store took, unless another request has taken it over since.
	 */
	public function unlock_pairing(): void;

	/**
	 * Unix time flitagent.com ended this site's connection (an account or its owner disconnected the site there, and
	 * flitagent.com sent the signed `flitagent/site_disconnected`), 0 when not. Saving a new connection clears it.
	 */
	public function ended(): int;

	/**
	 * Records that flitagent.com ended the connection (Authenticator, after deleting it).
	 *
	 * @param int $now Current Unix time.
	 */
	public function save_ended( int $now ): void;

	/**
	 * Unix time of the last verified request of an AI tool (Authenticator), 0 when none.
	 */
	public function last_request(): int;

	/**
	 * Records the time of a verified request of an AI tool. A later time is written at most once a minute; an earlier
	 * one (the clock went back, or Upgrade lowers it) always.
	 *
	 * @param int $now Current Unix time.
	 */
	public function save_last_request( int $now ): void;

	/**
	 * The AI apps seen on verified requests, as Apps::record() saved them (Apps validates what it reads).
	 *
	 * @return array<string, mixed>
	 */
	public function apps(): array;

	/**
	 * Stores the AI apps seen, replacing the list.
	 *
	 * @param array<string, array{id: string, name: string, scope: string, first_seen: int, last_seen: int, last_request: int, signed_at: int, connection: string}> $apps Apps by key.
	 */
	public function save_apps( array $apps ): void;

	/**
	 * The AI apps flitagent.com said were revoked, as Apps::forget() saved them (Apps validates what it reads). Always
	 * the stored value, never a copy cached earlier in the request: Apps::record() reads it just before it saves.
	 *
	 * @return array<string, mixed>
	 */
	public function revoked_apps(): array;

	/**
	 * Changes the revoked AI apps in one atomic step: $change gets the stored revocations and returns the ones to store.
	 * A concurrent change (another app's revoke) is never lost: when one lands between the read and the write, $change
	 * runs again on the fresh value. Gives up after a few such losses, each one another change that did land.
	 *
	 * @param callable $change Gets the stored revocations by app key (array<string, mixed>), returns the ones to store (array<string, array{at: int, connection: string}>).
	 */
	public function update_revoked_apps( callable $change ): void;

	/**
	 * The tools disconnected on this site (Apps::TOOLS values; Apps validates what it reads).
	 *
	 * @return array<int, mixed>
	 */
	public function blocked_tools(): array;

	/**
	 * Disconnects one tool on this site. Each tool's state is stored on its own, so concurrent changes to different
	 * tools never undo each other.
	 *
	 * @param string $tool One of Apps::TOOLS (anything else is ignored).
	 */
	public function block_tool( string $tool ): void;

	/**
	 * Allows one disconnected tool again.
	 *
	 * @param string $tool One of Apps::TOOLS.
	 */
	public function unblock_tool( string $tool ): void;
}
