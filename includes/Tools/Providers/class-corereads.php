<?php
/**
 * Core read tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Providers;

defined( 'ABSPATH' ) || exit;

use FlitAgent\Tools\ContentQa;
use FlitAgent\Tools\ContentSearch;
use FlitAgent\Tools\HealthTriage;
use FlitAgent\Tools\PluginList;
use FlitAgent\Tools\Provider;
use FlitAgent\Tools\RecentContent;
use FlitAgent\Tools\SiteHealth;
use FlitAgent\Tools\SiteInfo;
use FlitAgent\Tools\System\CommentCounts;
use FlitAgent\Tools\System\ConnectionInfo;
use FlitAgent\Tools\System\CoreUpdates;
use FlitAgent\Tools\ThemeList;
use FlitAgent\Tools\UpdateReadiness;

/**
 * The twelve read tools of the read-only beta, in the order they appear in `tools/list`.
 */
final class CoreReads implements Provider {

	/**
	 * Read tools.
	 *
	 * @return \FlitAgent\Mcp\Tool[]
	 */
	public function reads(): array {
		return array(
			new SiteInfo(),
			new SiteHealth(),
			new HealthTriage(),
			new UpdateReadiness(),
			new ContentQa(),
			new PluginList(),
			new ThemeList(),
			new RecentContent(),
			new ContentSearch(),
			new CoreUpdates(),
			new CommentCounts(),
			new ConnectionInfo(),
		);
	}
}
