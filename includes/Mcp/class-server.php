<?php
/**
 * Minimal stateless MCP server (JSON-RPC 2.0 over Streamable HTTP, JSON responses).
 *
 * @package FlitAgent
 */

namespace FlitAgent\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Handles one MCP JSON-RPC message at a time. Has no WordPress dependencies.
 */
final class Server {

	/**
	 * Supported MCP protocol versions, newest first.
	 */
	const SUPPORTED_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26' );

	const PARSE_ERROR      = -32700;
	const INVALID_REQUEST  = -32600;
	const METHOD_NOT_FOUND = -32601;
	const INVALID_PARAMS   = -32602;

	/**
	 * The message flitagent.com sends when an AI app was revoked there (signed with that app's client, params `{}`): the site
	 * forgets the app (Gateway\Authenticator) and answers an empty result. Advertised as the site feature `app_revoked`.
	 */
	const APP_REVOKED = 'flitagent/app_revoked';

	/**
	 * The message flitagent.com sends when an account or its owner disconnected this site there (signed for the
	 * connection that ended, with no app, params `{}`): the site deletes the connection (Gateway\Authenticator) and
	 * RestController answers an empty result. Advertised as the site feature `site_disconnected`.
	 */
	const SITE_DISCONNECTED = 'flitagent/site_disconnected';

	/**
	 * Server name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Human-readable server title.
	 *
	 * @var string
	 */
	private $title;

	/**
	 * Server version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Tools keyed by name.
	 *
	 * @var array<string, Tool>
	 */
	private $tools = array();

	/**
	 * Decides whether the current caller may list and call protected tools.
	 *
	 * @var callable|null
	 */
	private $access_gate;

	/**
	 * Returns the site details for the initialize result, or null for none. Null adds nothing.
	 *
	 * @var callable|null
	 */
	private $site_meta;

	/**
	 * Constructor.
	 *
	 * @param string        $name        Server name.
	 * @param string        $title       Human-readable title.
	 * @param string        $version     Server version.
	 * @param Tool[]        $tools       Tools to expose.
	 * @param callable|null $access_gate Returns true when the current caller may use protected tools. Null denies them.
	 * @param callable|null $site_meta   Returns the array for `_meta["com.flitagent/site"]` in the initialize result, or
	 *                                   null to leave it out. Null adds nothing.
	 */
	public function __construct( string $name, string $title, string $version, array $tools, ?callable $access_gate = null, ?callable $site_meta = null ) {
		$this->name        = $name;
		$this->title       = $title;
		$this->version     = $version;
		$this->access_gate = $access_gate;
		$this->site_meta   = $site_meta;
		foreach ( $tools as $tool ) {
			$this->tools[ $tool->name() ] = $tool;
		}
	}

	/**
	 * Decodes and handles a raw HTTP body.
	 *
	 * @param string $body Raw request body.
	 * @return array<string, mixed>|null Response, or null for notifications.
	 */
	public function handle_raw( string $body ): ?array {
		$message = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return self::error( null, self::PARSE_ERROR, 'Parse error' );
		}
		return $this->handle( $message );
	}

	/**
	 * Handles one decoded JSON-RPC message.
	 *
	 * @param mixed $message Decoded message.
	 * @return array<string, mixed>|null Response, or null for notifications.
	 */
	public function handle( $message ): ?array {
		if ( ! is_array( $message ) || array() === $message || array_key_exists( 0, $message ) ) {
			return self::error( null, self::INVALID_REQUEST, 'Invalid Request' );
		}

		$is_notification = ! array_key_exists( 'id', $message );
		$id              = $is_notification ? null : $message['id'];

		if ( ! $is_notification && ! is_string( $id ) && ! is_int( $id ) ) {
			return self::error( null, self::INVALID_REQUEST, 'Invalid Request' );
		}
		if ( ! isset( $message['jsonrpc'], $message['method'] ) || '2.0' !== $message['jsonrpc'] || ! is_string( $message['method'] ) ) {
			return self::error( $id, self::INVALID_REQUEST, 'Invalid Request' );
		}
		if ( $is_notification ) {
			return null;
		}

		$params = ( isset( $message['params'] ) && is_array( $message['params'] ) ) ? $message['params'] : array();

		switch ( $message['method'] ) {
			case 'initialize':
				return self::result( $id, $this->initialize( $params ) );
			case 'ping':
				return self::result( $id, new \stdClass() );
			case 'tools/list':
				return self::result( $id, array( 'tools' => $this->tool_definitions() ) );
			case 'tools/call':
				return $this->call_tool( $id, $params );
			case self::APP_REVOKED:
				// The Authenticator already forgot the app; flitagent.com only needs the acknowledgement.
				return self::result( $id, new \stdClass() );
			default:
				return self::error( $id, self::METHOD_NOT_FOUND, 'Method not found' );
		}
	}

	/**
	 * Builds the initialize result.
	 *
	 * @param array<string, mixed> $params Request params.
	 * @return array<string, mixed>
	 */
	private function initialize( array $params ): array {
		$requested = isset( $params['protocolVersion'] ) ? $params['protocolVersion'] : '';

		$result = array(
			'protocolVersion' => in_array( $requested, self::SUPPORTED_VERSIONS, true ) ? $requested : self::SUPPORTED_VERSIONS[0],
			'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
			'serverInfo'      => array(
				'name'    => $this->name,
				'title'   => $this->title,
				'version' => $this->version,
			),
			'instructions'    => 'Tools from the FlitAgent WordPress plugin for this WordPress site. Requests reach this site through flitagent.com and act as the administrator who connected the site. This version of FlitAgent reads the site and never changes it: every tool is read-only. Start with get_site_info for an overview of the site, or get_health_triage for what needs attention.',
		);

		$site = null === $this->site_meta ? null : call_user_func( $this->site_meta );
		if ( is_array( $site ) ) {
			$result['_meta'] = array( 'com.flitagent/site' => $site );
		}
		return $result;
	}

	/**
	 * Lists tool definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function tool_definitions(): array {
		$definitions = array();
		foreach ( $this->available_tools() as $tool ) {
			$definitions[] = $tool->definition();
		}
		return $definitions;
	}

	/**
	 * Tools the current caller may list and call. Protected tools, and tools with an unrecognised access level, need the access gate.
	 *
	 * @return array<string, Tool>
	 */
	private function available_tools(): array {
		return $this->filter_tools( $this->can_access_protected() );
	}

	/**
	 * Whether the current caller may use protected tools (the access gate; none denies them).
	 */
	private function can_access_protected(): bool {
		return null !== $this->access_gate && true === call_user_func( $this->access_gate );
	}

	/**
	 * A short fingerprint of the tool list an administrator sees: the access gate counts as allowed and is not called, so
	 * every request gets the same value. It changes only when the tool set changes.
	 *
	 * @return string 32 lowercase hex characters.
	 */
	public function tools_version(): string {
		$definitions = array();
		foreach ( $this->filter_tools( true ) as $tool ) {
			$definitions[] = $tool->definition();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Server is WordPress-free.
		return substr( hash( 'sha256', (string) json_encode( $definitions ) ), 0, 32 );
	}

	/**
	 * The registered tools that pass the access gate's result.
	 *
	 * @param bool $can_access_protected Whether protected tools are allowed.
	 * @return array<string, Tool>
	 */
	private function filter_tools( bool $can_access_protected ): array {
		$available = array();
		foreach ( $this->tools as $name => $tool ) {
			if ( ! $can_access_protected && Tool::ACCESS_PUBLIC !== $tool->access() ) {
				continue;
			}
			// Defence in depth: this version is read-only, so a tool is served only if it declares itself read-only.
			if ( true !== ( $tool->definition()['annotations']['readOnlyHint'] ?? null ) ) {
				continue;
			}
			$available[ $name ] = $tool;
		}
		return $available;
	}

	/**
	 * Calls a tool.
	 *
	 * @param string|int           $id     Request id.
	 * @param array<string, mixed> $params Request params.
	 * @return array<string, mixed>
	 */
	private function call_tool( $id, array $params ): array {
		$name  = isset( $params['name'] ) ? $params['name'] : null;
		$tools = $this->available_tools();
		// A protected tool the caller may not use gets the same error as a missing one, so its existence is not revealed.
		if ( ! is_string( $name ) || ! isset( $tools[ $name ] ) ) {
			return self::error( $id, self::INVALID_PARAMS, 'Unknown tool' );
		}

		$arguments = array_key_exists( 'arguments', $params ) ? $params['arguments'] : array();
		if ( null === $arguments ) {
			$arguments = array();
		}
		if ( ! is_array( $arguments ) ) {
			return self::error( $id, self::INVALID_PARAMS, 'Tool arguments must be an object' );
		}

		try {
			$data = $tools[ $name ]->call( $arguments );
		} catch ( ToolError $e ) {
			return self::tool_error( $id, ToolError::plain( $e ) );
		} catch ( \Throwable $e ) {
			return self::tool_error( $id, 'The tool failed to run.' );
		}

		return self::result(
			$id,
			array(
				'content'           => array(
					array(
						'type' => 'text',
						// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Server is WordPress-free.
						'text' => (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE ),
					),
				),
				'structuredContent' => $data,
				'isError'           => false,
			)
		);
	}

	/**
	 * Builds a success response.
	 *
	 * @param string|int $id     Request id.
	 * @param mixed      $result Result payload.
	 * @return array<string, mixed>
	 */
	private static function result( $id, $result ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Builds a tool error result (`isError: true`), which the model sees and can act on.
	 *
	 * @param string|int $id      Request id.
	 * @param string     $message Message for the model.
	 * @return array<string, mixed>
	 */
	private static function tool_error( $id, string $message ): array {
		return self::result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $message,
					),
				),
				'isError' => true,
			)
		);
	}

	/**
	 * Builds an error response.
	 *
	 * @param string|int|null $id      Request id.
	 * @param int             $code    JSON-RPC error code.
	 * @param string          $message Error message.
	 * @return array<string, mixed>
	 */
	private static function error( $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
