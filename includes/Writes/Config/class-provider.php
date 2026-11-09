<?php
/**
 * Site configuration write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Site configuration writes (package E): options, theme mods, custom CSS, permalinks, menus, widgets, templates and
 * global styles.
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
			new UpdateOption(),
			new DeleteOption(),
			new SetThemeMod(),
			new UpdateCustomCss(),
			new SetPermalinkStructure(),
			new CreateMenu(),
			new DeleteMenu(),
			new AddMenuItem(),
			new UpdateMenuItem(),
			new DeleteMenuItem(),
			new AssignMenuLocation(),
			new UpdateWidget(),
			new MoveWidget(),
			new DeleteWidget(),
			new UpdateTemplate(),
			new UpdateGlobalStyles(),
		);
	}
}
