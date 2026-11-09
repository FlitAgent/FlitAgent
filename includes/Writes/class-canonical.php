<?php
/**
 * Canonical JSON for hashing and storage.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes data the same way every time: object keys sorted at every depth, lists kept in order, types kept (1 and "1"
 * differ). Used for argument hashes, approval bindings and stored JSON.
 */
final class Canonical {

	/**
	 * Canonical JSON. Invalid UTF-8 is replaced rather than failing.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function json( $data ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Gateway is WordPress-free.
		return (string) json_encode( self::sorted( $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION );
	}

	/**
	 * JSON that keeps key order (stored responses replayed exactly as first returned).
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function encode( $data ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Gateway is WordPress-free.
		return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION );
	}

	/**
	 * SHA-256 of the canonical JSON.
	 *
	 * @param mixed $data Data.
	 * @return string 64 hex characters.
	 */
	public static function hash( $data ): string {
		return hash( 'sha256', self::json( $data ) );
	}

	/**
	 * Decodes stored JSON to an array; anything else becomes an empty array.
	 *
	 * @param mixed $json JSON string.
	 * @return array<mixed>
	 */
	public static function decode( $json ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Sorts object keys recursively.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	private static function sorted( $data ) {
		if ( is_object( $data ) ) {
			$data = get_object_vars( $data );
		}
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$is_list = array() === $data || array_keys( $data ) === range( 0, count( $data ) - 1 );
		foreach ( $data as $key => $value ) {
			$data[ $key ] = self::sorted( $value );
		}
		if ( ! $is_list ) {
			ksort( $data, SORT_STRING );
		}
		return $data;
	}
}
