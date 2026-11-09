<?php
/**
 * REST route behind the live AI tool status in wp-admin.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

use FlitAgent\Auth\Gateway\Apps;
use FlitAgent\Auth\Gateway\Store;
use FlitAgent\Auth\Gateway\WpStore;
use FlitAgent\Mcp\RestController;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/flitagent/v1/admin/tools: whether the site is connected to flitagent.com and, for each AI tool, whether
 * it is connected (Apps::tools(), which leaves out the tools disconnected on this site). The Connect screen and setup
 * step 3 poll it (src/admin/tool-status.ts) so a tool allowed or revoked on flitagent.com shows without a reload.
 * For administrators only (Admin::CAPABILITY), from their wp-admin session: WordPress's cookie authentication with the
 * `wp_rest` nonce. It reads the stored state only and sends nothing to flitagent.com.
 */
final class ToolStatusRoute {

	const ROUTE = '/admin/tools';

	const CONNECTED     = 'connected';
	const NOT_CONNECTED = 'not_connected';

	/**
	 * Returns the gateway store.
	 *
	 * @var callable
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param callable|null $store Returns the Store; WpStore by default.
	 */
	public function __construct( ?callable $store = null ) {
		$this->store = $store ?? static function (): Store {
			return new WpStore();
		};
	}

	/**
	 * The route's URL, for the page's script.
	 *
	 * @return string
	 */
	public static function url(): string {
		return rest_url( RestController::REST_NAMESPACE . self::ROUTE );
	}

	/**
	 * Registers the route. Runs on `rest_api_init`.
	 */
	public function register(): void {
		register_rest_route(
			RestController::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( self::class, 'permission' ),
			)
		);
	}

	/**
	 * Only an administrator who may use FlitAgent.
	 *
	 * @return bool
	 */
	public static function permission(): bool {
		return current_user_can( Admin::CAPABILITY );
	}

	/**
	 * The state.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle() {
		$response = new \WP_REST_Response( $this->state(), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * `{ connected: bool, tools: { chatgpt|claude|manual: "connected"|"not_connected" } }`.
	 *
	 * @return array{connected: bool, tools: array<string, string>}
	 */
	public function state(): array {
		$store     = call_user_func( $this->store );
		$connected = null !== $store->connection();
		$known     = $connected ? ( new Apps( $store ) )->tools() : array();
		$tools     = array();
		foreach ( Apps::TOOLS as $tool ) {
			$tools[ $tool ] = isset( $known[ $tool ] ) ? self::CONNECTED : self::NOT_CONNECTED;
		}
		return array(
			'connected' => $connected,
			'tools'     => $tools,
		);
	}
}
