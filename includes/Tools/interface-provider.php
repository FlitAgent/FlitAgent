<?php
/**
 * Tool provider contract.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * One provider per package of read tools. A package adds its provider class to Registry::PROVIDERS and nothing else.
 */
interface Provider {

	/**
	 * Read tools, in the order they appear in `tools/list`.
	 *
	 * @return \FlitAgent\Mcp\Tool[]
	 */
	public function reads(): array;
}
