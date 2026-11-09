<?php
/**
 * Theme package write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Themes;

defined( 'ABSPATH' ) || exit;

/**
 * Theme packages (#105): install, update and delete themes from the WordPress.org directory with core's upgrader.
 * Every write is destructive and approved by a human.
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
		$upgrader = new Upgrader();
		return array(
			new InstallTheme( $upgrader ),
			new UpdateTheme( $upgrader ),
			new DeleteTheme( $upgrader ),
		);
	}
}
