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
