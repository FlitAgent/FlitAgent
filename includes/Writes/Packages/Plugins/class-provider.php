<?php
/**
 * Plugin package write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Plugins;

defined( 'ABSPATH' ) || exit;

/**
 * Installing, updating and deleting plugins from the WordPress.org directory (#104).
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
		return array(
			new InstallPlugin(),
			new UpdatePlugin(),
			new DeletePlugin(),
		);
	}
}
