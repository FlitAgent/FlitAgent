<?php
/**
 * Page-builder read tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Providers;

use FlitAgent\Tools\Builders\Catalog;
use FlitAgent\Tools\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Page-builder reads, only for builders active on this site (#48).
 */
final class BuilderReads implements Provider {

	/**
	 * Read tools of the active builders.
	 *
	 * @return \FlitAgent\Mcp\Tool[]
	 */
	public function reads(): array {
		return Catalog::tools();
	}

	/**
	 * No write tools.
	 *
	 * @return \FlitAgent\Writes\WriteTool[]
	 */
	public function writes(): array {
		return array();
	}
}
