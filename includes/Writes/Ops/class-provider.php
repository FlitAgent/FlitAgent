<?php
/**
 * Operations and lifecycle write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

defined( 'ABSPATH' ) || exit;

/**
 * Operations writes (package F): caches, rewrite rules, transients, cron, plugin activation, theme switch and
 * auto-update settings. Installing, updating and deleting plugins, themes and core live in Writes\Packages; a hard
 * rewrite flush (it writes .htaccess) is not offered.
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
			new FlushObjectCache(),
			new PurgePageCache(),
			new RegenerateBuilderCss(),
			new FlushRewriteRules(),
			new DeleteExpiredTransients(),
			new DeleteTransient(),
			new DeleteAllTransients(),
			new UnscheduleCronEvent(),
			new RunCronEvent(),
			new PluginActivation( true ),
			new PluginActivation( false ),
			new SwitchTheme(),
		);
	}
}
