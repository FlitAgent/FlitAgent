<?php
/**
 * First-run setup guide (#134): install record, one-time redirect, status.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks the installation in the `flitagent_install` option and decides when to show the setup guide.
 */
final class Onboarding {

	/**
	 * Option holding the install record: installed_at (int), version (string), onboarding (pending or done; `skipped`
	 * from versions that had Skip setup, read as finished) and redirect (bool).
	 */
	const OPTION = 'flitagent_install';

	/**
	 * Menu slug of the setup guide.
	 */
	const SLUG = 'flitagent-onboarding';

	/**
	 * Steps, in order (#209: Connect covers connecting the site and setting up the AI tools; the beta has no step for changes).
	 */
	const STEPS = array( 'welcome', 'check', 'connect', 'done' );

	/**
	 * Steps that no longer exist, and the step that took their place, so an old link or bookmark still lands on it.
	 */
	const RENAMED = array(
		'app'     => 'connect',
		'changes' => 'done',
	);

	/**
	 * Nonce action and admin-post action of the setup guide's forms.
	 */
	const ACTION = 'flitagent_onboarding';

	/**
	 * URL of a step of the setup guide.
	 *
	 * @param string $step One of STEPS.
	 * @param bool   $run  For the check step: run the checks on a local site, where they do not run by themselves.
	 * @return string
	 */
	public static function url( string $step = 'welcome', bool $run = false ): string {
		$step = self::step( $step );
		return admin_url( 'admin.php?page=' . self::SLUG . '&step=' . $step . ( $run && 'check' === $step ? '&run=1' : '' ) );
	}

	/**
	 * A known step: one of STEPS, a removed step's replacement (RENAMED), or welcome for anything else.
	 *
	 * @param mixed $step Step asked for.
	 * @return string One of STEPS.
	 */
	public static function step( $step ): string {
		if ( ! is_string( $step ) ) {
			return 'welcome';
		}
		$step = self::RENAMED[ $step ] ?? $step;
		return in_array( $step, self::STEPS, true ) ? $step : 'welcome';
	}

	/**
	 * Activation: records the install once, as `pending` with a one-time redirect (none for a network-wide activation).
	 * Reactivation changes nothing. Uninstall deletes the record only with "Remove data on uninstall" on, so only then is a
	 * reinstall new; otherwise the kept record skips the setup guide.
	 *
	 * @param bool $network_wide Whether the plugin is network-activated.
	 */
	public static function activate( $network_wide = false ): void {
		if ( false !== get_option( self::OPTION ) ) {
			return;
		}
		add_option(
			self::OPTION,
			array(
				'installed_at' => time(),
				'version'      => FLITAGENT_VERSION,
				'onboarding'   => 'pending',
				'redirect'     => ! $network_wide,
			),
			'',
			false
		);
	}

	/**
	 * The install record.
	 *
	 * @return array|null Null when missing or malformed.
	 */
	public static function state(): ?array {
		$state = get_option( self::OPTION );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Decides the one-time redirect on `admin_init`. Redirects an administrator once and clears the flag. Bulk
	 * activation and the network admin clear the flag without redirecting; AJAX, WP-CLI and users without
	 * `manage_options` keep it for a later page load.
	 *
	 * @param array $query The request's query arguments.
	 * @return string|null The setup guide's URL, or null for no redirect.
	 */
	public static function redirect_target( array $query ): ?string {
		$state = self::state();
		if ( null === $state || empty( $state['redirect'] ) ) {
			return null;
		}
		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		$state['redirect'] = false;
		update_option( self::OPTION, $state, false );
		if ( array_key_exists( 'activate-multi', $query ) || is_network_admin() ) {
			return null;
		}
		return self::url( 'welcome' );
	}

	/**
	 * Records how the setup guide ended and turns off the redirect. Creates the record when missing.
	 *
	 * @param string $status `done`; anything else is ignored (#209: Skip setup is gone, so nothing records `skipped`).
	 */
	public static function set_status( string $status ): void {
		if ( 'done' !== $status ) {
			return;
		}
		$state = self::state();
		if ( null === $state ) {
			$state = array(
				'installed_at' => time(),
				'version'      => FLITAGENT_VERSION,
			);
		}
		$state['onboarding'] = $status;
		$state['redirect']   = false;
		update_option( self::OPTION, $state, false );
	}
}
