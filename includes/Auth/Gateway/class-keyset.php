<?php
/**
 * The gateway's published signing keys.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * A set of Ed25519 public keys by key id, as served at `<gateway>/public-key`:
 * `{"keys":[{"kid":"k2026a","alg":"Ed25519","public_key":"<base64url, 32 bytes>"}]}`. WordPress-free.
 */
final class KeySet {

	const KID_PATTERN = '/^[A-Za-z0-9_-]{1,32}\z/';
	const MAX_KEYS    = 5;

	/**
	 * Raw 32-byte keys by key id.
	 *
	 * @var array<string,string>
	 */
	private $keys;

	/**
	 * Holds validated keys.
	 *
	 * @param array<string,string> $keys Raw keys by key id (already validated).
	 */
	private function __construct( array $keys ) {
		$this->keys = $keys;
	}

	/**
	 * Parses a key set document. Only the index shape is accepted.
	 *
	 * @param string $body Response body.
	 * @return KeySet
	 * @throws \RuntimeException When the body is not a valid key set.
	 */
	public static function from_json( string $body ): KeySet {
		$document = json_decode( $body, true );
		$list     = is_array( $document ) && isset( $document['keys'] ) && is_array( $document['keys'] ) ? $document['keys'] : null;
		if ( null === $list || array_keys( $list ) !== range( 0, count( $list ) - 1 ) || count( $list ) < 1 || count( $list ) > self::MAX_KEYS ) {
			throw new \RuntimeException( 'The gateway did not return a valid key set.' );
		}
		$keys = array();
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['kid'], $entry['alg'], $entry['public_key'] ) || ! is_string( $entry['kid'] ) || ! is_string( $entry['alg'] ) || ! is_string( $entry['public_key'] ) ) {
				throw new \RuntimeException( 'The gateway did not return a valid key set.' );
			}
			$raw = Signature::decode( $entry['public_key'] );
			if ( 1 !== preg_match( self::KID_PATTERN, $entry['kid'] ) || 'Ed25519' !== $entry['alg'] || 32 !== strlen( $raw ) || isset( $keys[ $entry['kid'] ] ) ) {
				throw new \RuntimeException( 'The gateway did not return a valid key set.' );
			}
			$keys[ $entry['kid'] ] = $raw;
		}
		return new self( $keys );
	}

	/**
	 * Rebuilds a set from stored data (key id => base64url key). Anything invalid gives an empty set.
	 *
	 * @param mixed $stored Stored value.
	 * @return KeySet
	 */
	public static function from_array( $stored ): KeySet {
		if ( ! is_array( $stored ) || count( $stored ) > self::MAX_KEYS ) {
			return new self( array() );
		}
		$keys = array();
		foreach ( $stored as $kid => $encoded ) {
			// PHP turns a numeric key id such as "1234" into an int array key.
			$kid = (string) $kid;
			$raw = is_string( $encoded ) ? Signature::decode( $encoded ) : '';
			if ( 1 !== preg_match( self::KID_PATTERN, $kid ) || 32 !== strlen( $raw ) ) {
				return new self( array() );
			}
			$keys[ $kid ] = $raw;
		}
		return new self( $keys );
	}

	/**
	 * Key id => base64url key, for storage.
	 *
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return array_map( array( Signature::class, 'encode' ), $this->keys );
	}

	/**
	 * The raw key of a key id.
	 *
	 * @param string $kid Key id.
	 * @return string|null
	 */
	public function get( string $kid ): ?string {
		return $this->keys[ $kid ] ?? null;
	}

	/**
	 * Key ids, in published order.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_map( 'strval', array_keys( $this->keys ) );
	}

	/**
	 * Whether the set holds no key.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->keys;
	}
}
