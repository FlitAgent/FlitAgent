<?php
/**
 * The FlitAgent menu in wp-admin.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

use FlitAgent\Auth\Gateway\WpStore as GatewayStore;
use FlitAgent\Writes\Store;
use FlitAgent\Writes\Wp\Factory;
use FlitAgent\Writes\Wp\Tables;
use FlitAgent\Writes\Wp\WpClock;
use FlitAgent\Writes\Wp\WpPolicy;
use FlitAgent\Writes\Wp\WpStore;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the FlitAgent menu (Approvals, Activity, Settings; `manage_options`), the hidden setup guide, its one-time
 * redirect and the admin-post handlers.
 */
final class Admin {

	/**
	 * Capability for every screen and action.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Style and script handle of the FlitAgent screens (build/admin.css, build/admin.js, compiled from src/).
	 */
	const STYLE = 'flitagent-admin';



	/**
	 * Hook suffixes of the FlitAgent screens, as add_menu_page() and add_submenu_page() returned them (a submenu's
	 * suffix starts with the sanitized menu title, which includes the pending-count badge).
	 *
	 * @var array<int, string>
	 */
	private static $hooks = array();

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( Tables::class, 'maybe_upgrade' ) );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_post_flitagent_decide', array( self::class, 'decide' ) );
		add_action( 'admin_post_flitagent_settings', array( self::class, 'save_settings' ) );
		add_action( 'admin_init', array( self::class, 'onboarding_redirect' ) );
		// Fires just before wp-admin refuses an unregistered page; admin_init never runs for one.
		add_action( 'admin_page_access_denied', array( self::class, 'change_link' ) );
		add_action( 'admin_post_' . Onboarding::ACTION, array( self::class, 'save_onboarding' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FLITAGENT_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Adds the menu, with a count of pending changes while writes are on (only for users who can open Approvals).
	 */
	public static function menu(): void {
		$title = __( 'FlitAgent', 'flitagent' );
		if ( current_user_can( self::CAPABILITY ) && ( new WpPolicy() )->writes_enabled() ) {
			$pending = Screens::waiting( new WpStore(), ( new WpClock() )->now() );
			if ( $pending > 0 ) {
				$title .= ' <span class="awaiting-mod">' . esc_html( number_format_i18n( $pending ) ) . '</span>';
			}
		}

		// No parent and no menu title: reachable at admin.php?page=flitagent-onboarding, not in the menu.
		$guide = add_submenu_page( '', __( 'Set up FlitAgent', 'flitagent' ), '', self::CAPABILITY, Onboarding::SLUG, array( self::class, 'onboarding' ) );
		if ( is_string( $guide ) ) {
			add_action( 'load-' . $guide, array( self::class, 'onboarding_load' ) );
		}

		self::$hooks = array_values(
			array_filter(
				array(
					add_menu_page( __( 'FlitAgent approvals', 'flitagent' ), $title, self::CAPABILITY, 'flitagent-approvals', array( self::class, 'approvals' ), 'dashicons-shield', 81 ),
					add_submenu_page( 'flitagent-approvals', __( 'FlitAgent approvals', 'flitagent' ), __( 'Approvals', 'flitagent' ), self::CAPABILITY, 'flitagent-approvals', array( self::class, 'approvals' ) ),
					add_submenu_page( 'flitagent-approvals', __( 'Connect to flitagent.com', 'flitagent' ), __( 'Connect', 'flitagent' ), self::CAPABILITY, ConnectScreen::SLUG, array( self::class, 'connect' ) ),
					add_submenu_page( 'flitagent-approvals', __( 'FlitAgent activity', 'flitagent' ), __( 'Activity', 'flitagent' ), self::CAPABILITY, 'flitagent-activity', array( self::class, 'activity' ) ),
					add_submenu_page( 'flitagent-approvals', __( 'FlitAgent settings', 'flitagent' ), __( 'Settings', 'flitagent' ), self::CAPABILITY, 'flitagent-settings', array( self::class, 'settings' ) ),
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
		return 'flitagent-approvals';
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
	 * Adds the compiled admin script and styles (see Assets) on FlitAgent's screens only. Runs on `admin_enqueue_scripts`.
	 *
	 * @param string $hook_suffix Hook suffix of the current admin page.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( ! is_string( $hook_suffix ) || '' === $hook_suffix || ! in_array( $hook_suffix, self::$hooks, true ) ) {
			return;
		}
		$asset = Assets::meta( 'admin' );
		wp_register_style( self::STYLE, Assets::url( 'admin.css' ), array(), $asset['version'] );
		wp_enqueue_style( self::STYLE );
		wp_register_script( self::STYLE, Assets::url( 'admin.js' ), $asset['dependencies'], $asset['version'], true );
		wp_enqueue_script( self::STYLE );
	}

	/**
	 * Approvals screen.
	 */
	public static function approvals(): void {
		echo wp_kses( self::screens()->approvals( Request::query( array( 'change' ) ) ), Ui::allowed_html() );
	}

	/**
	 * Connect screen.
	 */
	public static function connect(): void {
		echo wp_kses( ConnectScreen::render(), Ui::allowed_html() );
	}

	/**
	 * Setup guide (#134).
	 */
	public static function onboarding(): void {
		echo wp_kses( OnboardingScreen::render( Request::query( array( 'step', 'setup', ConnectScreen::DONE, 'run' ) ) ), Ui::allowed_html() );
	}

	/**
	 * Sends an administrator to the setup guide once after a new install. Runs on `admin_init`.
	 */
	public static function onboarding_redirect(): void {
		$url = self::onboarding_redirect_url();
		if ( null !== $url ) {
			add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_admin_host' ) );
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Where to redirect this request to, once, or null (see Onboarding::redirect_target()).
	 *
	 * @return string|null
	 */
	public static function onboarding_redirect_url(): ?string {
		return Onboarding::redirect_target();
	}

	/**
	 * Sends the link an AI app was given for a change (WpPolicy::approve_url()) on to that change. Runs on
	 * `admin_page_access_denied`. The link carries no nonce, so this reads core's `$plugin_page` and never the request.
	 */
	public static function change_link(): void {
		global $plugin_page;
		$url = self::change_link_url( $plugin_page, new WpStore() );
		if ( null !== $url ) {
			add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_admin_host' ) );
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Where a change's link goes: the change, with a nonce made for this session, or the Approvals list for a change the
	 * site does not have. Null, so core refuses as usual, for any other page name and for a user who cannot open Approvals,
	 * whether or not the change exists.
	 *
	 * @param mixed $page  The page asked for (core's `$plugin_page`).
	 * @param Store $store Store.
	 * @return string|null
	 */
	public static function change_link_url( $page, Store $store ): ?string {
		if ( ! is_string( $page ) || 1 !== preg_match( '/^' . preg_quote( WpPolicy::CHANGE_PAGE, '/' ) . '([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/D', $page, $match ) ) {
			return null;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return null;
		}
		return null === $store->change( $match[1] ) ? admin_url( 'admin.php?page=flitagent-approvals' ) : Screens::change_url( $match[1] );
	}

	/**
	 * `allowed_redirect_hosts` for the handlers: adds this site's admin host (siteurl's), so they still reach their screen
	 * when siteurl and home are on different hosts (core allows only the home host, and sends anything else to the Dashboard).
	 *
	 * @param mixed $hosts Allowed hosts.
	 * @return array
	 */
	public static function allow_admin_host( $hosts ): array {
		$hosts = is_array( $hosts ) ? $hosts : array();
		$host  = wp_parse_url( admin_url(), PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? array_merge( $hosts, array( $host ) ) : $hosts;
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
	 * Activity screen.
	 */
	public static function activity(): void {
		echo wp_kses( self::screens()->activity( Request::query( array( 'tool', 'status', 'paged' ) ) ), Ui::allowed_html() );
	}

	/**
	 * Settings screen.
	 */
	public static function settings(): void {
		$post = Request::post( array( DiagnosticsScreen::ACTION, '_wpnonce' ), DiagnosticsScreen::ACTION );
		if ( array() === $post && Request::is_post() ) {
			// This screen's only POST form failed its nonce: the check below answers that the link expired.
			$post = array( DiagnosticsScreen::ACTION => '1' );
		}
		$connection = ( new GatewayStore() )->connection();
		echo wp_kses( self::screens()->settings( DiagnosticsScreen::render( $post ), null === $connection ? '' : $connection->scope() ), Ui::allowed_html() );
	}

	/**
	 * Handles admin-post.php: approve or reject.
	 */
	public static function decide(): void {
		add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_admin_host' ) );
		wp_safe_redirect( ( new Actions( Factory::gateway() ) )->decide( self::decide_fields() ) );
		exit;
	}

	/**
	 * Handles admin-post.php: save settings.
	 */
	public static function save_settings(): void {
		add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_admin_host' ) );
		wp_safe_redirect( ( new Actions( Factory::gateway() ) )->save_settings( self::settings_fields() ) );
		exit;
	}

	/**
	 * Handles admin-post.php: the setup guide's forms.
	 */
	public static function save_onboarding(): void {
		add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_admin_host' ) );
		wp_safe_redirect( ( new Actions( Factory::gateway() ) )->onboarding( self::onboarding_fields() ) );
		exit;
	}

	/**
	 * The approve or reject form's fields. Its nonce names no change, so it can be checked before the change is read;
	 * Actions::decide() then checks the second nonce, which is bound to that change.
	 *
	 * @return array<string, mixed> Sanitized POST fields.
	 */
	public static function decide_fields(): array {
		$post = Request::post( array( '_wpnonce', Actions::CHANGE_NONCE, 'change', 'decision', 'plan_items' ), Actions::DECIDE );
		if ( isset( $post['plan_items'] ) ) {
			$post['items'] = Request::post_list( 'items', Actions::DECIDE );
		}
		return $post;
	}

	/**
	 * The settings form's fields.
	 *
	 * @return array<string, mixed> Sanitized POST fields.
	 */
	public static function settings_fields(): array {
		$post = Request::post( array( '_wpnonce', WpPolicy::OPTION_ENABLED, WpPolicy::OPTION_TTL, WpPolicy::OPTION_REMOVE_DATA ), Actions::SETTINGS );
		if ( array() !== $post ) {
			$post[ WpPolicy::OPTION_AUTO ] = Request::post_list( WpPolicy::OPTION_AUTO, Actions::SETTINGS );
		}
		return $post;
	}

	/**
	 * The setup guide's form fields.
	 *
	 * @return array<string, string> Sanitized POST fields.
	 */
	public static function onboarding_fields(): array {
		return Request::post( array( '_wpnonce', 'do', 'mode' ), Onboarding::ACTION );
	}

	/**
	 * Screens for this request.
	 *
	 * @return Screens
	 */
	private static function screens(): Screens {
		return new Screens( Factory::gateway(), new WpStore(), new WpClock() );
	}
}
