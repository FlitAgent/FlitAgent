<?php
/**
 * Validates arguments against a closed input schema.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * A small JSON Schema subset for flat, closed schemas: `properties`, `required`, `additionalProperties: false`, and per
 * property `type` (string, integer, boolean, array, object), `minLength` / `maxLength` (characters), `pattern`, `enum`,
 * `minimum` / `maximum` and `items.type`. Null is never a valid value.
 */
final class Schema {

	/**
	 * Validates arguments.
	 *
	 * @param array<string, mixed> $schema Input schema.
	 * @param array<string, mixed> $args   Arguments.
	 * @throws ToolError On the first invalid argument.
	 */
	public static function validate( array $schema, array $args ): void {
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();

		foreach ( array_keys( $args ) as $name ) {
			if ( ! isset( $properties[ $name ] ) ) {
				throw new ToolError( sprintf( 'Unknown argument "%s". Accepted arguments: %s.', esc_textarea( $name ), array() === $properties ? 'none' : esc_textarea( implode( ', ', array_keys( $properties ) ) ) ) );
			}
		}
		foreach ( isset( $schema['required'] ) ? $schema['required'] : array() as $name ) {
			if ( ! array_key_exists( $name, $args ) ) {
				throw new ToolError( sprintf( '%s is required.', esc_textarea( $name ) ) );
			}
		}
		foreach ( $args as $name => $value ) {
			self::check( (string) $name, $value, $properties[ $name ] );
		}
	}

	/**
	 * Checks one value.
	 *
	 * @param string               $name  Argument name.
	 * @param mixed                $value Value.
	 * @param array<string, mixed> $rules Property schema.
	 * @throws ToolError When invalid.
	 */
	private static function check( string $name, $value, array $rules ): void {
		$type = isset( $rules['type'] ) ? $rules['type'] : 'string';

		switch ( $type ) {
			case 'integer':
				if ( ! is_int( $value ) ) {
					throw new ToolError( sprintf( '%s must be an integer.', esc_textarea( $name ) ) );
				}
				if ( isset( $rules['minimum'] ) && $value < $rules['minimum'] ) {
					throw new ToolError( sprintf( '%s must be at least %d.', esc_textarea( $name ), (int) $rules['minimum'] ) );
				}
				if ( isset( $rules['maximum'] ) && $value > $rules['maximum'] ) {
					throw new ToolError( sprintf( '%s must be at most %d.', esc_textarea( $name ), (int) $rules['maximum'] ) );
				}
				break;
			case 'boolean':
				if ( ! is_bool( $value ) ) {
					throw new ToolError( sprintf( '%s must be true or false.', esc_textarea( $name ) ) );
				}
				break;
			case 'array':
				if ( ! is_array( $value ) || ( array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) {
					throw new ToolError( sprintf( '%s must be a list.', esc_textarea( $name ) ) );
				}
				$item_type = isset( $rules['items']['type'] ) ? $rules['items']['type'] : null;
				foreach ( $value as $item ) {
					if ( ( 'string' === $item_type && ! is_string( $item ) ) || ( 'integer' === $item_type && ! is_int( $item ) ) ) {
						throw new ToolError( sprintf( '%s items must be %ss.', esc_textarea( $name ), esc_textarea( $item_type ) ) );
					}
				}
				break;
			case 'object':
				if ( ! is_array( $value ) || ( array() !== $value && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) ) {
					throw new ToolError( sprintf( '%s must be an object.', esc_textarea( $name ) ) );
				}
				break;
			default:
				self::check_string( $name, $value, $rules );
		}//end switch

		if ( isset( $rules['enum'] ) && ! in_array( $value, $rules['enum'], true ) ) {
			throw new ToolError( sprintf( '%s must be one of: %s.', esc_textarea( $name ), esc_textarea( implode( ', ', $rules['enum'] ) ) ) );
		}
	}

	/**
	 * Checks a string value.
	 *
	 * @param string               $name  Argument name.
	 * @param mixed                $value Value.
	 * @param array<string, mixed> $rules Property schema.
	 * @throws ToolError When invalid.
	 */
	private static function check_string( string $name, $value, array $rules ): void {
		if ( ! is_string( $value ) ) {
			throw new ToolError( sprintf( '%s must be a string.', esc_textarea( $name ) ) );
		}
		$length = self::length( $value );
		if ( isset( $rules['minLength'] ) && $length < $rules['minLength'] ) {
			throw new ToolError( sprintf( '%s must be at least %d characters.', esc_textarea( $name ), (int) $rules['minLength'] ) );
		}
		if ( isset( $rules['maxLength'] ) && $length > $rules['maxLength'] ) {
			throw new ToolError( sprintf( '%s must be at most %d characters.', esc_textarea( $name ), (int) $rules['maxLength'] ) );
		}
		if ( isset( $rules['pattern'] ) && 1 !== preg_match( '/' . str_replace( '/', '\/', $rules['pattern'] ) . '/uD', $value ) ) {
			throw new ToolError( sprintf( '%s must match %s.', esc_textarea( $name ), esc_textarea( $rules['pattern'] ) ) );
		}
	}

	/**
	 * Length in characters (UTF-8), or bytes when the string is not valid UTF-8.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private static function length( string $value ): int {
		$count = preg_match_all( '/./su', $value );
		return false === $count ? strlen( $value ) : $count;
	}
}
