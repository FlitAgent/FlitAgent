<?php
/**
 * WordPress core update write tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Core package: `update_core`. Available updates are read with the existing `get_core_updates` read tool, so this
 * package has no read tools.
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
		return array( new UpdateCore() );
	}
}
