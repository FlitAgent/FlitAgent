<?php
/**
 * Plugin entry point.
 *
 * @package FlitAgent
 */

namespace FlitAgent;

defined( 'ABSPATH' ) || exit;

use FlitAgent\Admin\Admin;
use FlitAgent\Admin\DiagnosticsRoute;
use FlitAgent\Admin\ToolStatusRoute;
use FlitAgent\Auth\Context;
use FlitAgent\Auth\Gateway\Apps;
use FlitAgent\Auth\Gateway\WpStore;
use FlitAgent\Mcp\RestController;
use FlitAgent\Mcp\Server;
use FlitAgent\Tools\Registry;

/**
 * Boots FlitAgent once WordPress has loaded all plugins.
 */
final class Plugin {

	/**
	 * The site features advertised in `_meta["com.flitagent/site"].features`.
	 */
	const FEATURES = array( 'app_revoked', 'tool_state', 'site_disconnected' );

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Boots the plugin. Safe to call more than once.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
		Admin::register();
		Auth\Gateway\Gateway::register();
		add_action( 'admin_init', array( Auth\Gateway\Upgrade::class, 'maybe_run' ) );

		/**
		 * Fires after FlitAgent has booted.
		 */
		do_action( 'flitagent_loaded' );
	}

	/**
	 * Registers the MCP endpoint. Runs on `rest_api_init`.
	 *
	 * Every tool is a read tool from the Registry; this version has no write tools, so any other name is "Unknown tool".
	 */
	public static function register_rest_routes(): void {
		$server = new Server( 'flitagent', 'FlitAgent', FLITAGENT_VERSION, Registry::reads(), array( self::class, 'can_access_protected_tools' ), array( self::class, 'initialize_meta' ) );
		( new RestController( $server ) )->register();
		( new DiagnosticsRoute() )->register();
		( new ToolStatusRoute() )->register();
	}

	/**
	 * Access gate for protected tools: the current user must have `manage_options`.
	 *
	 * The gateway authenticator sets the user via determine_current_user.
	 */
	public static function can_access_protected_tools(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Site details for the `initialize` result, only for a request flitagent.com signed. An unauthenticated
	 * `initialize` (possible until the route is closed to everything else) names nothing.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function initialize_meta(): ?array {
		return Context::GATEWAY === Context::current()->method() ? self::site_meta() : null;
	}

	/**
	 * The site details flitagent.com stores: `_meta["com.flitagent/site"]` of the `initialize` result, with the keys of
	 * 1.0.0. This version only reads, so `access` is always `read` and `writes_enabled` always false; `scope` stays the
	 * connection's paired scope (`read` without one), which flitagent.com shows. `features` names what this plugin
	 * understands beyond the protocol: `app_revoked` (Mcp\Server::APP_REVOKED), `tool_state` (`disconnected_tools`,
	 * and the 401 `flitagent_not_connected`) and `site_disconnected` (Mcp\Server::SITE_DISCONNECTED). `disconnected_tools` lists the AI tools an administrator disconnected on
	 * this site (Apps::blocked(), in Apps::TOOLS order; [] when none).
	 *
	 * @return array{name: string, url: string, plugin_version: string, wp_version: string, php_version: string, writes_enabled: bool, access: string, scope: string, features: string[], disconnected_tools: string[]}
	 */
	public static function site_meta(): array {
		global $wp_version;
		$store      = new WpStore();
		$connection = $store->connection();
		$scope      = null === $connection ? Context::SCOPE_READ : $connection->scope();

		return array(
			'name'               => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'                => home_url(),
			'plugin_version'     => FLITAGENT_VERSION,
			'wp_version'         => (string) $wp_version,
			'php_version'        => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
			'writes_enabled'     => false,
			'access'             => Context::SCOPE_READ,
			'scope'              => $scope,
			'features'           => self::FEATURES,
			'disconnected_tools' => ( new Apps( $store ) )->blocked(),
		);
	}

	/**
	 * Resets the booted flag. For unit tests only.
	 */
	public static function reset_for_tests(): void {
		self::$booted = false;
	}
}
