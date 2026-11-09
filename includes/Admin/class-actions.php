<?php
/**
 * Form handlers for wp-admin: approve, reject, save settings, the setup guide.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Gateway;
use FlitAgent\Writes\Wp\WpPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Handles `admin-post.php` requests. Every handler first requires: no application-password authentication, a cookie
 * session, `manage_options`, and a valid nonce for the exact action. This is the only place a change can be approved.
 */
final class Actions {

	/**
	 * Nonce action of the approve or reject form, the same for every change: it is what lets the handler read the form.
	 */
	const DECIDE = 'flitagent_decide';

	/**
	 * Field of the form's second nonce, for `DECIDE_<change>`, so a form for one change never acts on another.
	 */
	const CHANGE_NONCE = 'flitagent_change_nonce';

	/**
	 * Nonce action of the settings form.
	 */
	const SETTINGS = 'flitagent_settings';

	/**
	 * Gateway.
	 *
	 * @var Gateway
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param Gateway $gateway Gateway.
	 */
	public function __construct( Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Approves or rejects a change.
	 *
	 * @param array<string, mixed> $post Sanitized POST fields (Admin::decide_fields()).
	 * @return string URL to redirect to.
	 */
	public function decide( array $post ): string {
		$this->guard( self::DECIDE, $post );
		$change = isset( $post['change'] ) && is_string( $post['change'] ) ? $post['change'] : '';
		self::check_nonce( self::DECIDE . '_' . $change, $post, self::CHANGE_NONCE );

		$decision = isset( $post['decision'] ) && is_string( $post['decision'] ) ? $post['decision'] : '';
		if ( ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
			wp_die( esc_html__( 'Unknown decision.', 'flitagent' ), '', array( 'response' => 400 ) );
		}

		try {
			if ( 'approve' === $decision ) {
				$items = null;
				if ( isset( $post['plan_items'] ) ) {
					$items = array();
					foreach ( isset( $post['items'] ) && is_array( $post['items'] ) ? $post['items'] : array() as $item ) {
						if ( is_string( $item ) ) {
							$items[] = $item;
						}
					}
				}
				$status  = $this->gateway->approve( $change, get_current_user_id(), $items );
				$message = 'rejected' === $status
					? __( 'No changes were ticked, so this plan was rejected. Nothing was changed.', 'flitagent' )
					: __( 'Change approved. Nothing has been changed yet: the agent that requested it applies it with apply_change before the approval expires.', 'flitagent' );
			} else {
				$this->gateway->reject( $change, get_current_user_id() );
				$message = __( 'Change rejected. Nothing was changed.', 'flitagent' );
			}
		} catch ( ToolError $e ) {
			$message = $e->getMessage();
		}//end try

		self::notify( $message );
		return Screens::change_url( $change );
	}

	/**
	 * Saves the FlitAgent settings.
	 *
	 * @param array<string, mixed> $post Sanitized POST fields (Admin::settings_fields()).
	 * @return string URL to redirect to.
	 */
	public function save_settings( array $post ): string {
		$this->guard( self::SETTINGS, $post );

		$eligible = $this->gateway->auto_approvable_tools();
		$posted   = isset( $post[ WpPolicy::OPTION_AUTO ] ) && is_array( $post[ WpPolicy::OPTION_AUTO ] ) ? $post[ WpPolicy::OPTION_AUTO ] : array();
		$auto     = array_values( array_intersect( $eligible, array_filter( $posted, 'is_string' ) ) );

		update_option( WpPolicy::OPTION_ENABLED, self::flag( $post, WpPolicy::OPTION_ENABLED ), true );
		update_option( WpPolicy::OPTION_AUTO, $auto, true );
		update_option( WpPolicy::OPTION_TTL, WpPolicy::clamp_minutes( isset( $post[ WpPolicy::OPTION_TTL ] ) ? $post[ WpPolicy::OPTION_TTL ] : null ), true );
		update_option( WpPolicy::OPTION_REMOVE_DATA, self::flag( $post, WpPolicy::OPTION_REMOVE_DATA ), false );

		self::notify( __( 'Settings saved.', 'flitagent' ) );
		return admin_url( 'admin.php?page=flitagent-settings' );
	}

	/**
	 * Handles the setup guide's forms (#134): `do` = writes (with `mode` = write or read) or finish. Anything else, such as
	 * `skip` from a page left open since Skip setup was removed (#209), changes nothing and returns to Welcome.
	 *
	 * @param array<string, mixed> $post Sanitized POST fields (Admin::onboarding_fields()).
	 * @return string URL to redirect to.
	 */
	public function onboarding( array $post ): string {
		$this->guard( Onboarding::ACTION, $post );

		$do      = isset( $post['do'] ) && is_string( $post['do'] ) ? $post['do'] : '';
		$connect = admin_url( 'admin.php?page=' . ConnectScreen::SLUG );
		switch ( $do ) {
			case 'writes':
				$write = isset( $post['mode'] ) && 'write' === $post['mode'];
				update_option( WpPolicy::OPTION_ENABLED, $write ? '1' : '0', true );
				return Onboarding::url( 'done' );
			case 'finish':
				Onboarding::set_status( 'done' );
				return $connect;
			default:
				return Onboarding::url( 'welcome' );
		}
	}

	/**
	 * Refuses anything but a human in a wp-admin session with `manage_options` and a valid nonce.
	 *
	 * Application passwords authenticate only API requests, and a cookie session takes precedence over them, so a
	 * request authenticated by an application password has no session token; both are checked.
	 *
	 * @param string               $action Nonce action.
	 * @param array<string, mixed> $post   POST data.
	 */
	private function guard( string $action, array $post ): void {
		if ( null !== rest_get_authenticated_app_password() || did_action( 'application_password_did_authenticate' ) ) {
			wp_die( esc_html__( 'Changes cannot be approved, and FlitAgent settings cannot be changed, with an application password. Log in to wp-admin.', 'flitagent' ), '', array( 'response' => 403 ) );
		}
		if ( '' === (string) wp_get_session_token() ) {
			wp_die( esc_html__( 'This needs a logged-in wp-admin session.', 'flitagent' ), '', array( 'response' => 403 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'flitagent' ), '', array( 'response' => 403 ) );
		}
		self::check_nonce( $action, $post );
	}

	/**
	 * Refuses a request without a valid nonce for the action.
	 *
	 * @param string               $action Nonce action.
	 * @param array<string, mixed> $post   POST fields.
	 * @param string               $field  Field holding the nonce.
	 */
	private static function check_nonce( string $action, array $post, string $field = '_wpnonce' ): void {
		$nonce = isset( $post[ $field ] ) && is_string( $post[ $field ] ) ? $post[ $field ] : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'The link expired. Go back, reload the page and try again.', 'flitagent' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * "1" when a checkbox was ticked, otherwise "0".
	 *
	 * @param array<string, mixed> $post POST data.
	 * @param string               $name Field name.
	 * @return string
	 */
	private static function flag( array $post, string $name ): string {
		return isset( $post[ $name ] ) && '1' === $post[ $name ] ? '1' : '0';
	}

	/**
	 * Stores a one-time notice for the current user.
	 *
	 * @param string $message Message.
	 */
	private static function notify( string $message ): void {
		set_transient( 'flitagent_notice_' . get_current_user_id(), $message, 60 );
	}
}
