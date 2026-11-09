<?php
/**
 * The gateway's form handlers: Connect, the pairing return, Disconnect, and an AI tool's Disconnect and Set up.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

use FlitAgent\Admin\ConnectScreen;
use FlitAgent\Admin\Onboarding;
use FlitAgent\Admin\Request;
use FlitAgent\Auth\Context;
use FlitAgent\Plugin;
use FlitAgent\Writes\Wp\WpPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * "Connect to flitagent.com" (form on the Connect screen and in the setup guide's Connect step, both offering `write` as
 * the ceiling), the pairing return (which sets Access for AI tools to what was chosen on flitagent.com's Approve page,
 * when a choice came back), "Disconnect site", and an AI tool's "Disconnect" and "Set up" (#188), for users passing the
 * access gate.
 * Connect and the Disconnect and Set up forms are POSTed with a nonce. The return is a GET from the gateway that carries
 * a nonce of its own, plus a single-use pairing nonce bound to the user who started it.
 * Every handler needs a wp-admin session (not an application password) and the access gate.
 *
 * A tool's Disconnect (of a tool that was not disconnected), a tool's Set up (of a tool that was disconnected) and
 * Disconnect site (while the site held a connection) each send flitagent.com one nudge with the connection id (Nudge),
 * after the change is stored. Its answer changes nothing here: the redirect and the notice are the same either way.
 *
 * With an invalid gateway URL ('') these handlers are still hooked: Connect and the return store nothing and say why,
 * and the Disconnect and Set up forms change this site only, with no nudge.
 */
final class AdminSection {

	const CONNECT    = 'flitagent_gateway_connect';
	const RETURN     = 'flitagent_gateway_return';
	const DISCONNECT = 'flitagent_gateway_disconnect';

	const DISCONNECT_APP = 'flitagent_gateway_disconnect_app';
	const SETUP_APP      = 'flitagent_gateway_setup_app';

	/**
	 * Field of a connect form posted into a new tab, carried on the return URL (#198).
	 */
	const NEW_TAB = 'flitagent_tab';

	/**
	 * Field of an AI tool's Disconnect or Set up form posted from the setup guide's Connect step (#209), and its value:
	 * the handler then returns to that step rather than to the Connect screen.
	 */
	const FROM       = 'flitagent_from';
	const FROM_GUIDE = 'onboarding';

	/**
	 * Gateway URL.
	 *
	 * @var string
	 */
	private $gateway;

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Gateway key set.
	 *
	 * @var Keys
	 */
	private $keys;

	/**
	 * Returns the Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Tells flitagent.com that something changed: fn( string $connection_id ): bool.
	 *
	 * @var callable
	 */
	private $nudge;

	/**
	 * Constructor.
	 *
	 * @param string        $gateway Gateway URL.
	 * @param Store         $store   Store.
	 * @param Keys          $keys    Gateway key set.
	 * @param callable|null $clock   Returns the Unix time (default: time()).
	 * @param callable|null $nudge   fn( string $connection_id ): bool (default: Nudge::send() for the gateway URL).
	 */
	public function __construct( string $gateway, Store $store, Keys $keys, ?callable $clock = null, ?callable $nudge = null ) {
		$this->gateway = $gateway;
		$this->store   = $store;
		$this->keys    = $keys;
		$this->clock   = null !== $clock ? $clock : 'time';
		$this->nudge   = null !== $nudge ? $nudge : array( new Nudge( $gateway ), 'send' );
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::CONNECT, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::RETURN, array( $this, 'handle_return' ) );
		add_action( 'admin_post_' . self::DISCONNECT, array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_' . self::DISCONNECT_APP, array( $this, 'handle_disconnect_app' ) );
		add_action( 'admin_post_' . self::SETUP_APP, array( $this, 'handle_setup_app' ) );
	}

	/**
	 * Starts pairing: refreshes the gateway key set, stores a single-use nonce and returns the gateway's pairing URL (or the
	 * Connect screen with a notice). A form posted into a new tab (`flitagent_tab=1`, #198) marks the return URL, so the
	 * way back can close that tab.
	 *
	 * @param array<string, mixed> $post Sanitized POST fields (Request::post()).
	 * @return string URL to redirect to.
	 */
	public function connect( array $post ): string {
		return self::in_tab( $this->start( $post ), $post );
	}

	/**
	 * Connect, as connect() returns it in the same tab.
	 *
	 * @param array<string, mixed> $post Sanitized POST fields (Request::post()).
	 * @return string URL to redirect to.
	 */
	private function start( array $post ): string {
		$this->guard( $post, self::CONNECT );
		$scope = isset( $post['scope'] ) && is_string( $post['scope'] ) ? $post['scope'] : '';
		if ( ! in_array( $scope, array( Context::SCOPE_READ, Context::SCOPE_WRITE ), true ) ) {
			wp_die( esc_html__( 'Unknown access level.', 'flitagent' ), '', array( 'response' => 400 ) );
		}
		if ( null !== $this->store->connection() ) {
			self::notify( __( 'This site is already connected to flitagent.com. Disconnect it first.', 'flitagent' ) );
			return self::connect_url();
		}
		if ( '' === $this->gateway || ConnectScreen::is_local( home_url(), rest_url( 'flitagent/v1/mcp' ), $this->gateway ) ) {
			self::notify( '' === $this->gateway ? ConnectScreen::invalid_gateway_message() : __( 'flitagent.com must reach this site over the internet, so local and private sites cannot be connected.', 'flitagent' ) );
			return self::connect_url();
		}
		$error = $this->keys->refresh();
		if ( '' !== $error ) {
			self::notify( __( 'Could not start connecting to flitagent.com:', 'flitagent' ) . ' ' . $error );
			return self::connect_url();
		}
		try {
			$return = admin_url( 'admin-post.php?action=' . self::RETURN ) . ( self::in_new_tab( $post ) ? '&' . self::NEW_TAB . '=1' : '' ) . '&_wpnonce=' . wp_create_nonce( self::RETURN );
			return ( new Pairing( $this->store ) )->start( get_current_user_id(), $scope, $this->gateway, rest_url( 'flitagent/v1/mcp' ), $return, (int) call_user_func( $this->clock ) );
		} catch ( \RuntimeException $e ) {
			// Pairing's messages are plain English; the one an administrator meets in normal use is translated here.
			$message = Pairing::LOCKED === $e->getMessage() ? __( 'Another connection attempt is finishing. Try again in a moment.', 'flitagent' ) : $e->getMessage();
			self::notify( __( 'Could not start connecting to flitagent.com:', 'flitagent' ) . ' ' . $message );
		} catch ( \InvalidArgumentException $e ) {
			self::notify( __( 'Could not start connecting to flitagent.com:', 'flitagent' ) . ' ' . $e->getMessage() );
		}
		return self::connect_url();
	}

	/**
	 * The gateway's return: a refusal code (acted on only with the pending pairing's nonce), or the signed pairing
	 * result. A good result stores the connection and returns the flitagent.com check page (its host allowed for the
	 * redirect); anything else stores nothing and returns to the Connect screen.
	 *
	 * @param array<string, mixed> $get Sanitized query args (Request::query()).
	 * @return string URL to redirect to.
	 */
	public function complete( array $get ): string {
		return self::in_tab( $this->finish( $get ), $get );
	}

	/**
	 * The gateway's return, as complete() handles it in the same tab.
	 *
	 * @param array<string, mixed> $get Sanitized query args (Request::query()).
	 * @return string URL to redirect to.
	 */
	private function finish( array $get ): string {
		$this->guard( $get, '' );
		// Going back can't help here: flitagent.com has already used up its copy of the request.
		if ( ! wp_verify_nonce( $get['_wpnonce'] ?? '', self::RETURN ) ) {
			wp_die( esc_html__( 'The link expired. Open FlitAgent, then Connect, and start connecting again.', 'flitagent' ), '', array( 'response' => 403 ) );
		}
		// Without a valid gateway URL nothing is accepted, even signed with a key the site still holds.
		if ( '' === $this->gateway ) {
			self::notify( ConnectScreen::invalid_gateway_message() );
			return self::connect_url();
		}
		$pairing = new Pairing( $this->store );
		if ( isset( $get['flitagent_pairing_error'] ) ) {
			// Only the pending pairing's own nonce acts on a refusal; anything else is ignored without a word.
			$code  = is_string( $get['flitagent_pairing_error'] ) ? $get['flitagent_pairing_error'] : '';
			$nonce = isset( $get['nonce'] ) && is_string( $get['nonce'] ) ? $get['nonce'] : '';
			if ( $pairing->cancel( $nonce, (int) call_user_func( $this->clock ) ) ) {
				self::notify( self::error_message( $code ) );
			}
			return self::connect_url();
		}

		$result    = isset( $get['flitagent_pairing'] ) && is_string( $get['flitagent_pairing'] ) ? $get['flitagent_pairing'] : '';
		$signature = isset( $get['flitagent_signature'] ) && is_string( $get['flitagent_signature'] ) ? $get['flitagent_signature'] : '';

		$connection = '' === $result || '' === $signature ? null : $pairing->complete( $result, $signature, get_current_user_id(), rest_url( 'flitagent/v1/mcp' ), (int) call_user_func( $this->clock ), array( self::class, 'apply_access' ) );
		if ( null === $connection ) {
			self::notify( __( 'The answer from flitagent.com was refused and no connection was stored. Start connecting again.', 'flitagent' ) );
			return self::connect_url();
		}
		$this->keys->sync_schedule();
		self::notify(
			$pairing->access_failed()
				? __( 'Connected to flitagent.com. Access for AI tools could not be changed to Read and propose changes; set it in FlitAgent → Settings.', 'flitagent' )
				: __( 'Connected to flitagent.com.', 'flitagent' )
		);
		if ( self::in_new_tab( $get ) ) {
			// The tab hands back and closes; flitagent.com confirms the site on its first verified request instead of the
			// check page, and the Connect screen links the check page until an AI tool's first request.
			return ConnectScreen::tab_done_url( ConnectScreen::TAB_CONNECTED );
		}
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_gateway_host' ) );
		return Config::check_url( $this->gateway, $connection->id() );
	}

	/**
	 * Applies the Access chosen on flitagent.com's Approve page as the site's Access for AI tools, stored the way
	 * Settings and the setup guide store it, and still editable there. Pairing::complete() calls it after every check
	 * passes, right after checking it still holds the pairing lock: Read only before it stores the connection, Read and
	 * propose changes after (and Read only again if, once that is applied, the lock or the connection is no longer its
	 * own). It is not called without a choice (an older flitagent.com, a read grant), which leaves the setting
	 * as it is.
	 *
	 * Fails closed: a Read only choice that does not read back as off refuses the pairing (update_option() returns false
	 * for an unchanged value too, so its answer proves nothing). The read-back uses the same default as WpPolicy, so a
	 * site that never saved the setting reads as Read and propose changes, the way every write tool sees it. The cached
	 * copies are dropped before the write and before the read-back, so a stale cached "off" can neither skip the write
	 * nor pass the check. A Read and propose changes choice that fails to store leaves the setting as it was, which the
	 * administrator chose or the default.
	 *
	 * @param string $access `read` or `write`.
	 * @return bool Whether the setting now matches the choice.
	 */
	public static function apply_access( string $access ): bool {
		$write = Context::SCOPE_WRITE === $access;
		self::forget_cached_access();
		update_option( WpPolicy::OPTION_ENABLED, $write ? '1' : '0', true );
		self::forget_cached_access();
		return WpPolicy::is_on( get_option( WpPolicy::OPTION_ENABLED, WpPolicy::DEFAULT_ENABLED ) ) === $write;
	}

	/**
	 * Drops the object cache's copies of Access for AI tools, so the next read and write act on the database.
	 */
	private static function forget_cached_access(): void {
		wp_cache_delete( WpPolicy::OPTION_ENABLED, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * The plain message for a refusal code flitagent.com sent back; an unknown code reads as invalid_request.
	 *
	 * @param string $code Refusal code.
	 * @return string
	 */
	public static function error_message( string $code ): string {
		$messages = array(
			'cancelled'             => __( 'You cancelled connecting on flitagent.com. Nothing was connected.', 'flitagent' ),
			'site_cap_reached'      => __( 'Your flitagent.com account already has the most sites it can connect. Disconnect one on flitagent.com, then try again.', 'flitagent' ),
			'site_on_other_account' => __( 'This site is connected to another flitagent.com account. Disconnect it there first, or log in to that account.', 'flitagent' ),
			'invalid_request'       => __( 'flitagent.com could not accept this connection request. Start connecting again.', 'flitagent' ),
		);
		return in_array( $code, Pairing::ERRORS, true ) && isset( $messages[ $code ] ) ? $messages[ $code ] : $messages['invalid_request'];
	}

	/**
	 * Disconnect: deletes the connection (and any pending pairing) and removes the key refresh, then nudges flitagent.com
	 * with the connection the site held (none when it held none, or when the gateway URL is invalid, which the notice says).
	 *
	 * @param array<string, mixed> $post Sanitized POST fields (Request::post()).
	 * @return string URL to redirect to.
	 */
	public function disconnect( array $post ): string {
		$this->guard( $post, self::DISCONNECT );
		$held = $this->store->connection();
		$this->store->delete_connection();
		$this->store->delete_pending();
		$this->keys->sync_schedule();
		if ( null !== $held ) {
			$this->nudge( $held->id() );
		}
		$notice = __( 'Disconnected from flitagent.com. It can no longer call this site.', 'flitagent' );
		if ( null !== $held && '' === $this->gateway ) {
			$notice .= ' ' . __( 'flitagent.com was not told, because the address set for this site is not valid, so your account there may still list this site.', 'flitagent' );
		}
		self::notify( $notice );
		return self::connect_url();
	}

	/**
	 * Disconnect on an AI tool's row (#188): refuses that tool's apps on this site from now on and forgets them
	 * (Apps::block()). The site stays connected, and so do the other tools. A tool that was not disconnected yet nudges
	 * flitagent.com.
	 *
	 * @param array<string, mixed> $post Sanitized POST fields (Request::post()).
	 * @return string URL to redirect to.
	 */
	public function disconnect_app( array $post ): string {
		$this->guard( $post, '' );
		$tool = self::tool( $post );
		self::check_nonce( $post, self::DISCONNECT_APP . '_' . $tool );
		$back       = self::from_guide( $post ) ? Onboarding::url( 'connect' ) : self::connect_url();
		$connection = $this->store->connection();
		if ( null === $connection ) {
			self::notify( __( 'This site is not connected to flitagent.com, so no AI tool can reach it.', 'flitagent' ) );
			return $back;
		}
		$apps    = new Apps( $this->store );
		$changed = ! in_array( $tool, $apps->blocked(), true );
		$apps->block( $tool );
		if ( $changed ) {
			$this->nudge( $connection->id() );
		}
		/* translators: %s: an AI tool, such as ChatGPT. */
		self::notify( sprintf( __( '%s was disconnected from this site. To use it here again, set it up on this page.', 'flitagent' ), ConnectScreen::tool_label( $tool ) ) );
		return $back;
	}

	/**
	 * Set up on a disconnected AI tool's row (#188): allows the tool again (Apps::unblock()) and returns to the Connect
	 * screen (or the setup guide's Connect step it came from) with its Set up dialog open. A tool that was disconnected
	 * nudges flitagent.com while the site holds a connection.
	 *
	 * @param array<string, mixed> $post Sanitized POST fields (Request::post()).
	 * @return string URL to redirect to.
	 */
	public function setup_app( array $post ): string {
		$this->guard( $post, '' );
		$tool = self::tool( $post );
		self::check_nonce( $post, self::SETUP_APP . '_' . $tool );
		$apps    = new Apps( $this->store );
		$changed = in_array( $tool, $apps->blocked(), true );
		$apps->unblock( $tool );
		$connection = $this->store->connection();
		if ( $changed && null !== $connection ) {
			$this->nudge( $connection->id() );
		}
		return ConnectScreen::setup_url( $tool, self::from_guide( $post ) );
	}

	/**
	 * Sends the nudge, unless the gateway URL is invalid. Its answer is ignored: a nudge flitagent.com did not take changes
	 * nothing on the site, and it learns the state on its next signed request.
	 *
	 * @param string $connection_id Connection id.
	 */
	private function nudge( string $connection_id ): void {
		if ( '' === $this->gateway ) {
			return;
		}
		call_user_func( $this->nudge, $connection_id );
	}

	/**
	 * Whether a tool's form was posted from the setup guide (FROM). Only picks where to return.
	 *
	 * @param array<string, mixed> $post Sanitized POST fields (Request::post()).
	 * @return bool
	 */
	private static function from_guide( array $post ): bool {
		return isset( $post[ self::FROM ] ) && self::FROM_GUIDE === $post[ self::FROM ];
	}

	/**
	 * The AI tool a form names, or wp_die() for anything but Apps::TOOLS.
	 *
	 * @param array<string, mixed> $post Sanitized POST fields (Request::post()).
	 * @return string
	 */
	private static function tool( array $post ): string {
		// Request::post() read nothing: the form's nonce failed, so the tool is unknown only because of that.
		if ( array() === $post ) {
			self::link_expired();
		}
		$tool = isset( $post['tool'] ) && is_string( $post['tool'] ) ? $post['tool'] : '';
		if ( ! in_array( $tool, Apps::TOOLS, true ) ) {
			wp_die( esc_html__( 'Unknown AI tool.', 'flitagent' ), '', array( 'response' => 400 ) );
		}
		return $tool;
	}

	/**
	 * `allowed_redirect_hosts` while redirecting to the gateway: adds the configured gateway host.
	 *
	 * @param mixed $hosts Allowed hosts.
	 * @return array
	 */
	public function allow_gateway_host( $hosts ): array {
		$hosts = is_array( $hosts ) ? $hosts : array();
		$host  = '' === $this->gateway ? null : wp_parse_url( $this->gateway, PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? array_merge( $hosts, array( $host ) ) : $hosts;
	}

	/**
	 * Handles admin-post.php: Connect. Redirects to the gateway (its host allowed for this request only) or back.
	 */
	public function handle_connect(): void {
		$post = Request::post( array( '_wpnonce', 'scope', self::NEW_TAB ), self::CONNECT );
		$url  = $this->connect( $post );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_gateway_host' ) );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_admin_host' ) );
		wp_safe_redirect( self::in_tab( $this->reachable( $url, true ), $post ) );
		exit;
	}

	/**
	 * `allowed_redirect_hosts` for the handlers: adds this site's admin host (siteurl's), so the way back to the Connect
	 * screen works when siteurl and home are on different hosts (core allows only the home host).
	 *
	 * @param mixed $hosts Allowed hosts.
	 * @return array
	 */
	public function allow_admin_host( $hosts ): array {
		$hosts = is_array( $hosts ) ? $hosts : array();
		$host  = wp_parse_url( admin_url(), PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? array_merge( $hosts, array( $host ) ) : $hosts;
	}

	/**
	 * Where to send the browser. This site's own wp-admin URLs pass as they are. Any other URL (the gateway's pairing
	 * page or check page) passes when WordPress's redirect check accepts it; otherwise the browser goes to the Connect
	 * screen with a notice (wp_safe_redirect() would land on the Dashboard without a word), and a refused pairing URL also
	 * drops the pairing it started.
	 *
	 * @param string $url     URL from connect() or complete().
	 * @param bool   $pairing Whether the URL starts a pairing (Connect) rather than opening the check page (the return).
	 * @return string
	 */
	public function reachable( string $url, bool $pairing ): string {
		if ( 0 === strpos( $url, admin_url() ) || '' !== (string) wp_validate_redirect( $url, '' ) ) {
			return $url;
		}
		if ( $pairing ) {
			$this->store->delete_pending();
			/* translators: %s: the gateway URL. */
			self::notify( sprintf( __( 'WordPress refused to send your browser to the flitagent.com address set for this site (%s), so nothing was connected. Set FLITAGENT_GATEWAY_URL to an address on a host name.', 'flitagent' ), $this->gateway ) );
		} elseif ( null !== $this->store->connection() ) {
			/* translators: %s: the check page URL. */
			self::notify( sprintf( __( 'Connected to flitagent.com, but WordPress refused to open the check page. Open it yourself: %s', 'flitagent' ), $url ) );
		}
		return self::connect_url();
	}

	/**
	 * Handles admin-post.php: the gateway's return.
	 */
	public function handle_return(): void {
		$get = Request::query( array( '_wpnonce', 'flitagent_pairing_error', 'nonce', 'flitagent_pairing', 'flitagent_signature', self::NEW_TAB ), self::RETURN );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_admin_host' ) );
		wp_safe_redirect( $this->reachable( $this->complete( $get ), false ) );
		exit;
	}

	/**
	 * Handles admin-post.php: Disconnect.
	 */
	public function handle_disconnect(): void {
		$post = Request::post( array( '_wpnonce' ), self::DISCONNECT );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_admin_host' ) );
		wp_safe_redirect( $this->disconnect( $post ) );
		exit;
	}

	/**
	 * Handles admin-post.php: Disconnect on an AI tool's row.
	 */
	public function handle_disconnect_app(): void {
		$post = Request::post( array( '_wpnonce', 'tool', self::FROM ), ...self::tool_actions( self::DISCONNECT_APP ) );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_admin_host' ) );
		wp_safe_redirect( $this->disconnect_app( $post ) );
		exit;
	}

	/**
	 * Handles admin-post.php: Set up on a disconnected AI tool's row.
	 */
	public function handle_setup_app(): void {
		$post = Request::post( array( '_wpnonce', 'tool', self::FROM ), ...self::tool_actions( self::SETUP_APP ) );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_admin_host' ) );
		wp_safe_redirect( $this->setup_app( $post ) );
		exit;
	}

	/**
	 * The nonce actions a tool's form can carry, one per tool. disconnect_app() and setup_app() then check the one that
	 * names the posted tool, so one tool's nonce never acts on another.
	 *
	 * @param string $action The form's action.
	 * @return array<int, string>
	 */
	private static function tool_actions( string $action ): array {
		return array_map(
			static function ( string $tool ) use ( $action ): string {
				return $action . '_' . $tool;
			},
			Apps::TOOLS
		);
	}

	/**
	 * Refuses anything but a wp-admin session passing the access gate and, when an action is named, a valid nonce.
	 *
	 * @param array<string, mixed> $data   Request data.
	 * @param string               $action Nonce action, '' for none.
	 */
	private function guard( array $data, string $action ): void {
		if ( null !== rest_get_authenticated_app_password() || did_action( 'application_password_did_authenticate' ) ) {
			wp_die( esc_html__( 'This site cannot be connected to flitagent.com with an application password. Log in to wp-admin.', 'flitagent' ), '', array( 'response' => 403 ) );
		}
		if ( '' === (string) wp_get_session_token() ) {
			wp_die( esc_html__( 'This needs a logged-in wp-admin session.', 'flitagent' ), '', array( 'response' => 403 ) );
		}
		if ( ! Plugin::can_access_protected_tools() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'flitagent' ), '', array( 'response' => 403 ) );
		}
		if ( '' !== $action ) {
			self::check_nonce( $data, $action );
		}
	}

	/**
	 * Refuses a request without a valid nonce for the action (for a tool's Disconnect and Set up, the action names the
	 * tool, so one tool's nonce never acts on another).
	 *
	 * @param array<string, mixed> $data   Request data.
	 * @param string               $action Nonce action.
	 */
	private static function check_nonce( array $data, string $action ): void {
		$nonce = isset( $data['_wpnonce'] ) && is_string( $data['_wpnonce'] ) ? $data['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			self::link_expired();
		}
	}

	/**
	 * Refuses a form whose nonce failed.
	 */
	private static function link_expired(): void {
		wp_die( esc_html__( 'The link expired. Go back, reload the page and try again.', 'flitagent' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Where a request from the new tab goes (#198): any way back to the Connect screen (a failure, with its notice stored)
	 * becomes the closing page, so the tab that started connecting hears the outcome. Other URLs pass as they are.
	 *
	 * @param string               $url  URL from start() or finish().
	 * @param array<string, mixed> $data Sanitized request fields (Request).
	 * @return string
	 */
	private static function in_tab( string $url, array $data ): string {
		return self::in_new_tab( $data ) && self::connect_url() === $url ? ConnectScreen::tab_done_url( ConnectScreen::TAB_FAILED ) : $url;
	}

	/**
	 * Whether a request came from a connect form posted into a new tab (#198).
	 *
	 * @param array<string, mixed> $data Sanitized request fields (Request).
	 * @return bool
	 */
	private static function in_new_tab( array $data ): bool {
		return isset( $data[ self::NEW_TAB ] ) && '1' === $data[ self::NEW_TAB ];
	}

	/**
	 * The Connect screen, where every notice and failure is shown.
	 */
	private static function connect_url(): string {
		return admin_url( 'admin.php?page=' . ConnectScreen::SLUG );
	}

	/**
	 * Stores a one-time notice for the current user (shown by the Connect screen).
	 *
	 * @param string $message Message.
	 */
	private static function notify( string $message ): void {
		set_transient( 'flitagent_notice_' . get_current_user_id(), $message, 60 );
	}
}
