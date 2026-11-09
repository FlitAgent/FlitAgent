<?php
/**
 * Core read tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Providers;

defined( 'ABSPATH' ) || exit;

use FlitAgent\Tools\AbilityInfo;
use FlitAgent\Tools\AbilityList;
use FlitAgent\Tools\RunReadonlyAbility;
use FlitAgent\Tools\ContentQa;
use FlitAgent\Tools\ContentSearch;
use FlitAgent\Tools\Files\FileOutline;
use FlitAgent\Tools\Files\ListFiles;
use FlitAgent\Tools\Files\ReadFile;
use FlitAgent\Tools\Files\SearchFiles;
use FlitAgent\Tools\HealthTriage;
use FlitAgent\Tools\Http\CoreChecksums;
use FlitAgent\Tools\Http\PageHtml;
use FlitAgent\Tools\Http\PluginChecksums;
use FlitAgent\Tools\Http\PluginDirectorySearch;
use FlitAgent\Tools\PluginList;
use FlitAgent\Tools\PostMeta;
use FlitAgent\Tools\Provider;
use FlitAgent\Tools\RecentContent;
use FlitAgent\Tools\RegisteredMeta;
use FlitAgent\Tools\Rest\RestGet;
use FlitAgent\Tools\Settings\ConfigGet;
use FlitAgent\Tools\Settings\OptionGet;
use FlitAgent\Tools\Settings\OptionList;
use FlitAgent\Tools\Settings\ThemeMods;
use FlitAgent\Tools\Settings\TransientGet;
use FlitAgent\Tools\Settings\TransientList;
use FlitAgent\Tools\SiteHealth;
use FlitAgent\Tools\SiteInfo;
use FlitAgent\Tools\System\CacheInfo;
use FlitAgent\Tools\System\CommentCounts;
use FlitAgent\Tools\System\ConnectionInfo;
use FlitAgent\Tools\System\CoreUpdates;
use FlitAgent\Tools\System\CronEvents;
use FlitAgent\Tools\System\CronStatus;
use FlitAgent\Tools\System\ImageSizes;
use FlitAgent\Tools\System\MaintenanceStatus;
use FlitAgent\Tools\System\RewriteRules;
use FlitAgent\Tools\System\Roles;
use FlitAgent\Tools\ThemeList;
use FlitAgent\Tools\UpdateReadiness;
use FlitAgent\Tools\UserMeta;


/**
 * The read tools of the read-parity cards (#30, #39, #42, #44, #45, #46, #47) and the playbooks health triage (#118), update readiness (#123) and content QA (#121), in their original order.
 */
final class CoreReads implements Provider {

	/**
	 * Read tools.
	 *
	 * @return \FlitAgent\Mcp\Tool[]
	 */
	public function reads(): array {
		return array_values(
			array_filter(
				array(
					new SiteInfo(),
					new SiteHealth(),
					new HealthTriage(),
					new UpdateReadiness(),
					new ContentQa(),
					new PluginList(),
					new ThemeList(),
					new RecentContent(),
					new RestGet(),
					new OptionGet(),
					new OptionList(),
					new TransientGet(),
					new TransientList(),
					new ThemeMods(),
					new ConfigGet(),
					new CronEvents(),
					new CronStatus(),
					new RewriteRules(),
					new CacheInfo(),
					new MaintenanceStatus(),
					new CoreUpdates(),
					new Roles(),
					new ImageSizes(),
					new CommentCounts(),
					new ConnectionInfo(),
					new PostMeta(),
					new UserMeta(),
					new RegisteredMeta(),
					new ContentSearch(),
					new AbilityList(),
					new AbilityInfo(),
					// Registered only where the Abilities API exists (WordPress 6.9+), like the builder tools.
					function_exists( 'wp_get_abilities' ) ? new RunReadonlyAbility() : null,
					new PageHtml(),
					new CoreChecksums(),
					new PluginChecksums(),
					new PluginDirectorySearch(),
					new ReadFile(),
					new ListFiles(),
					new SearchFiles(),
					new FileOutline(),
				)
			)
		);
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
