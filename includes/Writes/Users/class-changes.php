<?php
/**
 * Capability add/remove lists.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Users;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * The `add` and `remove` arguments of set_role_capabilities and set_user_capabilities, and comparing capability maps.
 */
final class Changes {

	/**
	 * Validated `add` and `remove` lists.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array{0: string[], 1: string[]}
	 * @throws ToolError When both are empty, a name is invalid, or a capability is in both.
	 */
	public static function lists( array $args ): array {
		$add    = Site::cap_names( isset( $args['add'] ) ? $args['add'] : array(), 'add' );
		$remove = Site::cap_names( isset( $args['remove'] ) ? $args['remove'] : array(), 'remove' );
		if ( array() === $add && array() === $remove ) {
			throw new ToolError( 'Give add and/or remove.' );
		}
		$both = array_values( array_intersect( $add, $remove ) );
		if ( array() !== $both ) {
			throw new ToolError( sprintf( '%s is in both add and remove.', esc_textarea( implode( ', ', $both ) ) ) );
		}
		return array( $add, $remove );
	}

	/**
	 * Whether two capability maps hold the same capabilities with the same grants, in any order.
	 *
	 * @param array<string, bool>|null $first  Capabilities.
	 * @param array<string, bool>|null $second Capabilities.
	 * @return bool
	 */
	public static function same( ?array $first, ?array $second ): bool {
		if ( null === $first || null === $second ) {
			return $first === $second;
		}
		ksort( $first, SORT_STRING );
		ksort( $second, SORT_STRING );
		return $first === $second;
	}
}
