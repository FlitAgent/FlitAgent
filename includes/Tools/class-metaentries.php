<?php
/**
 * Shared argument checks and redaction for the meta tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Validates meta tool arguments and turns raw meta into redacted `{name, values}` entries.
 */
final class MetaEntries {

	/**
	 * Largest output returned, in bytes.
	 */
	const MAX_BYTES = 524288;

	/**
	 * Meta keys masked even though their names do not look secret: they hold email addresses or a user's IP address.
	 */
	const PERSONAL_KEY_PATTERN = '/e-?mail|^community-events-location$/i';

	/**
	 * Validates `<id_name>` and the optional `key` argument.
	 *
	 * @param string               $tool      Tool name, for messages.
	 * @param string               $id_name   Name of the ID argument, e.g. `post_id`.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array{0: int, 1: string} The ID and the key ('' for all keys).
	 * @throws ToolError When an argument is unknown or invalid.
	 */
	public static function arguments( string $tool, string $id_name, array $arguments ): array {
		foreach ( array_keys( $arguments ) as $name ) {
			if ( $id_name !== $name && 'key' !== $name ) {
				throw new ToolError( sprintf( 'Unknown argument. %s accepts only %s and key.', esc_textarea( $tool ), esc_textarea( $id_name ) ) );
			}
		}

		$id = $arguments[ $id_name ] ?? null;
		if ( ! is_int( $id ) || $id < 1 ) {
			throw new ToolError( sprintf( '%s must be a positive integer.', esc_textarea( $id_name ) ) );
		}

		if ( ! array_key_exists( 'key', $arguments ) ) {
			return array( $id, '' );
		}
		$key = $arguments['key'];
		if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 255 ) {
			throw new ToolError( 'key must be a meta key of 1 to 255 characters.' );
		}
		return array( $id, $key );
	}

	/**
	 * Builds the entries.
	 *
	 * @param array<string|int, mixed> $meta All meta (key => list of values), or one key's values when `$key` is set.
	 * @param string                   $key  The requested key, or '' for all.
	 * @return array<int, array{name: string, values: array<int, mixed>}>
	 * @throws ToolError When the output is too large.
	 */
	public static function entries( array $meta, string $key ): array {
		if ( '' !== $key ) {
			$meta = array() === $meta ? array() : array( $key => $meta );
		} else {
			// For all keys, get_metadata() returns the cache's still-serialized values; one key's values come
			// unserialized. Unserialize here (once, like WordPress) so nested secrets can be redacted.
			$meta = array_map(
				function ( $values ) {
					$unserialized = array();
					foreach ( (array) $values as $value ) {
						$unserialized[] = maybe_unserialize( $value );
					}
					return $unserialized;
				},
				$meta
			);
		}

		$entries = array();
		foreach ( $meta as $name => $values ) {
			$name      = (string) $name;
			$values    = array_values( (array) $values );
			$entries[] = array(
				'name'   => $name,
				'values' => self::is_masked_key( $name ) ? self::mask( $values ) : Redactor::redact( $values ),
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- size check only.
		$bytes = strlen( (string) json_encode( $entries ) );
		if ( $bytes > self::MAX_BYTES ) {
			throw new ToolError( sprintf( 'The meta is %d KB, over the 512 KB limit. Pass key to read one meta key.', (int) ceil( $bytes / 1024 ) ) );
		}

		return $entries;
	}

	/**
	 * Whether every value under this meta key is masked.
	 *
	 * @param string $name Meta key.
	 * @return bool
	 */
	public static function is_masked_key( string $name ): bool {
		return Redactor::is_secret_key( $name ) || 1 === preg_match( self::PERSONAL_KEY_PATTERN, $name );
	}

	/**
	 * Masks every value that could reveal something; keeps empty strings, booleans, numbers and null.
	 *
	 * @param array<int, mixed> $values Values.
	 * @return array<int, mixed>
	 */
	private static function mask( array $values ): array {
		return array_map(
			function ( $value ) {
				return Redactor::redact( array( 'secret' => $value ) )['secret'];
			},
			$values
		);
	}
}
