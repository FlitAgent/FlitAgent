<?php
/**
 * Exact snapshots of a post's builder layout.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

use FlitAgent\Writes\Canonical;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, encodes, fingerprints and restores a post's layout state: the builder's meta keys (as `get_post_meta()`
 * returns them, so PHP arrays and stdClass objects stay what they are) plus `post_content`, which Elementor rewrites
 * on save.
 *
 * The encoding is lossless through JSON (receipts store `Canonical::json()`, which sorts keys and replaces invalid
 * UTF-8): every value is a tagged list, arrays and objects keep their key order as lists of pairs, and strings that are
 * not valid UTF-8 are base64. Restoring writes the same PHP values back, so WordPress stores the same bytes. Nothing is
 * ever unserialized here.
 *
 *     state = array( 'meta' => array( key => array( 'exists' => bool, 'value' => mixed ) ), 'post_content' => string )
 */
final class Snapshot {

	/**
	 * Current state of a post.
	 *
	 * @param object   $post Post.
	 * @param string[] $keys Meta keys.
	 * @return array{meta: array<string, array{exists: bool, value: mixed}>, post_content: string}
	 */
	public static function read( $post, array $keys ): array {
		$meta = array();
		foreach ( $keys as $key ) {
			$exists       = (bool) metadata_exists( 'post', (int) $post->ID, $key );
			$meta[ $key ] = array(
				'exists' => $exists,
				'value'  => $exists ? get_post_meta( (int) $post->ID, $key, true ) : null,
			);
		}
		return array(
			'meta'         => $meta,
			'post_content' => (string) $post->post_content,
		);
	}

	/**
	 * Meta values of a state, key => value (null when the key does not exist).
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	public static function values( array $state ): array {
		return array_map(
			static function ( $entry ) {
				return $entry['value'];
			},
			$state['meta']
		);
	}

	/**
	 * Fingerprint of a state: changes with any byte, type or key order.
	 *
	 * @param array<string, mixed> $state State.
	 * @return string
	 * @throws \UnexpectedValueException When a value cannot be encoded.
	 */
	public static function fingerprint( array $state ): string {
		return Canonical::hash( self::encode( $state ) );
	}

	/**
	 * Lossless, JSON-safe form of a state, for the receipt.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 * @throws \UnexpectedValueException When a value cannot be encoded (e.g. an object other than stdClass).
	 */
	public static function encode( array $state ): array {
		$meta = array();
		foreach ( $state['meta'] as $key => $entry ) {
			$meta[] = array( (string) $key, (bool) $entry['exists'], self::encode_value( $entry['value'] ) );
		}
		return array(
			'meta'         => $meta,
			'post_content' => self::encode_value( (string) $state['post_content'] ),
		);
	}

	/**
	 * A state from its encoded form.
	 *
	 * @param mixed $encoded Encoded state from the receipt.
	 * @return array<string, mixed>
	 * @throws \UnexpectedValueException When the encoding is not valid.
	 */
	public static function decode( $encoded ): array {
		if ( ! is_array( $encoded ) || ! isset( $encoded['meta'], $encoded['post_content'] ) || ! is_array( $encoded['meta'] ) ) {
			throw new \UnexpectedValueException( 'Not a snapshot.' );
		}
		$meta = array();
		foreach ( $encoded['meta'] as $entry ) {
			if ( ! is_array( $entry ) || 3 !== count( $entry ) || ! is_string( $entry[0] ) || ! is_bool( $entry[1] ) ) {
				throw new \UnexpectedValueException( 'Not a snapshot entry.' );
			}
			$meta[ $entry[0] ] = array(
				'exists' => $entry[1],
				'value'  => self::decode_value( $entry[2] ),
			);
		}
		$content = self::decode_value( $encoded['post_content'] );
		if ( ! is_string( $content ) ) {
			throw new \UnexpectedValueException( 'Not a snapshot content.' );
		}
		return array(
			'meta'         => $meta,
			'post_content' => $content,
		);
	}

	/**
	 * Writes a state back: post_content first (only if it differs), then every meta key, deleting keys that did not
	 * exist. Values are slashed deeply because update_post_meta() unslashes them (objects included).
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $state   State to restore.
	 * @param string               $content Current post_content.
	 */
	public static function restore( int $post_id, array $state, string $content ): void {
		if ( $content !== $state['post_content'] ) {
			wp_update_post(
				wp_slash(
					array(
						'ID'           => $post_id,
						'post_content' => $state['post_content'],
					)
				)
			);
		}
		foreach ( $state['meta'] as $key => $entry ) {
			if ( $entry['exists'] ) {
				update_post_meta( $post_id, $key, self::slash( $entry['value'] ) );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}

	/**
	 * Adds slashes to every string, in arrays and object properties alike (objects are copied).
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function slash( $value ) {
		if ( is_object( $value ) ) {
			$copy = clone $value;
			foreach ( get_object_vars( $copy ) as $key => $item ) {
				$copy->$key = self::slash( $item );
			}
			return $copy;
		}
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'slash' ), $value );
		}
		return is_string( $value ) ? addslashes( $value ) : $value;
	}

	/**
	 * Tagged form of one value.
	 *
	 * @param mixed $value Value.
	 * @return array<int, mixed>
	 * @throws \UnexpectedValueException For resources, non-finite floats and objects other than stdClass.
	 */
	private static function encode_value( $value ): array {
		if ( null === $value ) {
			return array( 'n' );
		}
		if ( is_bool( $value ) ) {
			return array( 'b', $value );
		}
		if ( is_int( $value ) ) {
			return array( 'i', $value );
		}
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				throw new \UnexpectedValueException( 'Non-finite number.' );
			}
			return array( 'd', $value );
		}
		if ( is_string( $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- lossless storage of non-UTF-8 bytes.
			return 1 === preg_match( '//u', $value ) ? array( 's', $value ) : array( 'x', base64_encode( $value ) );
		}
		if ( is_array( $value ) || ( is_object( $value ) && \stdClass::class === get_class( $value ) ) ) {
			$pairs = array();
			foreach ( ( is_array( $value ) ? $value : get_object_vars( $value ) ) as $key => $item ) {
				$pairs[] = array( $key, self::encode_value( $item ) );
			}
			return array( is_array( $value ) ? 'a' : 'o', $pairs );
		}
		throw new \UnexpectedValueException( 'Unsupported value.' );
	}

	/**
	 * A value from its tagged form.
	 *
	 * @param mixed $tagged Tagged value.
	 * @return mixed
	 * @throws \UnexpectedValueException When the tag or payload is not valid.
	 */
	private static function decode_value( $tagged ) {
		if ( ! is_array( $tagged ) || ! isset( $tagged[0] ) || ! is_string( $tagged[0] ) ) {
			throw new \UnexpectedValueException( 'Not a tagged value.' );
		}
		$tag     = $tagged[0];
		$payload = array_key_exists( 1, $tagged ) ? $tagged[1] : null;
		switch ( $tag ) {
			case 'n':
				return null;
			case 'b':
				if ( is_bool( $payload ) ) {
					return $payload;
				}
				break;
			case 'i':
				if ( is_int( $payload ) ) {
					return $payload;
				}
				break;
			case 'd':
				if ( is_float( $payload ) || is_int( $payload ) ) {
					return (float) $payload;
				}
				break;
			case 's':
				if ( is_string( $payload ) ) {
					return $payload;
				}
				break;
			case 'x':
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encode_value().
				$bytes = is_string( $payload ) ? base64_decode( $payload, true ) : false;
				if ( false !== $bytes ) {
					return $bytes;
				}
				break;
			case 'a':
			case 'o':
				if ( is_array( $payload ) ) {
					return self::decode_pairs( 'o' === $tag, $payload );
				}
				break;
		}//end switch
		throw new \UnexpectedValueException( 'Not a valid tagged value.' );
	}

	/**
	 * An array or stdClass from key/value pairs.
	 *
	 * @param bool         $as_object Whether to build a stdClass.
	 * @param array<mixed> $pairs  Pairs.
	 * @return array<mixed>|\stdClass
	 * @throws \UnexpectedValueException When a pair is not valid.
	 */
	private static function decode_pairs( bool $as_object, array $pairs ) {
		$value = $as_object ? new \stdClass() : array();
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || 2 !== count( $pair ) || ! ( is_int( $pair[0] ) || is_string( $pair[0] ) ) ) {
				throw new \UnexpectedValueException( 'Not a key/value pair.' );
			}
			if ( $as_object ) {
				$value->{$pair[0]} = self::decode_value( $pair[1] );
			} else {
				$value[ $pair[0] ] = self::decode_value( $pair[1] );
			}
		}
		return $value;
	}
}
