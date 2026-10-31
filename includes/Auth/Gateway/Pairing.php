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
	 * Starts a pairing and returns the gateway URL to send the user to.
	 *
	 * @param int    $user_id    User starting the pairing.
	 * @param string $scope      Scope offered.
	 * @param string $gateway    Gateway base URL.
	 * @param string $site       Site's MCP URL.
	 * @param string $return_url Where the gateway sends the user back.
	 * @param int    $now        Current Unix time.
	 * @return string
	 * @throws \InvalidArgumentException For an unknown scope.
	 * @throws \RuntimeException While a connection exists or when no key set has been fetched.
	 */
	public function start( int $user_id, string $scope, string $gateway, string $site, string $return_url, int $now ): string {
		if ( ! in_array( $scope, array( Context::SCOPE_READ, Context::SCOPE_WRITE ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown scope.' );
		}
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

		return $gateway . '/pair?' . http_build_query(
			array(
				'site'   => $site,
				'scope'  => $scope,
				'nonce'  => $nonce,
				'return' => $return_url,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Accepts a signed pairing result and stores the connection.
	 *
	 * @param string $encoded   `flitagent_pairing`: base64url JSON with connection_id, nonce, scope, site and kid.
	 * @param string $signature `flitagent_signature`: base64url Ed25519 signature.
	 * @param int    $user_id   User completing the pairing.
	 * @param string $site      Site's MCP URL.
	 * @param int    $now       Current Unix time.
	 * @return Connection|null Null when refused (nothing stored).
	 */
	public function complete( string $encoded, string $signature, int $user_id, string $site, int $now ): ?Connection {
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
		$this->store->save_connection( $connection );
		return $connection;
	}

	/**
	 * Drops the pending pairing when the nonce is the one it was started with (the gateway refused or the admin
	 * cancelled there). Anything else changes nothing, so a crafted link cannot cancel someone's pairing.
	 *
	 * @param string $nonce Nonce from the return URL.
	 * @return bool Whether a pending pairing was dropped.
	 */
	public function cancel( string $nonce ): bool {
		$pending = $this->store->pending();
		if ( '' === $nonce || null === $pending || ! hash_equals( (string) $pending['nonce_hash'], hash( 'sha256', $nonce ) ) ) {
			return false;
		}
		$this->store->delete_pending();
		return true;
	}
}
