<?php
/**
 * The wp_rest_get tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Rest;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Annotations;
use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Reads any WordPress REST endpoint with a GET request, as the current user.
 *
 * Wraps core REST (prior art): permissions are WordPress's own per-route checks.
 */
final class RestGet implements Tool {

	/**
	 * Largest JSON response returned, in bytes.
	 */
	const MAX_BYTES = 524288;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'wp_rest_get';
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return Tool::ACCESS_PUBLIC;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		$scalar = array( 'type' => array( 'string', 'number', 'integer', 'boolean' ) );

		return array(
			'name'         => $this->name(),
			'title'        => 'Read a WordPress REST endpoint with FlitAgent',
			'description'  => 'From the FlitAgent WordPress plugin: sends a GET request to any WordPress REST API route on this site, with the caller\'s own WordPress permissions, and returns the status, data and paging totals. Use it for posts, pages, media, users, comments, taxonomies, terms, post types, menus, menu items, menu locations, widgets, sidebars, settings, plugins and themes, e.g. path "/wp/v2/posts" with query {"per_page": 10, "_fields": "id,title,status"}. Read-only: no other HTTP methods. Secret-looking fields are redacted.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'path'  => array(
						'type'        => 'string',
						'description' => 'REST route starting with "/", without "/wp-json" or a query string, e.g. "/wp/v2/posts" or "/wp/v2/users/me".',
					),
					'query' => array(
						'type'                 => 'object',
						'description'          => 'Query parameters, e.g. {"per_page": 20, "search": "hello", "_fields": "id,title"}. Values are scalars or lists of scalars.',
						'additionalProperties' => array(
							'anyOf' => array(
								$scalar,
								array(
									'type'  => 'array',
									'items' => $scalar,
								),
							),
						),
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'status'      => array( 'type' => 'integer' ),
					'data'        => array( 'description' => 'The response body, with secret-looking fields redacted.' ),
					'total'       => array( 'type' => array( 'integer', 'null' ) ),
					'total_pages' => array( 'type' => array( 'integer', 'null' ) ),
				),
				'required'   => array( 'status', 'data', 'total', 'total_pages' ),
			),
			'annotations'  => Annotations::read_only( 'Read a WordPress REST endpoint with FlitAgent' ),
		);
	}

	/**
	 * Runs the GET request.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the arguments are invalid, WordPress returns an error, or the response is too large.
	 */
	public function call( array $arguments ): array {
		foreach ( array_keys( $arguments ) as $key ) {
			if ( 'path' !== $key && 'query' !== $key ) {
				throw new ToolError( 'Unknown argument. wp_rest_get accepts only path and query.' );
			}
		}

		$path  = self::path( $arguments );
		$query = self::query( $arguments );

		$request = new \WP_REST_Request( 'GET', $path );
		foreach ( $query as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );
		// Core applies _fields only when serving HTTP (rest_post_dispatch); do the same for this internal request.
		$response = rest_filter_response_fields( $response, rest_get_server(), $request );
		$status   = (int) $response->get_status();
		$data     = rest_get_server()->response_to_data( $response, false );

		if ( $status >= 400 ) {
			$code    = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'error';
			$message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'WordPress returned an error.';
			throw new ToolError( sprintf( 'WordPress REST returned %d (%s): %s', (int) $status, esc_textarea( $code ), esc_textarea( $message ) ) );
		}

		$data = Redactor::redact( $data );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- size check only.
		$bytes = strlen( (string) json_encode( $data ) );
		if ( $bytes > self::MAX_BYTES ) {
			throw new ToolError( sprintf( 'The response is %d KB, over the 512 KB limit. Ask for less with query per_page (e.g. 10) and _fields (e.g. "id,title,status").', (int) ceil( $bytes / 1024 ) ) );
		}

		$headers = $response->get_headers();

		return array(
			'status'      => $status,
			'data'        => $data,
			'total'       => isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : null,
			'total_pages' => isset( $headers['X-WP-TotalPages'] ) ? (int) $headers['X-WP-TotalPages'] : null,
		);
	}

	/**
	 * Validates the route.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @return string
	 * @throws ToolError When the path is missing or unsafe.
	 */
	private static function path( array $arguments ): string {
		$path = isset( $arguments['path'] ) ? $arguments['path'] : null;

		if ( ! is_string( $path ) || '' === $path || '/' !== $path[0] || 0 === strpos( $path, '//' ) ) {
			throw new ToolError( 'path must be a REST route starting with "/", e.g. "/wp/v2/posts".' );
		}
		if ( false !== strpos( $path, '://' ) || false !== strpos( $path, '?' ) || false !== strpos( $path, '#' ) ) {
			throw new ToolError( 'path must be a route only: no scheme, host or query string. Put parameters in query.' );
		}
		if ( in_array( '..', explode( '/', $path ), true ) ) {
			throw new ToolError( 'path must not contain "..".' );
		}
		if ( 0 === stripos( $path, '/flitagent/' ) || 0 === strcasecmp( rtrim( $path, '/' ), '/flitagent' ) ) {
			throw new ToolError( 'path cannot target FlitAgent\'s own MCP route.' );
		}

		return $path;
	}

	/**
	 * Validates the query parameters.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When query is not an object of scalars or lists of scalars.
	 */
	private static function query( array $arguments ): array {
		if ( ! array_key_exists( 'query', $arguments ) || null === $arguments['query'] ) {
			return array();
		}

		$query = $arguments['query'];
		if ( ! is_array( $query ) || ( array() !== $query && array_keys( $query ) === range( 0, count( $query ) - 1 ) ) ) {
			throw new ToolError( 'query must be an object of parameter names to values.' );
		}

		foreach ( $query as $value ) {
			$values = is_array( $value ) ? $value : array( $value );
			foreach ( $values as $item ) {
				if ( ! is_scalar( $item ) ) {
					throw new ToolError( 'query values must be strings, numbers, booleans or lists of those.' );
				}
			}
		}

		return $query;
	}
}
