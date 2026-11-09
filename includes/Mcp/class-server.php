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
	 * Decides whether the current credential may use tools that are not read-only. Null allows them.
	 *
	 * @var callable|null
	 */
	private $write_gate;

	/**
	 * Returns the site details for the initialize result, or null for none. Null adds nothing.
	 *
	 * @var callable|null
	 */
	private $site_meta;

	/**
	 * Returns why a tool this site knows is not registered right now (a write tool while Access for AI tools is Read only),
	 * or null when the name is not such a tool. Null gives every unregistered name "Unknown tool".
	 *
	 * @var callable|null
	 */
	private $unavailable;

	/**
	 * Constructor.
	 *
	 * @param string        $name        Server name.
	 * @param string        $title       Human-readable title.
	 * @param string        $version     Server version.
	 * @param Tool[]        $tools       Tools to expose.
	 * @param callable|null $access_gate Returns true when the current caller may use protected tools. Null denies them.
	 * @param callable|null $write_gate  Returns true when the current credential may use tools not annotated
	 *                                   `readOnlyHint: true` (a read-scoped credential gets false). Null allows them.
	 * @param callable|null $site_meta   Returns the array for `_meta["com.flitagent/site"]` in the initialize result, or
	 *                                   null to leave it out. Null adds nothing.
	 * @param callable|null $unavailable Takes a tool name that is not registered and returns the message for a caller who
	 *                                   may use protected tools (why the tool is off on this site), or null for "Unknown
	 *                                   tool". Never lists or describes the tool. Null: always "Unknown tool".
	 */
	public function __construct( string $name, string $title, string $version, array $tools, ?callable $access_gate = null, ?callable $write_gate = null, ?callable $site_meta = null, ?callable $unavailable = null ) {
		$this->name        = $name;
		$this->title       = $title;
		$this->version     = $version;
		$this->access_gate = $access_gate;
		$this->write_gate  = $write_gate;
		$this->site_meta   = $site_meta;
		$this->unavailable = $unavailable;
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
			'instructions'    => 'Tools from the FlitAgent WordPress plugin for this WordPress site. Requests reach this site through flitagent.com and act as the administrator who connected the site. Write tools (such as update_post) appear only while Access for AI tools is Read and propose changes, the default; an administrator can set it to Read only in wp-admin (FlitAgent > Settings). Every write needs a new idempotency_key per intended change (repeat a key only to retry the same call). Use dry_run: true to see the tier, summary and preview without writing. A write either applies at once (only low-risk, reversible changes an administrator chose to auto-approve) or returns status approval_required with a change_id and approve_url: nothing has changed yet; ask a human to approve it in wp-admin, then call apply_change with that change_id and a new idempotency_key before the approval expires. If the target changed in the meantime the change is stale and must be requested again. Undo a write with rollback_change and its receipt_id; review writes with list_receipts and get_change. Receipts are an audit record kept on the site.',
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
		$can_write = null === $this->write_gate || true === call_user_func( $this->write_gate );

		return $this->filter_tools( $this->can_access_protected(), $can_write );
	}

	/**
	 * Whether the current caller may use protected tools (the access gate; none denies them).
	 */
	private function can_access_protected(): bool {
		return null !== $this->access_gate && true === call_user_func( $this->access_gate );
	}

	/**
	 * A short fingerprint of the tool list at the connection's paired scope, not the request's: the access gate counts as
	 * allowed and `$paired_write` decides whether tools without `readOnlyHint: true` are listed. Neither gate callable is
	 * called, so a read and a write request get the same value. It changes when writes are turned on or off, the paired
	 * scope changes, or the tool set changes.
	 *
	 * @param bool $paired_write Whether the connection is paired for writes.
	 * @return string 32 lowercase hex characters.
	 */
	public function tools_version( bool $paired_write ): string {
		$definitions = array();
		foreach ( $this->filter_tools( true, $paired_write ) as $tool ) {
			$definitions[] = $tool->definition();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Server is WordPress-free.
		return substr( hash( 'sha256', (string) json_encode( $definitions ) ), 0, 32 );
	}

	/**
	 * The registered tools that pass the two gates' results.
	 *
	 * @param bool $can_access_protected Whether protected tools are allowed.
	 * @param bool $can_write            Whether tools that are not read-only are allowed.
	 * @return array<string, Tool>
	 */
	private function filter_tools( bool $can_access_protected, bool $can_write ): array {
		$available = array();
		foreach ( $this->tools as $name => $tool ) {
			if ( ! $can_access_protected && Tool::ACCESS_PUBLIC !== $tool->access() ) {
				continue;
			}
			if ( ! $can_write && ! self::is_read_only( $tool ) ) {
				continue;
			}
			$available[ $name ] = $tool;
		}
		return $available;
	}

	/**
	 * Whether a tool declares itself read-only (`annotations.readOnlyHint` true).
	 *
	 * @param Tool $tool Tool.
	 */
	private static function is_read_only( Tool $tool ): bool {
		$definition = $tool->definition();
		return isset( $definition['annotations']['readOnlyHint'] ) && true === $definition['annotations']['readOnlyHint'];
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
			return self::error( $id, self::INVALID_PARAMS, $this->unknown_tool_message( $name ) );
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Server is WordPress-free.
		if ( false === json_encode( $data ) ) {
			$data = self::valid_utf8( $data );
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
	 * A tool's result with invalid UTF-8 replaced by U+FFFD, so that the text and the structured copy read the same:
	 * left alone, WordPress would encode the structured copy with "?" instead.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function valid_utf8( $value ) {
		if ( is_string( $value ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Server is WordPress-free.
			return 1 === preg_match( '//u', $value ) ? $value : (string) json_decode( (string) json_encode( $value, JSON_INVALID_UTF8_SUBSTITUTE ) );
		}
		if ( $value instanceof \stdClass ) {
			return (object) self::valid_utf8( (array) $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			$out[ is_string( $key ) ? self::valid_utf8( $key ) : $key ] = self::valid_utf8( $item );
		}
		return $out;
	}

	/**
	 * The message for a tool the caller cannot call: why it is off (Access for AI tools is Read only) when the site knows
	 * it, is not registering it now and the caller may use protected tools; else "Unknown tool". A registered tool the
	 * caller's gates filter out (a protected tool, or a write tool for a read-scoped connection) stays "Unknown tool".
	 *
	 * @param mixed $name The requested tool name.
	 * @return string
	 */
	private function unknown_tool_message( $name ): string {
		if ( null === $this->unavailable || ! is_string( $name ) || isset( $this->tools[ $name ] ) || ! $this->can_access_protected() ) {
			return 'Unknown tool';
		}
		$message = call_user_func( $this->unavailable, $name );
		return is_string( $message ) && '' !== $message ? $message : 'Unknown tool';
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
