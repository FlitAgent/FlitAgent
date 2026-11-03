<?php
/**
 * Verifies gateway-signed MCP requests.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the `FlitAgent-Connection`, `-Timestamp`, `-Nonce`, `-Key-Id`, `-Client` and `-Signature` headers of a request
 * against the stored connection and the gateway's key set. WordPress-free. Every failure is the same null, so callers
 * answer with one generic 401; nothing about the request (signature, body) is logged or returned. The one exception is
 * refusal(): NOT_CONNECTED when the request verified in full (only flitagent.com can sign it) but was signed for a
 * connection the site does not hold, or the site holds none, so flitagent.com learns that the site disconnected.
 *
 * Order: header syntax, the timestamp window, the key lookup (key_for, which may refresh the key set, only when the
 * signed connection id is the stored one, compared in constant time; otherwise the stored key set alone, so a request
 * for another connection never makes the site fetch keys), the Ed25519 signature (which covers the connection id and
 * the client header), the client identity, the connection comparison, and only then the nonce, so forged requests
 * cannot use up nonces.
 */
final class Verifier {

	/**
	 * Allowed clock difference, in seconds (5 minutes).
	 */
	const WINDOW = 300;

	/**
	 * The only signed path.
	 */
	const PATH = '/flitagent/v1/mcp';

	/**
	 * Nonces: 32 lowercase hex digits.
	 */
	const NONCE_PATTERN = '/^[0-9a-f]{32}\z/';

	/**
	 * Header names (lower case).
	 */
	const HEADERS = array( 'flitagent-connection', 'flitagent-timestamp', 'flitagent-nonce', 'flitagent-key-id', 'flitagent-client', 'flitagent-signature' );

	/**
	 * Refusal reason: the request verified but was signed for a connection the site does not hold (or it holds none).
	 */
	const NOT_CONNECTED = 'not_connected';

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Returns the raw public key of a key id, or null.
	 *
	 * @var callable
	 */
	private $key_for;

	/**
	 * Why the last verify() returned null: NOT_CONNECTED, or '' (the generic refusal, or no refusal).
	 *
	 * @var string
	 */
	private $refusal = '';

	/**
	 * Constructor.
	 *
	 * @param Store    $store   Store.
	 * @param callable $key_for fn( string $kid ): ?string, the raw public key (Keys::key in production).
	 */
	public function __construct( Store $store, callable $key_for ) {
		$this->store   = $store;
		$this->key_for = $key_for;
	}

	/**
	 * Whether a request carries any gateway header (then it must verify, or it is refused).
	 *
	 * @param array<string, mixed> $headers Lower-case header name => value.
	 * @return bool
	 */
	public static function has_headers( array $headers ): bool {
		foreach ( self::HEADERS as $name ) {
			if ( isset( $headers[ $name ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The verified request, or null when anything does not verify.
	 *
	 * @param array<string, mixed> $headers Lower-case header name => value.
	 * @param string               $method  HTTP method, in any case (only POST is accepted).
	 * @param string               $body    Raw body.
	 * @param int                  $now     Current Unix time.
	 * @return Verified|null
	 */
	public function verify( array $headers, string $method, string $body, int $now ): ?Verified {
		$this->refusal = '';
		if ( 'POST' !== strtoupper( $method ) ) {
			return null;
		}
		$values = array();
		foreach ( self::HEADERS as $name ) {
			$max = 'flitagent-client' === $name ? Client::MAX_HEADER : 256;
			if ( ! isset( $headers[ $name ] ) || ! is_string( $headers[ $name ] ) || strlen( $headers[ $name ] ) > $max ) {
				return null;
			}
			$values[ $name ] = $headers[ $name ];
		}
		$id        = $values['flitagent-connection'];
		$timestamp = $values['flitagent-timestamp'];
		$nonce     = $values['flitagent-nonce'];
		$kid       = $values['flitagent-key-id'];
		$client    = $values['flitagent-client'];
		$signature = Signature::decode( $values['flitagent-signature'] );
		if ( 1 !== preg_match( Connection::ID_PATTERN, $id ) || 1 !== preg_match( '/^[1-9][0-9]{0,11}\z/', $timestamp ) || 1 !== preg_match( self::NONCE_PATTERN, $nonce ) || 64 !== strlen( $signature ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,32}\z/', $kid ) || 1 !== preg_match( '/^[A-Za-z0-9_-]+\z/', $client ) ) {
			return null;
		}

		if ( abs( $now - (int) $timestamp ) > self::WINDOW ) {
			return null;
		}
		$connection = $this->store->connection();
		$held       = null !== $connection && hash_equals( $connection->id(), $id );
		$key        = $held ? call_user_func( $this->key_for, $kid ) : $this->store->keys()->get( $kid );
		if ( ! is_string( $key ) || ! Signature::verify( Signature::request_message( $method, self::PATH, $body, $timestamp, $nonce, $id, $kid, $client ), $signature, $key ) ) {
			return null;
		}
		$app = Client::from_header( $client );
		if ( null === $app ) {
			return null;
		}
		if ( ! $held ) {
			$this->refusal = self::NOT_CONNECTED;
			return null;
		}
		if ( ! $this->store->remember_nonce( hash( 'sha256', $id . "\n" . $nonce ), (int) $timestamp + self::WINDOW, $now ) ) {
			return null;
		}
		return new Verified( $connection, $app, (int) $timestamp );
	}

	/**
	 * Why the last verify() returned null: NOT_CONNECTED for a request that verified but was signed for a connection the
	 * site does not hold (or the site holds none); '' for every other refusal and after a verified request.
	 *
	 * @return string
	 */
	public function refusal(): string {
		return $this->refusal;
	}
}
