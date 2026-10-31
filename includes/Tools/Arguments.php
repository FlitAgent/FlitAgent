<?php
/**
 * Tool argument validation.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Validates tool arguments and throws a ToolError the model can act on.
 */
final class Arguments {

	/**
	 * Rejects arguments not in the allowed list.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param string[]             $allowed   Allowed names.
	 * @param string               $tool      Tool name, for the message.
	 * @throws ToolError On an unknown argument.
	 */
	public static function only( array $arguments, array $allowed, string $tool ): void {
		foreach ( array_keys( $arguments ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				$names = array() === $allowed ? 'no arguments' : implode( ', ', $allowed );
				throw new ToolError( sprintf( 'Unknown argument "%s". %s accepts only %s.', esc_textarea( $key ), esc_textarea( $tool ), esc_textarea( $names ) ) );
			}
		}
	}

	/**
	 * An integer argument within a range.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param string               $name      Argument name.
	 * @param int                  $fallback  Value when absent.
	 * @param int                  $min       Smallest allowed.
	 * @param int                  $max       Largest allowed.
	 * @return int
	 * @throws ToolError When not an integer in range.
	 */
	public static function int( array $arguments, string $name, int $fallback, int $min, int $max ): int {
		if ( ! array_key_exists( $name, $arguments ) ) {
			return $fallback;
		}
		$value = $arguments[ $name ];
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			throw new ToolError( sprintf( '%s must be an integer from %d to %d.', esc_textarea( $name ), (int) $min, (int) $max ) );
		}
		return $value;
	}

	/**
	 * A non-empty list of strings.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param string               $name      Argument name.
	 * @return string[]|null Null when absent.
	 * @throws ToolError When not a non-empty list of strings.
	 */
	public static function strings( array $arguments, string $name ): ?array {
		if ( ! array_key_exists( $name, $arguments ) || null === $arguments[ $name ] ) {
			return null;
		}
		$value = $arguments[ $name ];
		$valid = is_array( $value ) && array() !== $value && array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $valid ) {
			foreach ( $value as $item ) {
				if ( ! is_string( $item ) ) {
					$valid = false;
				}
			}
		}
		if ( ! $valid ) {
			throw new ToolError( sprintf( '%s must be a list of strings.', esc_textarea( $name ) ) );
		}
		return $value;
	}
}
