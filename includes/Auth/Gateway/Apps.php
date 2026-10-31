<?php
/**
 * The AI apps behind verified gateway requests, and the tools disconnected on this site.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Every verified gateway request names its AI app (Client: id, name, scope), except flitagent.com's own requests (client
 * id RELAY), which are never recorded or blocked. Apps records each one after verification
 * only (id, name, effective scope, first and last seen, and the connection it was seen on; never anything from the
 * request itself), in a list capped at MAX entries, and maps each app to one of three tools by its name: ChatGPT (ChatGPT, OpenAI), Claude (Claude, but not
 * Claude Code) and Manual configuration (everything else: Claude Code, Codex, other clients). An administrator can
 * disconnect a tool: its apps are forgotten and every verified request whose app maps to it is refused
 * (Authenticator). The name is the one flitagent.com signed for the app, so this is a per-site switch for the apps an
 * administrator set up, not a defence against a client that names itself after another tool; revoking an app's grant
 * happens on flitagent.com. Records of another connection than the current one are ignored and dropped on the next write,
 * so a request still in flight when the site was disconnected writes nothing back. Deleting the connection forgets the
 * apps but keeps the disconnected tools (Store::delete_connection()): their grants on flitagent.com survive it, so only
 * Set up (unblock()) or uninstall lets them back in. flitagent.com tells the site about an app as soon as it is allowed
 * (an `initialize` signed with the app's client: recorded with no request yet) and when it is revoked (forget()).
 * WordPress-free.
 */
final class Apps {

	const CHATGPT = 'chatgpt';
	const CLAUDE  = 'claude';
	const MANUAL  = 'manual';
	const TOOLS   = array( self::CHATGPT, self::CLAUDE, self::MANUAL );

	/**
	 * The client id flitagent.com signs its own requests with (its check page and cache refreshes; flitclient's
	 * `Signer::no_app()`). Such a request is the relay itself, not an AI app.
	 */
	const RELAY = 'flitagent.com';

	/**
	 * Most apps kept; the one seen longest ago is dropped first.
	 */
	const MAX = 20;

	/**
	 * Seconds within which a request from a recorded app (same name and scope) writes nothing.
	 */
	const THROTTLE = 60;

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Store $store Store.
	 */
	public function __construct( Store $store ) {
		$this->store = $store;
	}

	/**
	 * The tool an app name maps to. Claude Code and Codex are checked first, so "OpenAI Codex" and "Claude Code" are
	 * Manual configuration.
	 *
	 * @param string $name App name.
	 * @return string One of TOOLS.
	 */
	public static function tool( string $name ): string {
		$name = strtolower( $name );
		if ( 1 === preg_match( '/claude[\s_.-]*code|codex/', $name ) ) {
			return self::MANUAL;
		}
		if ( false !== strpos( $name, 'chatgpt' ) || false !== strpos( $name, 'openai' ) ) {
			return self::CHATGPT;
		}
		if ( false !== strpos( $name, 'claude' ) ) {
			return self::CLAUDE;
		}
		return self::MANUAL;
	}

	/**
	 * Whether a request comes from flitagent.com itself (client id RELAY), not from an AI app: it is never recorded,
	 * never blocked and never counts as a tool. Only flitagent.com signs requests, and it gives no app this id.
	 *
	 * @param Client $client Client.
	 * @return bool
	 */
	public static function is_relay( Client $client ): bool {
		return self::RELAY === $client->id();
	}

	/**
	 * The English name of a tool, for the error an AI app gets.
	 *
	 * @param string $tool One of TOOLS.
	 * @return string
	 */
	public static function label( string $tool ): string {
		$labels = array(
			self::CHATGPT => 'ChatGPT',
			self::CLAUDE  => 'Claude',
			self::MANUAL  => 'Manual configuration',
		);
		return $labels[ $tool ] ?? $tool;
	}

	/**
	 * Records the app of a verified request. An announcement is always written; a request of the app's own only when
	 * the app is new, its name or scope changed, it was last seen THROTTLE seconds ago or more (or the clock went back),
	 * or this is its first request after it was announced.
	 *
	 * @param Verified $verified Verified request.
	 * @param int      $now      Current Unix time.
	 * @param bool     $request  Whether this is a request of the app's own; false for flitagent.com announcing an app
	 *                           it just allowed (an `initialize`), which records the app with no request yet.
	 */
	public function record( Verified $verified, int $now, bool $request = true ): void {
		$connection = $this->store->connection();
		$client     = $verified->client();
		// A request still in flight when the site was disconnected (or re-paired), or its tool was, writes nothing back.
		if ( self::is_relay( $client ) || null === $connection || ! hash_equals( $connection->id(), $verified->connection()->id() ) || $this->is_blocked( $client ) ) {
			return;
		}
		$scope = $verified->scope();
		$key   = self::key( $client->id() );
		$apps  = $this->apps();
		$known = $apps[ $key ] ?? null;
		$first = $request && null !== $known && 0 === $known['last_request'];
		// Only an app's own requests are throttled: an announcement (flitagent.com allowed the app) is always written.
		if ( null !== $known && $request && ! $first && $known['name'] === $client->name() && $known['scope'] === $scope && $now >= $known['last_seen'] && $now - $known['last_seen'] < self::THROTTLE ) {
			return;
		}
		$signed = $verified->signed_at() > 0 ? $verified->signed_at() : $now;
		// Read just before saving, since the list read above may be stale: a request signed before flitagent.com revoked
		// its app (still in flight when forget() ran) writes nothing back, and no app revoked meanwhile is saved again,
		// whichever app's request this is.
		$revoked = $this->revocations( $connection->id() );
		if ( isset( $revoked[ $key ] ) && $signed <= $revoked[ $key ]['at'] ) {
			return;
		}
		// A known entry from before the app's revocation (saved back by a stale list) is gone: an app allowed again
		// starts afresh, with no first seen or last request from before.
		if ( null !== $known && isset( $revoked[ $key ] ) && $known['signed_at'] <= $revoked[ $key ]['at'] ) {
			$known = null;
		}
		unset( $apps[ $key ] );
		$app  = array(
			'id'           => $client->id(),
			'name'         => $client->name(),
			'scope'        => $scope,
			'first_seen'   => null !== $known ? $known['first_seen'] : $now,
			'last_seen'    => $now,
			'last_request' => $request ? $now : ( null !== $known ? $known['last_request'] : 0 ),
			'signed_at'    => null !== $known ? max( $known['signed_at'], $signed ) : $signed,
			'connection'   => $connection->id(),
		);
		$apps = self::without_revoked( array( $key => $app ) + $apps, $revoked );
		// At MAX, an app that has made requests is never dropped for one flitagent.com only announced: the ones without a
		// request go first, then the ones seen longest ago. So with MAX apps that made requests, an app flitagent.com
		// just announced is not kept and its tool reads Not connected until the app's first request, which then drops
		// the app seen longest ago.
		uasort(
			$apps,
			static function ( array $a, array $b ): int {
				return array( $b['last_request'] > 0, $b['last_seen'] ) <=> array( $a['last_request'] > 0, $a['last_seen'] );
			}
		);
		$this->store->save_apps( array_slice( $apps, 0, self::MAX, true ) );
	}

	/**
	 * The stored revocations of a connection that have the expected shape.
	 *
	 * @param string $connection Connection id.
	 * @return array<string, array{at: int, connection: string}>
	 */
	private function revocations( string $connection ): array {
		return self::valid_revocations( $this->store->revoked_apps(), $connection );
	}

	/**
	 * The revocations of a connection that have the expected shape, out of stored ones.
	 *
	 * @param array<mixed> $stored     Stored revocations.
	 * @param string       $connection Connection id.
	 * @return array<string, array{at: int, connection: string}>
	 */
	private static function valid_revocations( array $stored, string $connection ): array {
		$revoked = array();
		foreach ( $stored as $key => $entry ) {
			if ( is_string( $key ) && is_array( $entry ) && isset( $entry['at'], $entry['connection'] ) && is_int( $entry['at'] ) && is_string( $entry['connection'] ) && hash_equals( $connection, $entry['connection'] ) ) {
				$revoked[ $key ] = array(
					'at'         => $entry['at'],
					'connection' => $entry['connection'],
				);
			}
		}
		return $revoked;
	}

	/**
	 * The apps without those revoked at or after their time: the latest time flitagent.com signed a request of the app
	 * (`signed_at`, its clock, as the revocation's), or its last seen for a record from before that was kept.
	 *
	 * @param array<string, array{signed_at: int}> $apps    Apps by key.
	 * @param array<string, array{at: int}>        $revoked Revocations by app key.
	 * @return array<string, array{signed_at: int}>
	 */
	private static function without_revoked( array $apps, array $revoked ): array {
		foreach ( $apps as $key => $app ) {
			if ( isset( $revoked[ $key ] ) && $app['signed_at'] <= $revoked[ $key ]['at'] ) {
				unset( $apps[ $key ] );
			}
		}
		return $apps;
	}

	/**
	 * Forgets an app flitagent.com says was revoked (`flitagent/app_revoked`, signed with that app's client): its record
	 * goes, so its tool reads Not connected until it is allowed again, and a revocation (the revoke's signed time) is
	 * kept so that a request of the app signed before it, still in flight, cannot record it again (record()). The tool
	 * is not blocked. The relay is never an app, and a revoke for another connection than the current one does nothing.
	 * At most MAX revocations are kept, the latest first.
	 *
	 * @param Verified $verified The verified revoke.
	 * @param int      $now      Current Unix time (used when the signed time is unknown).
	 */
	public function forget( Verified $verified, int $now ): void {
		$connection = $this->store->connection();
		$client     = $verified->client();
		if ( self::is_relay( $client ) || null === $connection || ! hash_equals( $connection->id(), $verified->connection()->id() ) ) {
			return;
		}
		$key = self::key( $client->id() );
		$id  = $connection->id();
		$at  = $verified->signed_at() > 0 ? $verified->signed_at() : $now;
		// One atomic change of the stored list, so a concurrent revoke of another app is never lost.
		$this->store->update_revoked_apps(
			static function ( array $stored ) use ( $key, $id, $at ): array {
				$revoked = self::valid_revocations( $stored, $id );
				$revoked = array(
					$key => array(
						'at'         => max( $at, $revoked[ $key ]['at'] ?? 0 ),
						'connection' => $id,
					),
				) + $revoked;
				uasort(
					$revoked,
					static function ( array $a, array $b ): int {
						return $b['at'] <=> $a['at'];
					}
				);
				return array_slice( $revoked, 0, self::MAX, true );
			}
		);

		$apps = $this->store->apps();
		if ( array_key_exists( $key, $apps ) ) {
			unset( $apps[ $key ] );
			$this->store->save_apps( $apps );
		}
	}

	/**
	 * The connected tools, in TOOLS order: for each tool with a recorded app, the app names (most recent first), the
	 * scope of the most recent app, the first and last time any of its apps was seen, and the last request any of them
	 * made (0 while none has: flitagent.com only announced it).
	 *
	 * @return array<string, array{names: string[], scope: string, first_seen: int, last_seen: int, last_request: int}>
	 */
	public function tools(): array {
		$connection = $this->store->connection();
		// A revoked app left in a stale list (another app's record that read the list before the revoke) is not shown.
		$apps = null === $connection ? array() : self::without_revoked( $this->apps(), $this->revocations( $connection->id() ) );
		uasort(
			$apps,
			static function ( array $a, array $b ): int {
				return $b['last_seen'] <=> $a['last_seen'];
			}
		);
		$blocked = $this->blocked();
		$tools   = array();
		foreach ( $apps as $app ) {
			$tool = self::tool( $app['name'] );
			if ( in_array( $tool, $blocked, true ) ) {
				continue;
			}
			if ( ! isset( $tools[ $tool ] ) ) {
				$tools[ $tool ] = array(
					'names'        => array(),
					'scope'        => $app['scope'],
					'first_seen'   => $app['first_seen'],
					'last_seen'    => $app['last_seen'],
					'last_request' => $app['last_request'],
				);
			}
			if ( ! in_array( $app['name'], $tools[ $tool ]['names'], true ) ) {
				$tools[ $tool ]['names'][] = $app['name'];
			}
			$tools[ $tool ]['first_seen']   = min( $tools[ $tool ]['first_seen'], $app['first_seen'] );
			$tools[ $tool ]['last_request'] = max( $tools[ $tool ]['last_request'], $app['last_request'] );
		}
		$ordered = array();
		foreach ( self::TOOLS as $tool ) {
			if ( isset( $tools[ $tool ] ) ) {
				$ordered[ $tool ] = $tools[ $tool ];
			}
		}
		return $ordered;
	}

	/**
	 * The tools disconnected on this site, in TOOLS order.
	 *
	 * @return string[]
	 */
	public function blocked(): array {
		$stored = $this->store->blocked_tools();
		return array_values(
			array_filter(
				self::TOOLS,
				static function ( string $tool ) use ( $stored ): bool {
					return in_array( $tool, $stored, true );
				}
			)
		);
	}

	/**
	 * Whether the app of a verified request maps to a disconnected tool.
	 *
	 * @param Client $client App.
	 * @return bool
	 */
	public function is_blocked( Client $client ): bool {
		return ! self::is_relay( $client ) && in_array( self::tool( $client->name() ), $this->blocked(), true );
	}

	/**
	 * Disconnects a tool on this site: refuses its apps from now on and forgets them.
	 *
	 * @param string $tool One of TOOLS.
	 * @throws \InvalidArgumentException For an unknown tool.
	 */
	public function block( string $tool ): void {
		self::check( $tool );
		$this->store->block_tool( $tool );
		$apps = $this->apps();
		$kept = array_filter(
			$apps,
			static function ( array $app ) use ( $tool ): bool {
				return self::tool( $app['name'] ) !== $tool;
			}
		);
		if ( count( $kept ) !== count( $apps ) ) {
			$this->store->save_apps( $kept );
		}
	}

	/**
	 * Allows a disconnected tool again.
	 *
	 * @param string $tool One of TOOLS.
	 * @throws \InvalidArgumentException For an unknown tool.
	 */
	public function unblock( string $tool ): void {
		self::check( $tool );
		$this->store->unblock_tool( $tool );
	}

	/**
	 * The stored apps of the current connection that have the expected shape (none while not connected). A record from
	 * before announcements has no `last_request`: every record then came from a request, so it is its last seen. A
	 * record from before #219 has no `signed_at`: its last seen stands in (the site's clock, not flitagent.com's).
	 *
	 * @return array<string, array{id: string, name: string, scope: string, first_seen: int, last_seen: int, last_request: int, signed_at: int, connection: string}>
	 */
	private function apps(): array {
		$connection = $this->store->connection();
		if ( null === $connection ) {
			return array();
		}
		$apps = array();
		foreach ( $this->store->apps() as $key => $app ) {
			if ( is_string( $key ) && is_array( $app ) && isset( $app['id'], $app['name'], $app['scope'], $app['first_seen'], $app['last_seen'], $app['connection'] ) && is_string( $app['id'] ) && is_string( $app['name'] ) && is_string( $app['scope'] ) && is_int( $app['first_seen'] ) && is_int( $app['last_seen'] ) && $connection->id() === $app['connection'] && self::RELAY !== $app['id'] ) {
				$apps[ $key ] = array(
					'id'           => $app['id'],
					'name'         => $app['name'],
					'scope'        => $app['scope'],
					'first_seen'   => $app['first_seen'],
					'last_seen'    => $app['last_seen'],
					'last_request' => isset( $app['last_request'] ) && is_int( $app['last_request'] ) ? $app['last_request'] : $app['last_seen'],
					'signed_at'    => isset( $app['signed_at'] ) && is_int( $app['signed_at'] ) ? $app['signed_at'] : $app['last_seen'],
					'connection'   => $app['connection'],
				);
			}
		}
		return $apps;
	}

	/**
	 * The key of an app: the first 40 hex digits of the SHA-256 of its id (ids can be long URLs).
	 *
	 * @param string $id App id.
	 * @return string
	 */
	private static function key( string $id ): string {
		return substr( hash( 'sha256', $id ), 0, 40 );
	}

	/**
	 * Refuses an unknown tool.
	 *
	 * @param string $tool Tool.
	 * @throws \InvalidArgumentException For an unknown tool.
	 */
	private static function check( string $tool ): void {
		if ( ! in_array( $tool, self::TOOLS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown tool.' );
		}
	}
}
