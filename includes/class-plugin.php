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
use FlitAgent\Tools\SiteText;
use FlitAgent\Writes\Gateway as WritesGateway;
use FlitAgent\Writes\Mcp\WriteTools;
use FlitAgent\Writes\Wp\Factory;
use FlitAgent\Writes\Wp\Tables;
use FlitAgent\Writes\Wp\WpPolicy;

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
	 * Read tools come from the Registry. Write tools and the write meta-tools are added only while writes are on
	 * (`flitagent_writes_enabled`, default on); while off they do not exist: tools/list leaves them out, and calling one
	 * gets the Gateway's "Access for AI tools is Read only" message (any other unknown name stays "Unknown tool").
	 */
	public static function register_rest_routes(): void {
		$tools       = Registry::reads();
		$unavailable = null;
		if ( ( new WpPolicy() )->writes_enabled() ) {
			Tables::maybe_upgrade();
			$tools = array_merge( $tools, WriteTools::for_gateway( Factory::gateway() ) );
		} else {
			$unavailable = array( self::class, 'writes_off_message' );
		}
		$server = new Server( 'flitagent', 'FlitAgent', FLITAGENT_VERSION, $tools, array( self::class, 'can_access_protected_tools' ), array( self::class, 'credential_allows_writes' ), array( self::class, 'initialize_meta' ), $unavailable );
		( new RestController( $server ) )->register();
		( new DiagnosticsRoute() )->register();
		( new ToolStatusRoute() )->register();
	}

	/**
	 * While writes are off: the Gateway's Read only message for a write-layer tool name (each write tool, rollback_change,
	 * apply_change, submit_plan, get_change and list_receipts), null for any other name. The write tools are built only
	 * when such a call comes in, and only their names are read.
	 *
	 * @param string $name Tool name.
	 * @return string|null
	 */
	public static function writes_off_message( string $name ): ?string {
		foreach ( WriteTools::for_gateway( Factory::gateway() ) as $tool ) {
			if ( $tool->name() === $name ) {
				return WritesGateway::WRITES_OFF;
			}
		}
		return null;
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
	 * Write gate: whether the request's credential has write scope (`Auth\Context`). Only a request flitagent.com signed
	 * can; without one the scope is read, and a read-scoped app does not see tools that are not read-only.
	 */
	public static function credential_allows_writes(): bool {
		return Context::current()->allows_writes();
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
	 * The site details flitagent.com stores: `_meta["com.flitagent/site"]` of the `initialize` result. `access` is what
	 * every AI tool may do on the site (Context::access(): `write` for Read and propose changes on a connection paired
	 * for write and not on multisite, where every write is refused; else `read`), sent on every initialize so
	 * flitagent.com shows the current value; `writes_enabled` (the setting alone) stays for flitagent.com versions that
	 * read it. `features` names what this plugin understands beyond
	 * the protocol: `app_revoked` (Mcp\Server::APP_REVOKED), `tool_state` (`disconnected_tools`, and the 401
	 * `flitagent_not_connected`) and `site_disconnected` (Mcp\Server::SITE_DISCONNECTED). `disconnected_tools` lists the AI tools an administrator disconnected on this site
	 * (Apps::blocked(), in Apps::TOOLS order; [] when none).
	 *
	 * @return array{name: string, url: string, url_display?: string, identifier_unsafe?: bool, plugin_version: string, wp_version: string, php_version: string, writes_enabled: bool, access: string, scope: string, features: string[], disconnected_tools: string[]}
	 */
	public static function site_meta(): array {
		global $wp_version;
		$store      = new WpStore();
		$connection = $store->connection();
		$policy     = new WpPolicy();
		$writes     = $policy->writes_enabled();
		$scope      = null === $connection ? Context::SCOPE_READ : $connection->scope();

		return array( 'name' => SiteText::plain( (string) get_bloginfo( 'name' ) ) ) + SiteText::exact_field( 'url', home_url() ) + array(
			'plugin_version'     => FLITAGENT_VERSION,
			'wp_version'         => (string) $wp_version,
			'php_version'        => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
			'writes_enabled'     => $writes,
			'access'             => Context::access( $writes && ! $policy->is_multisite(), $scope ),
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
