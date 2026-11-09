<?php
/**
 * Ability write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Runs WordPress abilities that are not declared read-only (`run_ability`). The read-only counterpart,
 * `run_readonly_ability`, is a read tool in `Tools\Providers\CoreReads`.
 */
final class Provider implements \FlitAgent\Tools\Provider {

	/**
	 * No read tools.
	 *
	 * @return \FlitAgent\Mcp\Tool[]
	 */
	public function reads(): array {
		return array();
	}

	/**
	 * Write tools.
	 *
	 * @return \FlitAgent\Writes\WriteTool[]
	 */
	public function writes(): array {
		// Registered only where the Abilities API exists (WordPress 6.9+), like the builder tools.
		return function_exists( 'wp_get_abilities' ) ? array( new RunAbility() ) : array();
	}
}
