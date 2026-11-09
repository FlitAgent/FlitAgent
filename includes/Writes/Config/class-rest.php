<?php
/**
 * Internal requests to core REST controllers.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Canonical;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a request against a core REST controller in-process (rest_do_request(), as the current user), so the
 * controller's own permission checks, validation and side effects apply. Never a passthrough: every route and method
 * is fixed by a typed tool.
 */
final class Rest {

	/**
	 * GET in the `edit` context.
	 *
	 * @param string               $route  Route, e.g. /wp/v2/menus/12.
	 * @param array<string, mixed> $params Query parameters.
	 * @return array<mixed>|null Data as arrays, or null when the controller refused (missing or not allowed).
	 */
	public static function read( string $route, array $params = array() ): ?array {
		$response = self::request( 'GET', $route, $params + array( 'context' => 'edit' ) );
		return self::failed( $response ) ? null : self::data( $response );
	}

	/**
	 * A write request.
	 *
	 * @param string               $method POST, PUT or DELETE.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $params Parameters.
	 * @return array<mixed> Data as arrays.
	 * @throws ToolError When the controller refused.
	 */
	public static function write( string $method, string $route, array $params ): array {
		$response = self::request( $method, $route, $params );
		if ( self::failed( $response ) ) {
			$data = self::data( $response );
			throw new ToolError( sprintf( 'WordPress refused the change: %s', isset( $data['message'] ) && is_string( $data['message'] ) ? esc_textarea( $data['message'] ) : 'unknown error.' ) );
		}
		return self::data( $response );
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $params Parameters.
	 * @return mixed
	 */
	private static function request( string $method, string $route, array $params ) {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}
		return rest_do_request( $request );
	}

	/**
	 * Whether a response is an error.
	 *
	 * @param mixed $response Response.
	 * @return bool
	 */
	private static function failed( $response ): bool {
		return ! is_object( $response ) || ! method_exists( $response, 'get_status' ) || (int) $response->get_status() >= 400;
	}

	/**
	 * Response data with objects turned into arrays.
	 *
	 * @param mixed $response Response.
	 * @return array<mixed>
	 */
	private static function data( $response ): array {
		$data = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : null;
		return Canonical::decode( Canonical::encode( $data ) );
	}
}
