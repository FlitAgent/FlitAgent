<?php
/**
 * Shared option reading and checks for the option write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;

defined( 'ABSPATH' ) || exit;

/**
 * Reads an option without the registered-default fallback, fingerprints it, and runs the checks both option tools
 * share (protected name, capability, allowlist).
 */
final class Options {

	/**
	 * Stands in for "no such option" (a value no option can hold).
	 */
	const MISSING = "\0flitagent-missing\0";

	/**
	 * Shown for an option that does not exist.
	 */
	const NOT_SET = '(not set)';

	/**
	 * Current state: whether the option exists and its raw value. Passing a default stops core from substituting the
	 * registered setting's default.
	 *
	 * @param string $name Option name.
	 * @return array{exists: bool, value: mixed}
	 */
	public static function read( string $name ): array {
		$value = get_option( $name, self::MISSING );
		return self::MISSING === $value
			? array(
				'exists' => false,
				'value'  => null,
			)
			: array(
				'exists' => true,
				'value'  => $value,
			);
	}

	/**
	 * Fingerprint of an option state.
	 *
	 * @param array{exists: bool, value: mixed} $state State from read().
	 * @return string
	 */
	public static function fingerprint( array $state ): string {
		return Canonical::hash( array( $state['exists'], $state['value'] ) );
	}

	/**
	 * A state as one string for previews: scalars as text (true is "true"), anything else as JSON.
	 *
	 * @param array{exists: bool, value: mixed} $state State from read().
	 * @return string
	 */
	public static function display( array $state ): string {
		if ( ! $state['exists'] ) {
			return self::NOT_SET;
		}
		$value = $state['value'];
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		return is_scalar( $value ) || null === $value ? (string) $value : Canonical::json( $value );
	}

	/**
	 * Whether an option already holds a value (compared as stored text, since options come back from the database as
	 * strings).
	 *
	 * @param array{exists: bool, value: mixed} $state State from read().
	 * @param mixed                             $value Converted value.
	 * @return bool
	 */
	public static function holds( array $state, $value ): bool {
		if ( ! $state['exists'] || ! is_scalar( $state['value'] ) ) {
			return false;
		}
		$text = static function ( $scalar ): string {
			return is_bool( $scalar ) ? ( $scalar ? '1' : '' ) : (string) $scalar;
		};
		return $text( $state['value'] ) === $text( $value );
	}

	/**
	 * The shared refusals: a protected name, no `manage_options`, or an option off the allowlist. Returns the
	 * setting's schema when the option is writable.
	 *
	 * @param string $name Option name.
	 * @return array<string, mixed>|Assessment Schema, or a prohibited assessment.
	 */
	public static function writable( string $name ) {
		$refusal = WritableOptions::refusal( $name );
		if ( null !== $refusal ) {
			return Assessment::prohibited( 'protected_option', $refusal );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return Assessment::prohibited( 'forbidden', 'Changing options needs the manage_options capability.' );
		}
		$schema = WritableOptions::schema( $name );
		if ( null === $schema ) {
			return Assessment::prohibited( 'not_allowed', sprintf( 'The option "%s" is not on the list FlitAgent may change: only settings registered with show_in_rest that hold a single value, and a fixed list of core reading, discussion and media options.', $name ) );
		}
		return $schema;
	}

	/**
	 * Throws unless the option is still in the state the change was assessed against.
	 *
	 * @param string               $name     Option name.
	 * @param array<string, mixed> $expected Preconditions (`fingerprint`).
	 * @param string               $what     What would have been done, for the message.
	 * @return array{exists: bool, value: mixed} The current state.
	 * @throws ToolError When the option changed.
	 */
	public static function unchanged( string $name, array $expected, string $what ): array {
		$state = self::read( $name );
		if ( ! isset( $expected['fingerprint'] ) || self::fingerprint( $state ) !== $expected['fingerprint'] ) {
			throw new ToolError( sprintf( 'The option "%s" changed after the request was assessed; nothing was %s.', esc_textarea( $name ), esc_textarea( $what ) ) );
		}
		return $state;
	}

	/**
	 * A rollback snapshot of an option state: whether it existed and its value, packed so key order survives storage.
	 *
	 * @param array{exists: bool, value: mixed} $state State from read().
	 * @return array{exists: bool, packed: string}
	 */
	public static function snapshot( array $state ): array {
		return array(
			'exists' => $state['exists'],
			'packed' => Exact::pack( $state['value'] ),
		);
	}

	/**
	 * The value a snapshot holds.
	 *
	 * @param array<string, mixed> $before Snapshot from snapshot().
	 * @return mixed
	 */
	public static function snapshot_value( array $before ) {
		return isset( $before['packed'] ) ? Exact::unpack( $before['packed'] ) : null;
	}

	/**
	 * Puts a snapshot back: updates (or re-adds with its autoload flag) an option that existed, deletes one that did not.
	 *
	 * @param string                            $name     Option name.
	 * @param array<string, mixed>              $before   Snapshot from snapshot(), optional `autoload`.
	 * @param array{exists: bool, value: mixed} $current  Current state.
	 * @throws ToolError When WordPress refused.
	 */
	public static function restore( string $name, array $before, array $current ): void {
		$value = self::snapshot_value( $before );
		if ( empty( $before['exists'] ) ) {
			if ( $current['exists'] && ! delete_option( $name ) ) {
				throw new ToolError( sprintf( 'WordPress could not delete the option "%s".', esc_textarea( $name ) ) );
			}
			return;
		}
		if ( $current['exists'] ) {
			// update_option() also returns false when the value is already there.
			if ( ! update_option( $name, $value ) && Canonical::json( self::read( $name )['value'] ) !== Canonical::json( $value ) ) {
				throw new ToolError( sprintf( 'WordPress could not restore the option "%s".', esc_textarea( $name ) ) );
			}
			return;
		}
		$autoload = ! isset( $before['autoload'] ) || (bool) $before['autoload'];
		if ( ! add_option( $name, $value, '', $autoload ) ) {
			throw new ToolError( sprintf( 'WordPress could not re-create the option "%s".', esc_textarea( $name ) ) );
		}
	}
}
