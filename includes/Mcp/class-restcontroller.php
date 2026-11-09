<?php
/**
 * Exposes the MCP server over the WordPress REST API.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Mcp;

use FlitAgent\Auth\Context;
use FlitAgent\Auth\Gateway\Authenticator;
use FlitAgent\Auth\Gateway\WpStore;

defined( 'ABSPATH' ) || exit;

/**
 * Registers POST /wp-json/flitagent/v1/mcp (Streamable HTTP, JSON responses, stateless). It answers only requests that
 * flitagent.com signed; every other request gets the generic 401.
 */
final class RestController {

	const REST_NAMESPACE = 'flitagent/v1';
	const ROUTE          = '/mcp';

	/**
	 * Response header with the fingerprint of the tool list at the paired scope.
	 */
	const TOOLS_VERSION_HEADER = 'FlitAgent-Tools-Version';

	/**
	 * JSON-RPC error code (server-error range) for a request from an AI tool an administrator disconnected on this site.
	 */
	const APP_DISCONNECTED_CODE = -32001;

	/**
	 * Error code of the 401 for a request flitagent.com signed for a connection this site does not hold (not_connected()).
	 */
	const NOT_CONNECTED = 'flitagent_not_connected';

	/**
	 * MCP server.
	 *
	 * @var Server
	 */
	private $server;

	/**
	 * Whether the connection is paired for writes (`fn(): bool`).
	 *
	 * @var callable
	 */
	private $paired_write;

	/**
	 * Whether this request's gateway signature was verified (`fn(): bool`).
	 *
	 * @var callable
	 */
	private $verified;

	/**
	 * The disconnected tool this verified request's AI app maps to, '' for none (`fn(): string`).
	 *
	 * @var callable
	 */
	private $disconnected;

	/**
	 * Whether this verified request was flitagent.com's `flitagent/site_disconnected` (`fn(): bool`).
	 *
	 * @var callable
	 */
	private $ended;

	/**
	 * Constructor.
	 *
	 * @param Server        $server       MCP server.
	 * @param callable|null $paired_write Whether the connection is paired for writes; defaults to the stored connection.
	 * @param callable|null $verified     Whether this request's gateway signature was verified; defaults to
	 *                                    Gateway\Authenticator::verified_this_request().
	 * @param callable|null $disconnected The disconnected tool of this verified request ('' for none); defaults to
	 *                                    Gateway\Authenticator::disconnected_tool().
	 * @param callable|null $ended        Whether this verified request ended the connection; defaults to
	 *                                    Gateway\Authenticator::site_ended().
	 */
	public function __construct( Server $server, ?callable $paired_write = null, ?callable $verified = null, ?callable $disconnected = null, ?callable $ended = null ) {
		$this->server       = $server;
		$this->paired_write = $paired_write ?? array( self::class, 'paired_write' );
		$this->verified     = $verified ?? array( Authenticator::class, 'verified_this_request' );
		$this->disconnected = $disconnected ?? array( Authenticator::class, 'disconnected_tool' );
		$this->ended        = $ended ?? array( Authenticator::class, 'site_ended' );
	}

	/**
	 * True only when a connection is stored and its paired scope is write.
	 */
	public static function paired_write(): bool {
		$connection = ( new WpStore() )->connection();
		return null !== $connection && Context::SCOPE_WRITE === $connection->scope();
	}

	/**
	 * Registers the route and the invalid-JSON passthrough.
	 */
	public function register(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_post' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);

		add_filter( 'rest_request_before_callbacks', array( $this, 'allow_invalid_json' ), 10, 3 );
		// Before route matching, permission and callbacks: a disconnected tool's request runs nothing.
		add_filter( 'rest_pre_dispatch', array( $this, 'answer_disconnected_app' ), 10, 3 );
		// After core's rest_send_allow_header() (priority 10), which lists every registered method.
		add_filter( 'rest_post_dispatch', array( $this, 'restrict_allow_header' ), 11, 3 );
	}

	/**
	 * On an MCP route response: advertises POST as the only allowed method (the GET handler exists only to answer 405) and,
	 * only when this request's gateway signature was verified and the status is not 401, adds `FlitAgent-Tools-Version`,
	 * computed at the paired scope. flitagent.com takes the header as proof that the site verified its signature, so an
	 * unsigned request (an `initialize` on an unpaired site, or one whose gateway headers were stripped) never gets it.
	 * A verified request's 200 and 202 do (including a disconnected tool's JSON-RPC error from answer_disconnected_app()),
	 * and so does the 403 `flitagent_pairing_user_lost_access` from rest_authentication_errors (returned only after
	 * verification), which core also runs through this filter.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param mixed             $server   REST server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response
	 */
	public function restrict_allow_header( $response, $server, $request ) {
		if ( $response instanceof \WP_REST_Response && '' !== self::mcp_route( (string) $request->get_route() ) ) {
			$response->header( 'Allow', 'POST' );
			if ( 401 !== $response->get_status() && true === call_user_func( $this->verified ) ) {
				$response->header( self::TOOLS_VERSION_HEADER, $this->server->tools_version( (bool) call_user_func( $this->paired_write ) ) );
			}
		}
		return $response;
	}

	/**
	 * Permission callback for POST and GET: true only for a request that Gateway\Authenticator verified (it set the
	 * gateway Context). Gateway\Authenticator already answers every other request with the generic 401 before dispatch;
	 * this keeps the route closed even if that filter never ran.
	 *
	 * @return true|\WP_Error
	 */
	public function permission() {
		return Context::GATEWAY === Context::current()->method() ? true : self::unauthorized();
	}

	/**
	 * Which MCP route a REST route is (ROUTE), or '' for another route. Matched the way core's REST server matches
	 * a route: case-insensitive, trailing slash ignored, and `$` (which also accepts a final newline).
	 *
	 * @param string $route REST route, e.g. `/flitagent/v1/mcp`.
	 */
	public static function mcp_route( string $route ): string {
		return 1 === preg_match( '@^/' . preg_quote( self::REST_NAMESPACE, '@' ) . preg_quote( self::ROUTE, '@' ) . '$@i', untrailingslashit( $route ) ) ? self::ROUTE : '';
	}

	/**
	 * The one answer for a gateway request that does not verify: nothing says why.
	 */
	public static function unauthorized(): \WP_Error {
		return new \WP_Error( 'flitagent_unauthorized', 'Unauthorized.', array( 'status' => 401 ) );
	}

	/**
	 * The 401 for a request whose key id, timestamp, signature and client verified but that was signed for a connection
	 * this site does not hold, or when it holds none (Gateway\Verifier::NOT_CONNECTED). Only flitagent.com can sign such a
	 * request, so the distinct code tells nothing to anyone else; it tells flitagent.com that the site disconnected.
	 * Like every 401 it carries no FlitAgent-Tools-Version.
	 */
	public static function not_connected(): \WP_Error {
		return new \WP_Error( self::NOT_CONNECTED, 'Not connected.', array( 'status' => 401 ) );
	}

	/**
	 * `rest_pre_dispatch` (#188): answers flitagent.com's verified `flitagent/site_disconnected` (the Authenticator
	 * deleted the connection) with an empty result, and a verified request whose AI app's tool an administrator
	 * disconnected on this site, before any route, permission callback or method runs (not even initialize or tools/list). HTTP 200 with a
	 * JSON-RPC error (the request id, APP_DISCONNECTED_CODE, a message for the user) and, through restrict_allow_header(),
	 * `FlitAgent-Tools-Version`: flitagent.com takes that as a verified, healthy site and passes the error to the app,
	 * where a 403 would mark the whole site unreachable. A notification gets 202 with no body, like every notification;
	 * a batch or a body that is not a JSON-RPC object gets the error with a null id. Every other request is left alone.
	 *
	 * @param mixed            $result  Response so far (null to dispatch).
	 * @param mixed            $server  REST server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function answer_disconnected_app( $result, $server, $request ) {
		if ( ! $request instanceof \WP_REST_Request || '' === self::mcp_route( (string) $request->get_route() ) ) {
			return $result;
		}
		$ended = (bool) call_user_func( $this->ended );
		$tool  = (string) call_user_func( $this->disconnected );
		if ( ! $ended && '' === $tool ) {
			return $result;
		}
		$message = json_decode( (string) $request->get_body(), true );
		$id      = null;
		if ( is_array( $message ) && array() !== $message && ! array_key_exists( 0, $message ) ) {
			if ( ! array_key_exists( 'id', $message ) ) {
				add_filter( 'rest_pre_serve_request', '__return_true' );
				return new \WP_REST_Response( null, 202 );
			}
			$id = is_string( $message['id'] ) || is_int( $message['id'] ) ? $message['id'] : null;
		}
		if ( $ended ) {
			// The Authenticator deleted the connection; flitagent.com only needs the acknowledgement.
			return new \WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'result'  => new \stdClass(),
				),
				200
			);
		}
		return new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => self::APP_DISCONNECTED_CODE,
					'message' => 'The site\'s administrator disconnected ' . $tool . ' from this site in wp-admin. They can set it up again under FlitAgent → Connect.',
					'data'    => array( 'code' => Authenticator::APP_DISCONNECTED ),
				),
			),
			200
		);
	}

	/**
	 * Lets malformed JSON reach the callback so the client gets a JSON-RPC parse error.
	 *
	 * @param mixed            $response Response or error so far.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function allow_invalid_json( $response, $handler, $request ) {
		if (
			is_wp_error( $response )
			&& 'rest_invalid_json' === $response->get_error_code()
			&& '' !== self::mcp_route( (string) $request->get_route() )
		) {
			return null;
		}
		return $response;
	}

	/**
	 * Handles one JSON-RPC message.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_post( $request ) {
		$response = $this->server->handle_raw( (string) $request->get_body() );

		if ( null === $response ) {
			// Notification: 202 Accepted with an empty body.
			add_filter( 'rest_pre_serve_request', '__return_true' );
			return new \WP_REST_Response( null, 202 );
		}

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * This server offers no SSE stream.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_get() {
		$response = new \WP_REST_Response(
			array(
				'code'    => 'flitagent_method_not_allowed',
				'message' => 'Use POST to send MCP messages.',
			),
			405
		);
		$response->header( 'Allow', 'POST' );
		return $response;
	}
}
