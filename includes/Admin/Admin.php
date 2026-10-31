<?php
/**
 * The FlitAgent menu in wp-admin.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

use FlitAgent\Auth\Gateway\WpStore as GatewayStore;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the FlitAgent menu (Connect, Settings; `manage_options`), the hidden setup guide, its one-time
 * redirect and the admin-post handlers.
 */
final class Admin {

	/**
	 * Capability for every screen and action.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Style and script handle of the FlitAgent screens (assets/css/admin.css, assets/js/admin.js), as in 1.0.0.
	 */
	const STYLE = 'flitagent-admin';

	/**
	 * Menu slugs of version 1.0.0's Approvals and Activity screens, which redirect to Connect.
	 */
	const LEGACY_SLUGS = array( 'flitagent-approvals', 'flitagent-activity' );

	/**
	 * Hook suffixes of the FlitAgent screens, as add_menu_page() and add_submenu_page() returned them (a submenu's
	 * suffix starts with the sanitized parent menu title).
	 *
	 * @var array<int, string>
	 */
	private static $hooks = array();

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_post_flitagent_settings', array( self::class, 'save_settings' ) );
		add_action( 'admin_init', array( self::class, 'onboarding_redirect' ) );
		// Fires just before wp-admin refuses an unregistered page; admin_init comes too late for the old slugs.
		add_action( 'admin_page_access_denied', array( self::class, 'legacy_redirect' ) );
		add_action( 'admin_post_' . Onboarding::ACTION, array( self::class, 'save_onboarding' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FLITAGENT_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Adds the menu: FlitAgent (the Connect screen) with Connect and Settings, and the hidden setup guide.
	 */
	public static function menu(): void {
		// No parent and no menu title: reachable at admin.php?page=flitagent-onboarding, not in the menu.
		$guide = add_submenu_page( '', __( 'Set up FlitAgent', 'flitagent' ), '', self::CAPABILITY, Onboarding::SLUG, array( self::class, 'onboarding' ) );
		if ( is_string( $guide ) ) {
			add_action( 'load-' . $guide, array( self::class, 'onboarding_load' ) );
		}

		self::$hooks = array_values(
			array_filter(
				array(
					add_menu_page( __( 'Connect to flitagent.com', 'flitagent' ), __( 'FlitAgent', 'flitagent' ), self::CAPABILITY, ConnectScreen::SLUG, array( self::class, 'connect' ), 'dashicons-shield', 81 ),
					add_submenu_page( ConnectScreen::SLUG, __( 'Connect to flitagent.com', 'flitagent' ), __( 'Connect', 'flitagent' ), self::CAPABILITY, ConnectScreen::SLUG, array( self::class, 'connect' ) ),
					add_submenu_page( ConnectScreen::SLUG, __( 'FlitAgent settings', 'flitagent' ), __( 'Settings', 'flitagent' ), self::CAPABILITY, Settings::SLUG, array( self::class, 'settings' ) ),
					$guide,
				),
				'is_string'
			)
		);
	}

	/**
	 * Runs when the setup guide loads (`load-{hook}`). The page has no menu entry, so WordPress knows neither its title
	 * nor which menu to highlight: set the title and highlight FlitAgent → Connect.
	 */
	public static function onboarding_load(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the admin page title core reads in admin-header.php; a hidden page has none.
		$GLOBALS['title'] = __( 'Set up FlitAgent', 'flitagent' );
		add_filter( 'parent_file', array( self::class, 'onboarding_parent_file' ) );
		add_filter( 'submenu_file', array( self::class, 'onboarding_submenu_file' ) );
		add_action( 'admin_head', array( self::class, 'onboarding_unlist' ) );
	}

	/**
	 * Takes the setup guide out of the submenu list once WordPress has checked access to it. Otherwise
	 * get_admin_page_parent(), which menu-header.php calls after the parent_file filter, finds it under the empty parent
	 * and clears the menu highlight. Runs on `admin_head`, before the menu is drawn.
	 */
	public static function onboarding_unlist(): void {
		remove_submenu_page( '', Onboarding::SLUG );
	}

	/**
	 * Highlights the FlitAgent menu on the setup guide. Runs on `parent_file`, replacing core's choice.
	 *
	 * @return string
	 */
	public static function onboarding_parent_file(): string {
		return ConnectScreen::SLUG;
	}

	/**
	 * Highlights FlitAgent → Connect on the setup guide. Runs on `submenu_file`, replacing core's choice.
	 *
	 * @return string
	 */
	public static function onboarding_submenu_file(): string {
		return ConnectScreen::SLUG;
	}

	/**
	 * Adds the admin scripts and styles (see Assets) on FlitAgent's screens only, versioned with the plugin: the tokens,
	 * then admin.css; the five scripts, then admin.js, which attaches them; all scripts in the footer. Runs on
	 * `admin_enqueue_scripts`.
	 *
	 * @param string $hook_suffix Hook suffix of the current admin page.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( ! is_string( $hook_suffix ) || '' === $hook_suffix || ! in_array( $hook_suffix, self::$hooks, true ) ) {
			return;
		}
		wp_register_style( Assets::TOKENS, Assets::url( 'css/tokens.css' ), array(), FLITAGENT_VERSION );
		wp_register_style( self::STYLE, Assets::url( 'css/admin.css' ), array( Assets::TOKENS ), FLITAGENT_VERSION );
		wp_enqueue_style( self::STYLE );
		foreach ( Assets::SCRIPTS as $handle => $file ) {
			wp_register_script( $handle, Assets::url( $file ), array(), FLITAGENT_VERSION, true );
		}
		wp_register_script( self::STYLE, Assets::url( 'js/admin.js' ), array_keys( Assets::SCRIPTS ), FLITAGENT_VERSION, true );
		wp_enqueue_script( self::STYLE );
	}

	/**
	 * Connect screen.
	 */
	public static function connect(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ConnectScreen escapes every value.
		echo ConnectScreen::render();
	}

	/**
	 * Setup guide (#134).
	 */
	public static function onboarding(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view; the guide's forms are POSTed with a nonce.
		$query = wp_unslash( $_GET );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- OnboardingScreen escapes every value.
		echo OnboardingScreen::render( is_array( $query ) ? $query : array() );
	}

	/**
	 * Sends an administrator to the setup guide once after a new install. Runs on `admin_page_access_denied`, just before core's "not allowed" error.
	 */
	public static function onboarding_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only decides whether to redirect.
		$query = wp_unslash( $_GET );
		$url   = self::onboarding_redirect_url( is_array( $query ) ? $query : array() );
		if ( null !== $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Where to redirect this request to, once, or null (see Onboarding::redirect_target()).
	 *
	 * @param array<string, mixed> $query Unslashed GET data.
	 * @return string|null
	 */
	public static function onboarding_redirect_url( array $query ): ?string {
		return Onboarding::redirect_target( $query );
	}

	/**
	 * Adds Connect first among FlitAgent's links on the Plugins screen. Runs on `plugin_action_links_{file}`.
	 *
	 * @param array<string, string> $links Action links.
	 * @return array<string, string>
	 */
	public static function action_links( array $links ): array {
		return array_merge(
			array( 'connect' => '<a href="' . esc_url( admin_url( 'admin.php?page=' . ConnectScreen::SLUG ) ) . '">' . esc_html__( 'Connect', 'flitagent' ) . '</a>' ),
			$links
		);
	}

	/**
	 * Settings screen.
	 */
	public static function settings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- DiagnosticsScreen::render() checks the capability and nonce before running anything.
		$post = wp_unslash( $_POST );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Settings and DiagnosticsScreen escape every value.
		echo Settings::html( DiagnosticsScreen::render( is_array( $post ) ? $post : array() ) );
	}

	/**
	 * Sends a request for a screen of version 1.0.0 that no longer exists (Approvals, Activity) to Connect, so a bookmark
	 * does not end on "Sorry, you are not allowed to access this page". Runs on `admin_init`.
	 */
	public static function legacy_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only decides whether to redirect.
		$query = wp_unslash( $_GET );
		$url   = self::legacy_redirect_url( is_array( $query ) ? $query : array() );
		if ( null !== $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Where to send a request for an old screen, or null for any other request.
	 *
	 * @param array<string, mixed> $query Unslashed GET data.
	 * @return string|null
	 */
	public static function legacy_redirect_url( array $query ): ?string {
		$page = $query['page'] ?? null;
		if ( ! is_string( $page ) || ! in_array( $page, self::LEGACY_SLUGS, true ) || ! current_user_can( self::CAPABILITY ) ) {
			return null;
		}
		return admin_url( 'admin.php?page=' . ConnectScreen::SLUG );
	}

	/**
	 * Handles admin-post.php: save settings.
	 */
	public static function save_settings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Actions::save_settings() verifies the nonce first and sanitizes each field.
		$post = wp_unslash( $_POST );
		wp_safe_redirect( ( new Actions() )->save_settings( is_array( $post ) ? $post : array() ) );
		exit;
	}

	/**
	 * Handles admin-post.php: the setup guide's forms.
	 */
	public static function save_onboarding(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Actions::onboarding() verifies the nonce first and checks each field.
		$post = wp_unslash( $_POST );
		wp_safe_redirect( ( new Actions() )->onboarding( is_array( $post ) ? $post : array() ) );
		exit;
	}
}
