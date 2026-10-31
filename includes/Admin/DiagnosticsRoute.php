<?php
/**
 * REST route behind the custom header check.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

use FlitAgent\Mcp\RestController;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/flitagent/v1/diagnostics/headers: answers only whether this run's test `FlitAgent-Diagnostics` header
 * reached PHP, never its value.
 *
 * The loopback request carries no cookies or credentials, so it cannot be checked against the access gate itself.
 * Instead it must carry a per-run token (DiagnosticsToken) that only the "Test connection" button mints, after the
 * access gate (`manage_options`) and nonce checks; anything else gets a generic 403.
 */
final class DiagnosticsRoute {

	const ROUTE = '/diagnostics/headers';

	/**
	 * The custom request header the check sends.
	 */
	const HEADER = 'FlitAgent-Diagnostics';

	/**
	 * Returns the signing secret.
	 *
	 * @var callable
	 */
	private $secret;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null $secret Returns the signing secret; defaults to the site's nonce salt.
	 * @param callable|null $clock  Returns the current Unix time; defaults to time().
	 */
	public function __construct( ?callable $secret = null, ?callable $clock = null ) {
		$this->secret = $secret ?? array( self::class, 'site_secret' );
		$this->clock  = $clock ?? 'time';
	}

	/**
	 * Signing secret shared by the settings screen and this route: the site's nonce salt.
	 *
	 * @return string
	 */
	public static function site_secret(): string {
		return wp_salt( 'nonce' );
	}

	/**
	 * Registers the route. Runs on `rest_api_init`.
	 */
	public function register(): void {
		register_rest_route(
			RestController::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	/**
	 * Accepts only a valid, unexpired per-run token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function permission( $request ) {
		$token = $request->get_param( 'token' );
		if ( is_string( $token ) && DiagnosticsToken::verify( (string) call_user_func( $this->secret ), $token, (int) call_user_func( $this->clock ) ) ) {
			return true;
		}
		return new \WP_Error( 'flitagent_diagnostics_forbidden', 'Invalid or expired diagnostics token.', array( 'status' => 403 ) );
	}

	/**
	 * Reports whether the `FlitAgent-Diagnostics` header arrived carrying the token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( $request ) {
		$token   = (string) $request->get_param( 'token' );
		$header  = $request->get_header( 'flitagent_diagnostics' );
		$arrived = is_string( $header ) && hash_equals( $token, trim( $header ) );

		$response = new \WP_REST_Response( array( 'headers' => $arrived ), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
