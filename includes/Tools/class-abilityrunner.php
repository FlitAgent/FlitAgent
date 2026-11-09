<?php
/**
 * Running WordPress abilities, shared by run_readonly_ability and run_ability.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Ops\ErrorText;

defined( 'ABSPATH' ) || exit;

/**
 * Finds abilities, reads their annotations and shapes their output (redacted, capped). Running goes through
 * `WP_Ability::execute()`, which validates input and runs the ability's permission check first.
 */
final class AbilityRunner {

	/**
	 * Largest output returned whole, in bytes of JSON.
	 */
	const MAX_BYTES = 524288;

	/**
	 * Characters of JSON returned when the output is larger.
	 */
	const EXCERPT = 2000;

	/**
	 * The registered ability with this name.
	 *
	 * @param string        $name      Ability name.
	 * @param callable|null $available Availability check; null detects wp_get_abilities().
	 * @return object|null WP_Ability, or null when no ability has that name.
	 * @throws ToolError When the Abilities API is not available.
	 */
	public static function find( string $name, ?callable $available ) {
		// Looked up in the full list: wp_get_ability() reports an unknown name as a developer notice.
		foreach ( Abilities::all( $available ) as $ability ) {
			if ( $name === $ability->get_name() ) {
				return $ability;
			}
		}
		return null;
	}

	/**
	 * The ability's annotations as it declares them.
	 *
	 * @param object $ability WP_Ability.
	 * @return array<string, mixed>
	 */
	public static function annotations( $ability ): array {
		$meta = Abilities::meta( $ability );
		return isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
	}

	/**
	 * Whether the ability declares itself read-only. Declared by server-side code, never by the caller.
	 *
	 * @param object $ability WP_Ability.
	 * @return bool
	 */
	public static function is_readonly( $ability ): bool {
		$annotations = self::annotations( $ability );
		return isset( $annotations['readonly'] ) && true === $annotations['readonly'];
	}

	/**
	 * The `input` argument: an object, or null when it was left out.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>|null
	 * @throws ToolError When input is not an object.
	 */
	public static function input( array $arguments ): ?array {
		if ( ! array_key_exists( 'input', $arguments ) ) {
			return null;
		}
		$input = $arguments['input'];
		if ( ! is_array( $input ) || ( array() !== $input && array_keys( $input ) === range( 0, count( $input ) - 1 ) ) ) {
			throw new ToolError( 'input must be an object with the ability\'s input fields (get_ability shows its input schema); leave it out for abilities that take none.' );
		}
		return $input;
	}

	/**
	 * Output for the caller: redacted, and replaced by an excerpt when its JSON is over 512 KB.
	 *
	 * @param mixed $output Ability output.
	 * @return array{output: mixed, truncated: bool, excerpt?: string, bytes?: int}
	 * @throws ToolError When the output cannot be encoded as JSON.
	 */
	public static function result( $output ): array {
		$output = Redactor::redact( $output );
		$json   = wp_json_encode( $output );
		if ( false === $json ) {
			throw new ToolError( 'The ability returned output that cannot be encoded as JSON.' );
		}
		if ( strlen( $json ) <= self::MAX_BYTES ) {
			return array(
				'output'    => $output,
				'truncated' => false,
			);
		}
		return array(
			'output'    => null,
			'truncated' => true,
			'excerpt'   => substr( $json, 0, self::EXCERPT ),
			'bytes'     => strlen( $json ),
		);
	}

	/**
	 * A WP_Error's message, without file paths or markup.
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	public static function message( $error ): string {
		return ErrorText::clean( (string) $error->get_error_message() );
	}
}
