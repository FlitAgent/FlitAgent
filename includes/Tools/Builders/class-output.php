<?php
/**
 * Final shaping of page-builder tool output.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Turns builder data into plain arrays, redacts secrets and enforces the size limit.
 */
final class Output {

	/**
	 * Largest result returned, in bytes of JSON.
	 */
	const MAX_BYTES = 524288;

	/**
	 * Prepares a result for the client.
	 *
	 * @param array<string, mixed> $result Result.
	 * @return array<string, mixed>
	 * @throws ToolError When the result is over the size limit.
	 */
	public static function finish( array $result ): array {
		$result = Redactor::redact( self::plain( $result ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- size check only.
		$bytes = strlen( (string) json_encode( $result ) );
		if ( $bytes > self::MAX_BYTES ) {
			throw new ToolError( sprintf( 'The result is %d KB, over the 512 KB limit. Narrow it (names or prefix for schemas) or inspect this layout in the builder.', (int) ceil( $bytes / 1024 ) ) );
		}

		return $result;
	}

	/**
	 * Converts objects (e.g. Beaver Builder's stdClass nodes) to arrays, at any depth.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function plain( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::plain( $item );
		}
		return $value;
	}

	/**
	 * Decodes a JSON string to an array; anything else (including PHP-serialized text) is not decoded.
	 *
	 * @param mixed $raw Stored value.
	 * @return array<mixed>|null Null when the value is not a JSON object or array.
	 */
	public static function json( $raw ): ?array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
