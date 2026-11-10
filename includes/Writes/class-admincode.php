<?php
/**
 * Admin code for writes that run third-party hooks.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * Loads wp-admin/includes/admin.php, as wp-admin has it loaded when plugins.php, themes.php and update-core.php run
 * plugin and theme hooks. A REST request does not load it, so a hook that calls an admin function (WP Super Cache's
 * deactivation hook calls get_home_path()) would otherwise fail half way. Call it before activating, deactivating,
 * installing, updating, deleting or switching code, and before the rollbacks that do the same.
 */
final class AdminCode {

	/**
	 * Loads admin.php once, with require_once and no shortcut: functions from some of its files can already exist while
	 * others are missing. Every file it pulls in is loaded with require_once, as core loads them, so files a request
	 * already loaded are not loaded again.
	 */
	public static function load(): void {
		if ( is_readable( ABSPATH . 'wp-admin/includes/admin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/admin.php';
		}
	}
}
