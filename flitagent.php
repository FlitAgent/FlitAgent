<?php
/**
 * Plugin Name:       FlitAgent
 * Plugin URI:        https://flitagent.com
 * Description:       Connect your WordPress site to AI assistants such as Claude and ChatGPT.
 * Version:           0.9.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            FlitAgent
 * Author URI:        https://iamfaisal.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flitagent
 *
 * @package FlitAgent
 */

namespace FlitAgent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FLITAGENT_VERSION', '0.9.1' );
define( 'FLITAGENT_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-autoloader.php';
Autoloader::register();

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
register_activation_hook( __FILE__, array( Admin\Onboarding::class, 'activate' ) );
add_action( 'activated_plugin', array( Admin\Onboarding::class, 'plugin_activated' ) );
register_deactivation_hook( __FILE__, array( Auth\Gateway\Keys::class, 'unschedule' ) );
