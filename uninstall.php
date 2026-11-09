<?php
/**
 * Runs when the plugin is deleted in wp-admin (WordPress's uninstall_plugin()). WordPress prefers this file to the
 * uninstall hook earlier 1.0.0 builds registered on activation, removes that hook's entry and then never calls it. Later
 * versions must keep this file: an update never runs the activation hook.
 *
 * @package FlitAgent
 */

namespace FlitAgent;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-autoloader.php';
Autoloader::register();

Uninstall::run();
