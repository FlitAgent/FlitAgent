<?php
/**
 * Read access to the WordPress Abilities API, shared by list_abilities and get_ability.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Feature-detects the Abilities API (core 6.9+) and describes abilities without executing them or running their
 * permission callbacks.
 */
final class Abilities {

	/**
	 * Returns every registered ability.
	 *
	 * @param callable|null $available Returns whether the API exists; null checks for wp_get_abilities().
	 * @return object[] WP_Ability instances.
	 * @throws ToolError When the Abilities API is not available.
	 */
	public static function all( ?callable $available ): array {
		$exists = null === $available ? function_exists( 'wp_get_abilities' ) : (bool) $available();
		if ( ! $exists ) {
			throw new ToolError( 'The WordPress Abilities API is not available on this site. It needs WordPress 6.9 or later.' );
		}

		$abilities = wp_get_abilities();
		return is_array( $abilities ) ? array_values( $abilities ) : array();
	}

	/**
	 * Summary of one ability.
	 *
	 * @param object $ability WP_Ability.
	 * @return array<string, mixed>
	 */
	public static function summary( $ability ): array {
		$meta = self::meta( $ability );

		return array(
			'name'         => (string) $ability->get_name(),
			'label'        => (string) $ability->get_label(),
			'description'  => (string) $ability->get_description(),
			'category'     => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
			'annotations'  => isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? Redactor::redact( $meta['annotations'] ) : null,
			'show_in_rest' => ! empty( $meta['show_in_rest'] ),
		);
	}

	/**
	 * The ability's meta as an array.
	 *
	 * @param object $ability WP_Ability.
	 * @return array<string, mixed>
	 */
	public static function meta( $ability ): array {
		$meta = $ability->get_meta();
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Output schema for one ability summary.
	 *
	 * @return array<string, mixed>
	 */
	public static function summary_schema(): array {
		$string = array( 'type' => 'string' );

		return array(
			'name'         => $string,
			'label'        => $string,
			'description'  => $string,
			'category'     => $string,
			'annotations'  => array(
				'type'        => array( 'object', 'null' ),
				'description' => 'Hints such as readonly, destructive and idempotent, as the ability declares them.',
			),
			'show_in_rest' => array( 'type' => 'boolean' ),
		);
	}
}
