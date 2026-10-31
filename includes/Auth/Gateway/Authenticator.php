<?php
/**
 * Authenticates gateway-signed requests on the MCP route.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

use FlitAgent\Auth\Context;
use FlitAgent\Mcp\RestController;
use FlitAgent\Mcp\Server;

defined( 'ABSPATH' ) || exit;

/**
 * The MCP route answers only signed requests. Core's `determine_current_user` filter: on the MCP route only, when the request carries gateway headers and core has
 * not already determined a user, a request that verifies acts as the user who paired the connection. Then
 * `rest_authentication_errors`: a request without gateway headers (cookie session, application password, Basic,
 * anonymous), or with headers that did not authenticate this way (bad signature, another credential), gets the generic 401; one that verified in full but was signed for a connection this site does not hold (or it holds none,
 * Verifier::NOT_CONNECTED) gets the 401 RestController::not_connected(), with no Context and not marked verified; a correctly signed one whose pairing user was deleted or lost
 * `manage_options` gets 403 LOST_ACCESS; one whose AI app maps to a tool an administrator disconnected on this site
 * (Apps) gets no user and no Context, and RestController answers it with a JSON-RPC error (disconnected_tool()); any other sets `Auth\Context` to the AI app that made the request (its scope is
 * the lower of the app's and the connection's) and records the app (Apps::record(); an `initialize`, which only
 * flitagent.com's announcement of a newly allowed app sends, records it with no request yet), or, for the
 * `flitagent/app_revoked` message, forgets it (Apps::forget()). flitagent.com's `flitagent/site_disconnected` (signed
 * with no app) deletes the connection instead, before any user check (site_ended()). Those outcomes mark the
 * request verified (verified_this_request()). Nothing about the request is logged.
 *
 * The verification result is cached on the instance, which assumes one request per process (PHP-FPM, WP-CLI). Nonces
 * are single use, so verifying the same request twice would refuse the second time; the cache is what prevents that.
 */
final class Authenticator {

	const LOST_ACCESS = 'flitagent_pairing_user_lost_access';

	const APP_DISCONNECTED = 'flitagent_app_disconnected';

	/**
	 * Verifier.
	 *
	 * @var Verifier
	 */
	private $verifier;

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Returns the raw request body.
	 *
	 * @var callable
	 */
	private $body;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * The verified request, false when it did not verify, null before it was checked.
	 *
	 * @var Verified|false|null
	 */
	private $result;

	/**
	 * Verifier::refusal() of this request's verification ('' before it was checked).
	 *
	 * @var string
	 */
	private $refusal = '';

	/**
	 * Whether this request's gateway signature verified (set by authentication_errors(), per process).
	 *
	 * @var bool
	 */
	private static $verified = false;

	/**
	 * The disconnected tool this verified request's AI app maps to, '' for none (set by authentication_errors()).
	 *
	 * @var string
	 */
	private static $disconnected = '';

	/**
	 * Whether this verified request was flitagent.com's `flitagent/site_disconnected`, which deleted the connection (set by
	 * authentication_errors()).
	 *
	 * @var bool
	 */
	private static $ended = false;

	/**
	 * Constructor.
	 *
	 * @param Verifier      $verifier Verifier.
	 * @param Store         $store    Store (records the time of the last verified request and its AI app).
	 * @param callable|null $body     Returns the raw body (default: the REST server's raw data).
	 * @param callable|null $clock    Returns the Unix time (default: time()).
	 */
	public function __construct( Verifier $verifier, Store $store, ?callable $body = null, ?callable $clock = null ) {
		$this->verifier = $verifier;
		$this->store    = $store;
		$this->body     = null !== $body ? $body : array( \WP_REST_Server::class, 'get_raw_data' );
		$this->clock    = null !== $clock ? $clock : 'time';
	}

	/**
	 * Hooks: after core's cookie (10) and application password (20) authentication; after RestController's 401 (95),
	 * before core's cookie nonce check (100).
	 */
	public function register(): void {
		add_filter( 'determine_current_user', array( $this, 'determine_user' ), 30 );
		add_filter( 'rest_authentication_errors', array( $this, 'authentication_errors' ), 96 );
	}

	/**
	 * `determine_current_user`: the pairing user for a verified gateway request whose AI app's tool is not disconnected.
	 *
	 * @param int|false $user_id User determined so far.
	 * @return int|false
	 */
	public function determine_user( $user_id ) {
		if ( ! empty( $user_id ) || ! self::is_mcp_route() ) {
			return $user_id;
		}
		$headers = self::headers();
		if ( ! Verifier::has_headers( $headers ) ) {
			return $user_id;
		}
		if ( null === $this->result ) {
			$verified      = $this->verifier->verify( $headers, self::method(), (string) call_user_func( $this->body ), (int) call_user_func( $this->clock ) );
			$this->result  = null !== $verified ? $verified : false;
			$this->refusal = $this->verifier->refusal();
		}
		// A disconnected tool's request never becomes the pairing user (authentication_errors() then answers 403).
		if ( ! $this->result instanceof Verified || false === get_userdata( $this->result->connection()->user_id() ) || ( new Apps( $this->store ) )->is_blocked( $this->result->client() ) ) {
			return $user_id;
		}
		return $this->result->connection()->user_id();
	}

	/**
	 * `rest_authentication_errors`: the generic 401 for a request without gateway headers or whose headers did not authenticate, the 401
	 * `flitagent_not_connected` for one signed for a connection this site does not hold, 403 when the
	 * pairing user lost access, true without a Context when the AI app's tool was disconnected on this site (RestController
	 * then answers with a JSON-RPC error), or the AI app's context for a request that did.
	 *
	 * @param \WP_Error|true|null $result Authentication result so far.
	 * @return \WP_Error|true|null
	 */
	public function authentication_errors( $result ) {
		self::$verified     = false;
		self::$disconnected = '';
		self::$ended        = false;
		if ( ! self::is_mcp_route() ) {
			return $result;
		}
		if ( ! Verifier::has_headers( self::headers() ) ) {
			// Cookie session, application password, Basic, anonymous: nothing but a signed request is answered.
			Context::reset();
			return RestController::unauthorized();
		}
		// The REST server clears the current user before this filter, so this may be what runs determine_user().
		$user_id  = get_current_user_id();
		$verified = $this->result;
		if ( ! $verified instanceof Verified || is_wp_error( $result ) ) {
			Context::reset();
			// Only when this filter decides alone: an earlier error (another credential) keeps the generic 401.
			if ( false === $verified && ! is_wp_error( $result ) && Verifier::NOT_CONNECTED === $this->refusal ) {
				return RestController::not_connected();
			}
			return RestController::unauthorized();
		}
		$method = self::rpc_method( (string) call_user_func( $this->body ) );
		if ( Server::SITE_DISCONNECTED === $method && Apps::is_relay( $verified->client() ) ) {
			// flitagent.com ended this connection (an account or its owner disconnected the site there). Only a request
			// verified for the connection this site holds gets here, so a forged one ends nothing. Before the pairing
			// user's check: whoever paired the site, the connection is over. No nudge back: flitagent.com knows.
			$this->store->delete_connection();
			$this->store->delete_pending();
			Keys::unschedule();
			$this->store->save_ended( (int) call_user_func( $this->clock ) );
			Context::reset();
			self::$verified = true;
			self::$ended    = true;
			return true;
		}
		$pairing_user = $verified->connection()->user_id();
		$user         = get_userdata( $pairing_user );
		if ( false === $user || ! user_can( $user, 'manage_options' ) ) {
			Context::reset();
			self::$verified = true;
			return new \WP_Error( self::LOST_ACCESS, 'The administrator who connected this site to flitagent.com can no longer use FlitAgent. An administrator has to connect the site again in wp-admin.', array( 'status' => 403 ) );
		}
		// Before the user check: determine_user() gives a disconnected tool's request no user. No Context either, so no
		// tool can run: RestController answers it before dispatch with a JSON-RPC error (HTTP 200, which flitagent.com
		// counts as a healthy site; a 403 would mark the whole site unreachable), and permission() refuses it if not.
		$apps = new Apps( $this->store );
		if ( $apps->is_blocked( $verified->client() ) ) {
			if ( Server::APP_REVOKED === $method ) {
				// Its record went when the tool was disconnected; the revocation still stops an older request in flight
				// from recording it once Set up allows the tool again.
				$apps->forget( $verified, (int) call_user_func( $this->clock ) );
			}
			Context::reset();
			self::$verified     = true;
			self::$disconnected = Apps::label( Apps::tool( $verified->client()->name() ) );
			return true;
		}
		if ( $user_id !== $pairing_user ) {
			Context::reset();
			return RestController::unauthorized();
		}
		Context::set( $verified->context() );
		$now = (int) call_user_func( $this->clock );
		// flitagent.com tells the site when an app is revoked (Server::APP_REVOKED, signed with that app's client) and
		// announces an app it just allowed with an `initialize` (an app's own initialize never reaches the site).
		// Neither is a request of the app, and neither are the relay's own requests (its check page, cache refreshes):
		// the connection's last request counts only what AI tools asked, like each tool's own (e2e 2026-10-30, M12).
		$announced = 'initialize' === $method;
		if ( Server::APP_REVOKED !== $method && ! $announced && ! Apps::is_relay( $verified->client() ) ) {
			$this->store->save_last_request( $now );
		}
		if ( Server::APP_REVOKED === $method ) {
			$apps->forget( $verified, $now );
		} else {
			$apps->record( $verified, $now, ! $announced );
		}
		self::$verified = true;
		return true;
	}

	/**
	 * The method of a body that is one valid JSON-RPC request with an id, as Server::handle() accepts it; '' for a
	 * notification, a batch or anything invalid (which therefore never forgets an app or counts as an announcement).
	 *
	 * @param string $body Raw body.
	 * @return string
	 */
	private static function rpc_method( string $body ): string {
		$message = json_decode( $body, true );
		if ( ! is_array( $message ) || array() === $message || array_key_exists( 0, $message ) || ! array_key_exists( 'id', $message ) || ( ! is_string( $message['id'] ) && ! is_int( $message['id'] ) ) ) {
			return '';
		}
		return isset( $message['jsonrpc'], $message['method'] ) && '2.0' === $message['jsonrpc'] && is_string( $message['method'] ) ? $message['method'] : '';
	}

	/**
	 * Whether this request's gateway signature was verified: it got the gateway Context, the 403 LOST_ACCESS, or the
	 * disconnected-tool answer (all only after verification). RestController sends `FlitAgent-Tools-Version` only then, since
	 * flitagent.com takes that header as proof that the site checked its signature.
	 */
	public static function verified_this_request(): bool {
		return self::$verified;
	}

	/**
	 * The disconnected tool (its English name) this verified request's AI app maps to, or '' (#188).
	 * RestController::answer_disconnected_app() answers such a request with a JSON-RPC error.
	 */
	public static function disconnected_tool(): string {
		return self::$disconnected;
	}

	/**
	 * Whether this verified request was flitagent.com's `flitagent/site_disconnected` and the connection is deleted.
	 * RestController::answer_disconnected_app() answers it with an empty result.
	 */
	public static function site_ended(): bool {
		return self::$ended;
	}

	/**
	 * The REST route WordPress is serving ('' before it is parsed), as RestController reads it.
	 */
	private static function route(): string {
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';
		return is_string( $route ) ? $route : '';
	}

	/**
	 * Whether WordPress is serving the MCP route. Matched the way core's REST server matches a route (the same regex
	 * on the route without a trailing slash), so a spelling core routes here, such as one ending in a newline, is
	 * not missed.
	 */
	private static function is_mcp_route(): bool {
		return '' !== RestController::mcp_route( self::route() );
	}

	/**
	 * The request method.
	 */
	private static function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	}

	/**
	 * The gateway headers that were sent (lower-case names). Values are passed on as sent; the Verifier only accepts
	 * a strict character set for each.
	 *
	 * @return array<string, mixed>
	 */
	private static function headers(): array {
		$headers = array();
		foreach ( Verifier::HEADERS as $name ) {
			$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
			if ( isset( $_SERVER[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared byte for byte against a signature; Verifier allows only [A-Za-z0-9_-] (and digits for the timestamp).
				$headers[ $name ] = is_string( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : null;
			}
		}
		return $headers;
	}
}
