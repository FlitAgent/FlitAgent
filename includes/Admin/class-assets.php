<?php
/**
 * Scripts and styles.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Locates the admin's plain scripts and styles in assets/, which ship as they are (no build step): assets/css/tokens.css
 * and admin.css, assets/js/ with one classic script per class plus admin.js, which attaches them.
 */
final class Assets {

	/**
	 * Folder of the scripts and styles, relative to the plugin.
	 */
	const DIR = 'assets';

	/**
	 * Style handle of the design tokens (css/tokens.css), which admin.css depends on.
	 */
	const TOKENS = 'flitagent-tokens';

	/**
	 * Script handle => file under assets/, in load order. Each puts its class on window.flitagentAdmin; admin.js
	 * (the Admin::STYLE handle) depends on all of them and attaches them.
	 */
	const SCRIPTS = array(
		'flitagent-dialog'      => 'js/dialog.js',
		'flitagent-copy-button' => 'js/copy-button.js',
		'flitagent-connect-tab' => 'js/connect-tab.js',
		'flitagent-run-check'   => 'js/run-check.js',
		'flitagent-tool-status' => 'js/tool-status.js',
	);

	/**
	 * URL of a file in assets/.
	 *
	 * @param string $file Path under assets/, such as css/admin.css.
	 * @return string
	 */
	public static function url( string $file ): string {
		return plugins_url( self::DIR . '/' . $file, FLITAGENT_FILE );
	}
}
