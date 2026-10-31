<?php
/**
 * Form handlers for wp-admin: save settings, the setup guide.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Handles `admin-post.php` requests. Every handler first requires: no application-password authentication, a cookie
 * session, `manage_options`, and a valid nonce for the exact action.
 */
final class Actions {

	/**
	 * Saves the FlitAgent settings.
	 *
	 * @param array<string, mixed> $post Unslashed POST data.
	 * @return string URL to redirect to.
	 */
	public function save_settings( array $post ): string {
		$this->guard( 'flitagent_settings', $post );

		update_option( Settings::OPTION_REMOVE_DATA, self::flag( $post, Settings::OPTION_REMOVE_DATA ), false );

		self::notify( __( 'Settings saved.', 'flitagent' ) );
		return admin_url( 'admin.php?page=flitagent-settings' );
	}

	/**
	 * Handles the setup guide's forms (#134): `do` = finish. Anything else, such as `skip` or `writes` from a page left open
	 * since those were removed, changes nothing and returns to Welcome.
	 *
	 * @param array<string, mixed> $post Unslashed POST data.
	 * @return string URL to redirect to.
	 */
	public function onboarding( array $post ): string {
		$this->guard( Onboarding::ACTION, $post );

		$do      = isset( $post['do'] ) && is_string( $post['do'] ) ? $post['do'] : '';
		$connect = admin_url( 'admin.php?page=' . ConnectScreen::SLUG );
		switch ( $do ) {
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
			wp_die( esc_html__( 'FlitAgent settings cannot be changed with an application password. Log in to wp-admin.', 'flitagent' ), '', array( 'response' => 403 ) );
		}
		if ( '' === (string) wp_get_session_token() ) {
			wp_die( esc_html__( 'This needs a logged-in wp-admin session.', 'flitagent' ), '', array( 'response' => 403 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'flitagent' ), '', array( 'response' => 403 ) );
		}
		$nonce = isset( $post['_wpnonce'] ) && is_string( $post['_wpnonce'] ) ? $post['_wpnonce'] : '';
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
