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
				$rule = array() === $allowed ? 'takes no arguments' : 'accepts only ' . implode( ', ', $allowed );
				throw new ToolError( sprintf( 'Unknown argument "%s". %s %s.', esc_textarea( $key ), esc_textarea( $tool ), esc_textarea( $rule ) ) );
			}
		}
	}

	/**
	 * A non-empty string argument.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param string               $name      Argument name.
	 * @param bool                 $required  Whether it must be present.
	 * @return string|null
	 * @throws ToolError When missing (and required) or not a non-empty string.
	 */
	public static function string( array $arguments, string $name, bool $required ): ?string {
		if ( ! array_key_exists( $name, $arguments ) || null === $arguments[ $name ] ) {
			if ( $required ) {
				throw new ToolError( sprintf( '%s is required.', esc_textarea( $name ) ) );
			}
			return null;
		}
		if ( ! is_string( $arguments[ $name ] ) || '' === $arguments[ $name ] ) {
			throw new ToolError( sprintf( '%s must be a non-empty string.', esc_textarea( $name ) ) );
		}
		return $arguments[ $name ];
	}

	/**
	 * A boolean argument.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param string               $name      Argument name.
	 * @param bool                 $fallback  Value when absent.
	 * @return bool
	 * @throws ToolError When not a boolean.
	 */
	public static function bool( array $arguments, string $name, bool $fallback ): bool {
		if ( ! array_key_exists( $name, $arguments ) || null === $arguments[ $name ] ) {
			return $fallback;
		}
		if ( ! is_bool( $arguments[ $name ] ) ) {
			throw new ToolError( sprintf( '%s must be true or false.', esc_textarea( $name ) ) );
		}
		return $arguments[ $name ];
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
	 * One of a fixed set of strings.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param string               $name      Argument name.
	 * @param string[]             $choices   Allowed values.
	 * @return string|null Null when absent.
	 * @throws ToolError When not one of the choices.
	 */
	public static function enum( array $arguments, string $name, array $choices ): ?string {
		if ( ! array_key_exists( $name, $arguments ) || null === $arguments[ $name ] ) {
			return null;
		}
		if ( ! in_array( $arguments[ $name ], $choices, true ) ) {
			throw new ToolError( sprintf( '%s must be one of: %s.', esc_textarea( $name ), esc_textarea( implode( ', ', $choices ) ) ) );
		}
		return $arguments[ $name ];
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
