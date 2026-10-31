<?php
/**
 * The FlitAgent → Connect screen.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

use FlitAgent\Auth\Context;
use FlitAgent\Auth\Gateway\AdminSection;
use FlitAgent\Auth\Gateway\Apps;
use FlitAgent\Auth\Gateway\Client;
use FlitAgent\Auth\Gateway\Config;
use FlitAgent\Auth\Gateway\Keys;
use FlitAgent\Auth\Gateway\Pairing;
use FlitAgent\Auth\Gateway\StatusNudge;
use FlitAgent\Auth\Gateway\WpStore;

defined( 'ABSPATH' ) || exit;

/**
 * Connects this site to flitagent.com and tells an administrator how to add flitagent.com to an AI app: the state of the
 * pairing (not connected, waiting, connected) with its actions, then the MCP address with a Copy button and the guides.
 * Opening the screen sends nothing to flitagent.com unless the site is connected (then it refreshes the gateway's key
 * set). Every dynamic value is escaped here, so callers may echo it.
 */
final class ConnectScreen {

	/**
	 * Menu slug.
	 */
	const SLUG = 'flitagent-connect';

	/**
	 * Query arg of the new tab's way back after connecting (#198): TAB_CONNECTED or TAB_FAILED.
	 */
	const CLOSING = 'flitagent_tab_done';

	/**
	 * The new tab's outcomes (#198).
	 */
	const TAB_CONNECTED = 'connected';
	const TAB_FAILED    = 'failed';

	/**
	 * Query arg naming the tool whose "is connected" dialog to show (tool-status.js reloads with it once the tool it was
	 * waiting for reads Connected).
	 */
	const DONE = 'flitagent_connected';

	/**
	 * Attribute of a link that opens an AI tool's site: tool-status.js opens it in a tab it keeps a handle to (named
	 * `flitagent-<tool>`), so it can close that tab once the tool reads Connected.
	 */
	const OPEN_TOOL = 'data-flitagent-open-tool';

	/**
	 * ChatGPT's Plugins page, where + then "Add custom MCP server" adds one (OpenAI's guide, developers.openai.com/api/docs/guides/custom-mcp-server).
	 * ChatGPT has no link that opens that form filled in.
	 */
	const CHATGPT_PLUGINS = 'https://chatgpt.com/plugins';

	/**
	 * Claude's "Add custom connector" dialog, which Anthropic documents opening filled in from these query args (see
	 * claude_add_url()).
	 */
	const CLAUDE_ADD_CONNECTOR = 'https://claude.ai/customize/connectors?modal=add-custom-connector';

	/**
	 * A new ChatGPT chat and a new Claude chat with the message box filled in from `q`: the "is connected" dialog's Try
	 * now (the first question, URL-encoded, follows).
	 */
	const CHATGPT_ASK = 'https://chatgpt.com/?q=';
	const CLAUDE_ASK  = 'https://claude.ai/new?q=';

	/**
	 * The name an AI tool gives FlitAgent, prefilled in Claude and offered to copy.
	 */
	const CONNECTOR_NAME = 'FlitAgent';

	/**
	 * The name of an AI tool (Apps::TOOLS), translated.
	 *
	 * @param string $tool Tool.
	 * @return string
	 */
	public static function tool_label( string $tool ): string {
		$labels = array(
			Apps::CHATGPT => __( 'ChatGPT', 'flitagent' ),
			Apps::CLAUDE  => __( 'Claude', 'flitagent' ),
			Apps::MANUAL  => __( 'Manual configuration', 'flitagent' ),
		);
		return $labels[ $tool ] ?? $tool;
	}

	/**
	 * The Connect screen, or the setup guide's Connect step, with a tool's Set up dialog open (on the page itself without
	 * JavaScript).
	 *
	 * @param string $tool  Tool (Apps::TOOLS).
	 * @param bool   $guide Whether the setup guide's Connect step rather than the Connect screen.
	 * @return string
	 */
	public static function setup_url( string $tool, bool $guide = false ): string {
		$page = $guide ? Onboarding::url( 'connect' ) : admin_url( 'admin.php?page=' . self::SLUG );
		return $page . '&setup=' . rawurlencode( $tool ) . '#flitagent-setup-' . rawurlencode( $tool );
	}

	/**
	 * The screen for this site.
	 *
	 * @return string
	 */
	public static function render(): string {
		StatusNudge::on_view( new WpStore(), time() );
		return self::html(
			array_merge(
				self::facts( true ),
				array(
					'setup'   => self::requested_setup(),
					'closing' => self::requested_closing(),
					'done'    => self::requested_done(),
				)
			)
		);
	}

	/**
	 * The Connect screen as the new tab's way back (#198): its script tells the tab that started connecting, which shows
	 * the stored notice, and closes this one.
	 *
	 * @param string $outcome TAB_CONNECTED or TAB_FAILED.
	 * @return string
	 */
	public static function tab_done_url( string $outcome ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&' . self::CLOSING . '=' . ( self::TAB_CONNECTED === $outcome ? self::TAB_CONNECTED : self::TAB_FAILED ) );
	}

	/**
	 * The new tab's outcome the request carries (`&flitagent_tab_done=`), or ''. Only shows content; changes nothing.
	 *
	 * @return string
	 */
	public static function requested_closing(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: picks the closing message.
		$outcome = isset( $_GET[ self::CLOSING ] ) && is_string( $_GET[ self::CLOSING ] ) ? sanitize_key( wp_unslash( $_GET[ self::CLOSING ] ) ) : '';
		return in_array( $outcome, array( self::TAB_CONNECTED, self::TAB_FAILED ), true ) ? $outcome : '';
	}

	/**
	 * The tool whose Set up dialog the request asks to show (`&setup=<tool>`, from a Set up link without JavaScript or
	 * after Set up allowed a disconnected tool again), or ''. Only shows content; changes nothing.
	 *
	 * @return string
	 */
	public static function requested_setup(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: picks which dialog starts open.
		return self::setup_tool( isset( $_GET['setup'] ) && is_string( $_GET['setup'] ) ? sanitize_key( wp_unslash( $_GET['setup'] ) ) : '' );
	}

	/**
	 * The tool whose "is connected" dialog the request asks to show (`&flitagent_connected=<tool>`), or ''. Only shows
	 * content; changes nothing. The dialog shows only while that tool is connected.
	 *
	 * @return string
	 */
	public static function requested_done(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: picks which dialog starts open.
		return self::setup_tool( isset( $_GET[ self::DONE ] ) && is_string( $_GET[ self::DONE ] ) ? sanitize_key( wp_unslash( $_GET[ self::DONE ] ) ) : '' );
	}

	/**
	 * The tool a `setup` query value names, or '' for anything but Apps::TOOLS.
	 *
	 * @param mixed $value Unslashed query value.
	 * @return string
	 */
	public static function setup_tool( $value ): string {
		$tool = is_string( $value ) ? sanitize_key( $value ) : '';
		return in_array( $tool, Apps::TOOLS, true ) ? $tool : '';
	}

	/**
	 * Facts about this site and its connection.
	 *
	 * @param bool $refresh Whether to refresh the gateway's key set while connected (its failure goes in `key_error`).
	 * @return array{state: string, https_address: bool, scope: string, account_hint: string, paired_at: int, pending_at: int, last_request: int, ended: int, gateway: string, check_url: string, account_url: string, mcp_url: string, local: bool, https: bool, key_error: string, site: string, host: string, tools: array<string, array{names: string[], scope: string, first_seen: int, last_seen: int, last_request: int}>, blocked: string[], setup: string, closing: string, done: string, status_url: string}
	 */
	public static function facts( bool $refresh = false ): array {
		$store      = new WpStore();
		$apps       = new Apps( $store );
		$gateway    = Config::url();
		$base       = '' !== $gateway ? $gateway : Config::DEFAULT_URL;
		$connection = $store->connection();
		$pending    = $store->pending();
		$home       = home_url();
		$site_mcp   = rest_url( 'flitagent/v1/mcp' );

		$https_address = 'https' === strtolower( (string) wp_parse_url( $home, PHP_URL_SCHEME ) ) && 'https' === strtolower( (string) wp_parse_url( $site_mcp, PHP_URL_SCHEME ) );

		$state = 'none';
		if ( null !== $connection ) {
			$state = 'connected';
		} elseif ( null !== $pending && (int) $pending['expires_at'] >= time() ) {
			$state = 'waiting';
		}

		$key_error = '';
		if ( $refresh && 'connected' === $state && '' !== $gateway ) {
			$key_error = ( new Keys( $store, $gateway ) )->refresh_if_connected();
		}

		return array(
			'state'         => $state,
			'scope'         => null !== $connection ? $connection->scope() : '',
			'account_hint'  => null !== $connection ? $connection->account_hint() : '',
			'paired_at'     => null !== $connection ? $connection->paired_at() : 0,
			'pending_at'    => 'waiting' === $state ? (int) $pending['expires_at'] - Pairing::TTL : 0,
			'last_request'  => null !== $connection ? $store->last_request() : 0,
			'ended'         => null === $connection ? $store->ended() : 0,
			'gateway'       => $gateway,
			'check_url'     => null !== $connection ? Config::check_url( $base, $connection->id() ) : '',
			'account_url'   => Config::account_url( $base ),
			'mcp_url'       => Config::mcp_url(),
			'local'         => self::is_local( $home, $site_mcp, $gateway ),
			'https'         => is_ssl() && $https_address,
			'https_address' => $https_address,
			'key_error'     => $key_error,
			'site'          => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'host'          => (string) wp_parse_url( $home, PHP_URL_HOST ),
			'tools'         => null !== $connection ? $apps->tools() : array(),
			'blocked'       => $apps->blocked(),
			'setup'         => '',
			'closing'       => '',
			'done'          => '',
			'status_url'    => ToolStatusRoute::url(),
		);
	}

	/**
	 * Whether flitagent.com cannot reach this site: its home or MCP address is on a loopback or private host, and the
	 * gateway is not itself local: on a loopback host, or (only where Config::allows_private(), in a local or development
	 * environment) on a private host such as `test.local`. A local gateway, used in development, can reach the site.
	 *
	 * @param string $home    The home URL.
	 * @param string $mcp     The site's MCP URL.
	 * @param string $gateway The gateway URL ('' when invalid).
	 * @return bool
	 */
	public static function is_local( string $home, string $mcp, string $gateway ): bool {
		if ( '' !== $gateway && ( Config::is_loopback( $gateway ) || ( self::private_host( (string) wp_parse_url( $gateway, PHP_URL_HOST ) ) && Config::allows_private() ) ) ) {
			return false;
		}
		foreach ( array( $home, $mcp ) as $url ) {
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( Config::is_loopback( $url ) || self::private_host( $host ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Why an invalid gateway override (FLITAGENT_GATEWAY_URL or the flitagent_gateway_url filter) cannot be used.
	 *
	 * @return string
	 */
	public static function invalid_gateway_message(): string {
		return __( 'The flitagent.com address set for this site (FLITAGENT_GATEWAY_URL or the flitagent_gateway_url filter) is not valid, so it cannot be connected. It must be an HTTPS address on a host name or an IPv4 address (plain HTTP only on localhost or 127.0.0.1), without a query or fragment. IPv6 addresses such as [::1] cannot be used because WordPress refuses to redirect to them.', 'flitagent' );
	}

	/**
	 * Whether a host is only reachable on a private network: a special-use or local-only name (.local, .test,
	 * .localhost, .internal, .lan, .home.arpa, .invalid), a local development domain whose public DNS points back to
	 * this computer (DDEV's .ddev.site, Lando's .lndo.site, Docksal's .docksal.site, localtest.me), or a private,
	 * link-local, unique-local or shared-address (100.64.0.0/10, Tailscale) IP literal.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function private_host( string $host ): bool {
		$host = strtolower( rtrim( trim( $host, '[]' ), '.' ) );
		if ( '' === $host ) {
			return false;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// 100.64.0.0/10 (carrier-grade NAT, Tailscale) is outside PHP's private and reserved flags.
			if ( 1 === preg_match( '/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $host ) ) {
				return true;
			}
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return 1 === preg_match( '/(^|\.)(local|test|localhost|internal|lan|invalid|home\.arpa|ddev\.site|lndo\.site|docksal\.site|localtest\.me)$/', $host );
	}

	/**
	 * The screen from facts: the Site connectivity card, the Connected AI tools card and, while connected, the dialogs
	 * (Disconnect a tool, Disconnect site, Set up a tool). The dialogs are native `<dialog>` elements that the admin
	 * script opens (assets/js/dialog.js); without it the Disconnect forms submit straight away and a Set up link
	 * reloads the screen with that dialog open on the page.
	 *
	 * @param array<string, mixed> $facts Facts (see facts()).
	 * @return string
	 */
	public static function html( array $facts ): string {
		return Ui::open( __( 'Connect to flitagent.com', 'flitagent' ) )
			. ( '' === (string) ( $facts['closing'] ?? '' ) ? Ui::notice_once() : self::tab_done( $facts ) )
			. '<section class="flitagent-card flitagent-connectivity" aria-labelledby="flitagent-connectivity-title">' . self::status_section( $facts ) . '</section>'
			. '<section class="flitagent-card flitagent-tools-card" aria-labelledby="flitagent-tools-title">' . self::tools_section( $facts ) . '</section>'
			. '<p class="flitagent-links"><a href="' . esc_url( Onboarding::url( 'welcome' ) ) . '">' . esc_html__( 'Setup guide', 'flitagent' ) . '</a></p>'
			. self::dialogs( $facts ) . '</div>';
	}

	/**
	 * The new tab's closing message (#198). The stored notice stays for the tab that started connecting; if no tab
	 * answers, the script reloads this one without the marker and it shows the notice itself.
	 *
	 * @param array<string, mixed> $facts Facts (see facts()).
	 * @return string
	 */
	private static function tab_done( array $facts ): string {
		if ( self::TAB_CONNECTED === $facts['closing'] && 'connected' === ( $facts['state'] ?? '' ) ) {
			return '<div class="notice notice-success inline flitagent-notice flitagent-tab-done" role="status" data-flitagent-tab-done="connected"><p>' . esc_html__( 'Connected to flitagent.com. Going back to the tab you started from…', 'flitagent' ) . '</p></div>';
		}
		return '<div class="notice notice-info inline flitagent-notice flitagent-tab-done" role="status" data-flitagent-tab-done="failed"><p>' . esc_html__( 'Connecting did not finish. Going back to the tab you started from…', 'flitagent' ) . '</p></div>';
	}

	/**
	 * Site connectivity: the state of the connection and the actions for it. In the setup guide's Connect step (#209) the
	 * heading is an h3 under the step's own, connecting asks no access (both forms offer write as the ceiling) and,
	 * once connected, the account link, Access and Disconnect site stay on the Connect screen.
	 *
	 * @param array<string, mixed> $facts Facts (see facts()).
	 * @param bool                 $guide Whether in the setup guide.
	 * @return string
	 */
	public static function status_section( array $facts, bool $guide = false ): string {
		$state = (string) ( $facts['state'] ?? 'none' );
		$html  = self::heading( 'flitagent-connectivity-title', __( 'Site connectivity', 'flitagent' ), $guide );

		if ( 'connected' === $state ) {
			if ( ! $guide ) {
				return $html . self::connected( $facts );
			}
			return $html . self::key_error( $facts ) . self::connection_table( $facts, false );
		}

		$html .= '<p>' . Ui::chip( 'waiting' === $state ? 'waiting' : 'not_connected' ) . '</p>';
		if ( 'waiting' === $state ) {
			$html .= '<p>' . esc_html__( 'Finish connecting on flitagent.com, or start again.', 'flitagent' ) . '</p>';
		} elseif ( (int) ( $facts['ended'] ?? 0 ) > 0 ) {
			/* translators: %s: date and time in UTC. */
			$html .= '<p class="flitagent-ended">' . esc_html( sprintf( __( 'Disconnected on flitagent.com on %s. Connect again to use your AI tools on this site.', 'flitagent' ), self::time( (int) $facts['ended'] ) ) ) . '</p>';
		}
		return $html . self::connect_form( $facts, $guide );
	}

	/**
	 * A card's heading: an h2 on the Connect screen, an h3 inside the setup guide's step.
	 *
	 * @param string $id    Id.
	 * @param string $text  Text (plain).
	 * @param bool   $guide Whether in the setup guide.
	 * @return string
	 */
	private static function heading( string $id, string $text, bool $guide ): string {
		$tag = $guide ? 'h3' : 'h2';
		return '<' . $tag . ' id="' . esc_attr( $id ) . '">' . esc_html( $text ) . '</' . $tag . '>';
	}

	/**
	 * The warning that the key set could not be refreshed, or ''.
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return string
	 */
	private static function key_error( array $facts ): string {
		$error = (string) ( $facts['key_error'] ?? '' );
		return '' === $error ? '' : self::notice( __( 'Could not reach flitagent.com:', 'flitagent' ) . ' ' . $error );
	}

	/**
	 * The connected state: facts, then the account link and Disconnect site (which opens its dialog).
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return string
	 */
	private static function connected( array $facts ): string {
		return self::key_error( $facts ) . self::connection_table( $facts ) . '<div class="flitagent-actions">'
			. '<a class="button" href="' . esc_url( (string) $facts['account_url'] ) . '">' . esc_html__( 'Your flitagent.com account', 'flitagent' ) . '</a> '
			. '<form method="post" class="flitagent-inline-form" id="flitagent-disconnect-site-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( AdminSection::DISCONNECT ) . '" />'
			. self::nonce( AdminSection::DISCONNECT )
			. '<button type="submit" class="button" data-flitagent-dialog="flitagent-disconnect-site">' . esc_html__( 'Disconnect site', 'flitagent' ) . '</button></form></div>';
	}

	/**
	 * The connection's facts: status (with the connected AI tools, or an AI tools row saying none is), account, access
	 * (with a Change link to Settings), since when and the last request. Shown on the Connect screen and, without Access,
	 * in the setup guide's Connect step.
	 *
	 * @param array<string, mixed> $facts  Facts (see facts()).
	 * @param bool                 $access Whether to show the connection's access.
	 * @return string
	 */
	public static function connection_table( array $facts, bool $access = true ): string {
		$last  = (int) $facts['last_request'];
		$tools = self::connected_tools( $facts );
		$rows  = array();
		$muted = '';
		if ( array() !== $tools ) {
			$chips = '';
			foreach ( array_keys( $tools ) as $tool ) {
				$chips .= '<span class="flitagent-tool-chip">' . self::tile( $tool ) . esc_html( self::tool_label( $tool ) ) . '</span>';
			}
			/* translators: 1: the "Connected" status chip, 2: a chip for each connected AI tool, such as ChatGPT. */
			$rows[ __( 'Status', 'flitagent' ) ] = sprintf( esc_html__( '%1$s with %2$s', 'flitagent' ), Ui::chip( 'connected' ), '<span class="flitagent-tool-chips">' . $chips . '</span>' );
			// The row's "with" is muted and smaller; the chips keep their own colour and size.
			$muted = __( 'Status', 'flitagent' );
		} else {
			$rows[ __( 'Status', 'flitagent' ) ]   = Ui::chip( 'connected_gateway' );
			$rows[ __( 'AI tools', 'flitagent' ) ] = Ui::chip( 'not_connected' );
		}
		$rows[ __( 'Account', 'flitagent' ) ] = esc_html( (string) $facts['account_hint'] );
		if ( $access ) {
			// The beta can only read, whatever scope an older connection was paired with.
			$rows[ __( 'Access', 'flitagent' ) ] = esc_html__( 'Read only', 'flitagent' );
		}
		$rows[ __( 'Connected since', 'flitagent' ) ]                 = esc_html( self::time( (int) $facts['paired_at'] ) );
		$rows[ __( 'Last request from flitagent.com', 'flitagent' ) ] = $last > 0 ? esc_html( self::time( $last ) ) : self::no_request_yet( (string) ( $facts['check_url'] ?? '' ) );
		$html = '<dl class="flitagent-connection-facts">';
		foreach ( $rows as $label => $value ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd' . ( $muted === $label ? ' class="flitagent-status-with"' : '' ) . '>' . $value . '</dd>';
		}
		return $html . '</dl>';
	}

	/**
	 * The connected AI tools of the facts (see Apps::tools()), only those named in Apps::TOOLS.
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return array<string, array{names: string[], scope: string, first_seen: int, last_seen: int}>
	 */
	public static function connected_tools( array $facts ): array {
		$tools = isset( $facts['tools'] ) && is_array( $facts['tools'] ) ? $facts['tools'] : array();
		return array_intersect_key( $tools, array_flip( Apps::TOOLS ) );
	}

	/**
	 * Connected AI tools (#188): a row for ChatGPT, Claude and Manual configuration with its tile, name, a line about it,
	 * its chip and its action. Before the site is connected every Set up is dimmed and inert. In the setup guide's
	 * Connect step (#209) the heading is an h3, the line says what to do there, and Set up, Disconnect and a disconnected
	 * tool's Set up come back to the guide.
	 *
	 * @param array<string, mixed> $facts Facts (see facts()).
	 * @param bool                 $guide Whether in the setup guide.
	 * @return string
	 */
	public static function tools_section( array $facts, bool $guide = false ): string {
		$site    = 'connected' === ( $facts['state'] ?? '' );
		$tools   = self::connected_tools( $facts );
		$blocked = isset( $facts['blocked'] ) && is_array( $facts['blocked'] ) ? $facts['blocked'] : array();
		if ( $guide ) {
			$intro = $site ? __( 'Set up the AI tools you use. You can add more later in FlitAgent → Connect.', 'flitagent' ) : __( 'AI tools reach this site through flitagent.com. Set up becomes available once the site is connected.', 'flitagent' );
		} else {
			$intro = $site ? __( 'AI tools that can reach this site through flitagent.com. Disconnect removes one tool\'s access to this site; the others keep working.', 'flitagent' ) : __( 'AI tools reach this site through flitagent.com. Connect the site above first, then set up the tools you use.', 'flitagent' );
		}
		$html = self::heading( 'flitagent-tools-title', __( 'Connected AI tools', 'flitagent' ), $guide )
			. '<p>' . esc_html( $intro ) . '</p>'
			. '<ul class="flitagent-ai-tools"' . self::status_attributes( $facts ) . '>';
		foreach ( Apps::TOOLS as $tool ) {
			$label = self::tool_label( $tool );
			$sr    = '<span class="screen-reader-text"> ' . esc_html( $label ) . '</span>';
			if ( $site && isset( $tools[ $tool ] ) ) {
				$sub    = self::tool_line( $tool, $tools[ $tool ] );
				$chip   = Ui::chip( 'connected' );
				$action = self::post_form( AdminSection::DISCONNECT_APP, $tool, 'flitagent-disconnect-' . $tool . '-form', '<button type="submit" class="button" data-flitagent-dialog="flitagent-disconnect-' . esc_attr( $tool ) . '">' . esc_html__( 'Disconnect', 'flitagent' ) . $sr . '</button>', $guide );
			} elseif ( $site && in_array( $tool, $blocked, true ) ) {
				$sub    = esc_html__( 'Disconnected from this site. Set up allows it again.', 'flitagent' );
				$chip   = Ui::chip( 'not_connected' );
				$action = self::post_form( AdminSection::SETUP_APP, $tool, '', '<button type="submit" class="button button-primary">' . esc_html__( 'Set up', 'flitagent' ) . $sr . '</button>', $guide );
			} else {
				$sub    = esc_html__( 'Not connected to this site', 'flitagent' );
				$chip   = Ui::chip( 'not_connected' );
				$action = $site
					? '<a class="button button-primary" href="' . esc_url( self::setup_url( $tool, $guide ) ) . '" data-flitagent-dialog="flitagent-setup-' . esc_attr( $tool ) . '">' . esc_html__( 'Set up', 'flitagent' ) . $sr . '</a>'
					: '<span class="flitagent-button-inert" aria-disabled="true">' . esc_html__( 'Set up', 'flitagent' ) . $sr . '</span>';
			}
			$state = $site && isset( $tools[ $tool ] ) ? ToolStatusRoute::CONNECTED : ToolStatusRoute::NOT_CONNECTED;
			$html .= '<li class="flitagent-tool flitagent-tool--' . esc_attr( $tool ) . '" data-flitagent-tool="' . esc_attr( $tool ) . '" data-flitagent-state="' . esc_attr( $state ) . '">' . self::tile( $tool )
				. '<span class="flitagent-tool-text"><span class="flitagent-tool-name">' . esc_html( $label ) . '</span><span class="flitagent-tool-sub">' . $sub . '</span></span>'
				. $chip . $action . '</li>';
		}//end foreach
		return $html . '</ul>';
	}

	/**
	 * While the site is connected, where tool-status.js asks for the tools' state (ToolStatusRoute) and the `wp_rest`
	 * nonce it sends, as attributes of the tool list; '' otherwise.
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return string
	 */
	private static function status_attributes( array $facts ): string {
		$url = (string) ( $facts['status_url'] ?? '' );
		if ( 'connected' !== ( $facts['state'] ?? '' ) || '' === $url ) {
			return '';
		}
		return ' data-flitagent-tool-status="' . esc_url( $url ) . '" data-flitagent-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"';
	}

	/**
	 * The line under a connected tool: (for Manual configuration, the clients behind it,) its access (always Read
	 * only), when it was connected and its last request, or "no requests yet" while flitagent.com has only announced it.
	 *
	 * @param string                                                                                     $tool Tool.
	 * @param array{names: string[], scope: string, first_seen: int, last_seen: int, last_request?: int} $info What Apps::tools() says.
	 * @return string Escaped.
	 */
	private static function tool_line( string $tool, array $info ): string {
		$last = (int) ( $info['last_request'] ?? $info['last_seen'] );
		if ( $last > 0 ) {
			/* translators: 1: the access, such as Read only, 2: when the tool was connected, 3: its last request. */
			$line = sprintf( __( '%1$s · connected %2$s · last request %3$s', 'flitagent' ), __( 'Read only', 'flitagent' ), self::time( (int) $info['first_seen'] ), self::time( $last ) );
		} else {
			/* translators: 1: the access, such as Read only, 2: when the tool was connected. */
			$line = sprintf( __( '%1$s · connected %2$s · no requests yet', 'flitagent' ), __( 'Read only', 'flitagent' ), self::time( (int) $info['first_seen'] ) );
		}
		if ( Apps::MANUAL === $tool && array() !== $info['names'] ) {
			/* translators: 1: the clients behind Manual configuration, such as Claude Code, 2: their access and times. */
			$line = sprintf( __( '%1$s · %2$s', 'flitagent' ), implode( ', ', array_map( array( Client::class, 'display_name' ), array_map( 'strval', $info['names'] ) ) ), $line );
		}
		return esc_html( $line );
	}

	/**
	 * A POST form to admin-post.php for one tool: the action, the tool, a nonce bound to both and the given button. From
	 * the setup guide it also says so, and the handler returns there.
	 *
	 * @param string $action Action (and nonce action).
	 * @param string $tool   Tool.
	 * @param string $id     Form id ('' for none).
	 * @param string $button Escaped button.
	 * @param bool   $guide  Whether in the setup guide.
	 * @return string
	 */
	private static function post_form( string $action, string $tool, string $id, string $button, bool $guide = false ): string {
		return '<form method="post" class="flitagent-inline-form"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ' action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" /><input type="hidden" name="tool" value="' . esc_attr( $tool ) . '" />'
			. ( $guide ? '<input type="hidden" name="' . esc_attr( AdminSection::FROM ) . '" value="' . esc_attr( AdminSection::FROM_GUIDE ) . '" />' : '' )
			. self::nonce( $action . '_' . $tool ) . $button . '</form>';
	}

	/**
	 * A nonce field without an id (several forms share the page).
	 *
	 * @param string $action Nonce action.
	 * @return string
	 */
	private static function nonce( string $action ): string {
		return '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
	}

	/**
	 * A tool's tile: its mark on its brand colour (a terminal prompt for Manual configuration). Decorative.
	 *
	 * @param string $tool Tool.
	 * @return string
	 */
	private static function tile( string $tool ): string {
		return '<span class="flitagent-tool-tile flitagent-tool-tile--' . esc_attr( $tool ) . '">' . Ui::app_icon( $tool ) . '</span>';
	}

	/**
	 * The dialogs, only while connected: Disconnect for each connected tool, Disconnect site (not in the setup guide,
	 * which has no Disconnect site button), and Set up for each tool (the one `setup` names starts open, unless that tool
	 * is disconnected: its Set up form allows it again first).
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @param bool                 $guide Whether in the setup guide.
	 * @return string
	 */
	public static function dialogs( array $facts, bool $guide = false ): string {
		if ( 'connected' !== ( $facts['state'] ?? '' ) ) {
			return '';
		}
		$tools  = self::connected_tools( $facts );
		$cancel = '<form method="dialog" class="flitagent-inline-form"><button type="submit" class="button">' . esc_html__( 'Cancel', 'flitagent' ) . '</button></form>';
		$html   = '';
		foreach ( array_keys( $tools ) as $tool ) {
			$label = self::tool_label( $tool );
			$id    = 'flitagent-disconnect-' . $tool;
			$html .= self::dialog(
				$id,
				'',
				/* translators: %s: an AI tool, such as ChatGPT. */
				'<h2 id="' . esc_attr( $id . '-title' ) . '">' . esc_html( sprintf( __( 'Disconnect %s from this site?', 'flitagent' ), $label ) ) . '</h2>'
				/* translators: %s: an AI tool, such as ChatGPT. */
				. '<p>' . esc_html( sprintf( __( '%s stops working with this site straight away.', 'flitagent' ), $label ) ) . '</p>'
				/* translators: %s: an AI tool, such as ChatGPT. */
				. '<p>' . esc_html( sprintf( __( 'To use %s here again, set it up from this page.', 'flitagent' ), $label ) ) . '</p>'
				. '<div class="flitagent-dialog-actions">' . $cancel
				/* translators: %s: an AI tool, such as ChatGPT. */
				. '<button type="submit" class="button flitagent-button-danger" form="' . esc_attr( $id . '-form' ) . '">' . esc_html( sprintf( __( 'Disconnect %s', 'flitagent' ), $label ) ) . '</button></div>'
			);
		}

		if ( ! $guide ) {
			$html .= self::dialog(
				'flitagent-disconnect-site',
				'',
				'<h2 id="flitagent-disconnect-site-title">' . esc_html__( 'Disconnect this site from flitagent.com?', 'flitagent' ) . '</h2>'
				. '<p>' . esc_html( self::stop_working( array_map( array( self::class, 'tool_label' ), array_keys( $tools ) ) ) ) . '</p>'
				. '<p>' . esc_html__( 'Your flitagent.com account and its other sites are not affected.', 'flitagent' ) . '</p>'
				. '<div class="flitagent-dialog-actions">' . $cancel
				. '<button type="submit" class="button flitagent-button-danger" form="flitagent-disconnect-site-form">' . esc_html__( 'Disconnect site', 'flitagent' ) . '</button></div>'
			);
		}

		$url      = (string) $facts['mcp_url'];
		$requests = (string) ( $facts['setup'] ?? '' );
		$blocked  = isset( $facts['blocked'] ) && is_array( $facts['blocked'] ) ? $facts['blocked'] : array();
		$setups   = array(
			Apps::CHATGPT => array(
				__( 'Add FlitAgent to ChatGPT, then approve it on flitagent.com.', 'flitagent' ),
				self::chatgpt_steps( $url ),
				Ui::external_link( self::CHATGPT_PLUGINS, __( 'Open ChatGPT Plugins', 'flitagent' ), 'button button-primary', array( self::OPEN_TOOL => Apps::CHATGPT ) ),
			),
			Apps::CLAUDE  => array(
				__( 'Add FlitAgent to Claude, then approve it on flitagent.com.', 'flitagent' ),
				self::claude_steps( $url ),
				Ui::external_link( self::claude_add_url( $url ), __( 'Open Claude connectors', 'flitagent' ), 'button button-primary', array( self::OPEN_TOOL => Apps::CLAUDE ) ),
			),
			Apps::MANUAL  => array(
				__( 'Add FlitAgent to Claude Code, Codex or another MCP client, then approve it on flitagent.com.', 'flitagent' ),
				self::manual_steps( $url ),
				'',
			),
		);
		foreach ( $setups as $tool => $setup ) {
			$id    = 'flitagent-setup-' . $tool;
			$label = self::tool_label( $tool );
			$html .= self::dialog(
				$id,
				' flitagent-dialog--wide',
				'<div class="flitagent-dialog-head">' . self::tile( $tool )
				/* translators: %s: an AI tool, such as ChatGPT. */
				. '<h2 id="' . esc_attr( $id . '-title' ) . '">' . esc_html( sprintf( __( 'Set up %s', 'flitagent' ), self::tool_label( $tool ) ) ) . '</h2>'
				. '<form method="dialog" class="flitagent-inline-form"><button type="submit" class="flitagent-dialog-close" aria-label="' . esc_attr__( 'Close', 'flitagent' ) . '">' . Ui::icon( 'x', 18 ) . '</button></form></div>'
				. '<p>' . esc_html( $setup[0] ) . '</p>' . $setup[1]
				. '<p class="flitagent-setup-waiting" role="status">' . Ui::icon( 'clock', 16 ) . '<span><strong>'
				/* translators: %s: an AI tool, such as ChatGPT. */
				. esc_html( sprintf( __( 'Waiting for %s…', 'flitagent' ), $label ) ) . '</strong> '
				. esc_html__( 'This updates by itself after you click Allow on flitagent.com.', 'flitagent' ) . '</span></p>'
				. '<div class="flitagent-dialog-actions"><form method="dialog" class="flitagent-inline-form"><button type="submit" class="button">' . esc_html__( 'Close', 'flitagent' ) . '</button></form>' . $setup[2] . '</div>',
				$requests === $tool && ! in_array( $tool, $blocked, true ),
				' data-flitagent-setup-tool="' . esc_attr( $tool ) . '"'
			);
		}
		return $html . self::done_dialog( $facts );
	}

	/**
	 * The "is connected" dialog, open, for the tool the request names (`done`, after tool-status.js saw it turn
	 * Connected) while that tool is connected, or ''. A close button, the tool's mark with a check, a first question to
	 * copy and, for ChatGPT and Claude, Try now: a new chat in that tool with the question filled in. Manual
	 * configuration finishes in the terminal, so it has no chat to open and no Try now.
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @return string
	 */
	private static function done_dialog( array $facts ): string {
		$tool = (string) ( $facts['done'] ?? '' );
		if ( '' === $tool || ! isset( self::connected_tools( $facts )[ $tool ] ) ) {
			return '';
		}
		$id       = 'flitagent-done-' . $tool;
		$label    = self::tool_label( $tool );
		$host     = (string) ( $facts['host'] ?? '' );
		$where    = '' !== $host ? $host : __( 'this site', 'flitagent' );
		$question = self::first_question();
		$try      = self::try_url( $tool, $question );
		$line     = Apps::MANUAL === $tool
			/* translators: %s: this site's host name, such as example.com. */
			? sprintf( __( 'Your MCP client can now work with %s through FlitAgent. Try it with a first question.', 'flitagent' ), $where )
			/* translators: 1: an AI tool, such as ChatGPT, 2: this site's host name, such as example.com. */
			: sprintf( __( '%1$s can now work with %2$s through FlitAgent. Try it with a first question.', 'flitagent' ), $label, $where );
		$footer = '';
		if ( '' !== $try ) {
			/* translators: %s: an AI tool, such as ChatGPT. */
			$footer = '<div class="flitagent-done-footer">' . Ui::external_link( $try, sprintf( __( 'Try now in %s', 'flitagent' ), $label ), 'button button-primary' ) . '</div>';
		}
		return self::dialog(
			$id,
			' flitagent-dialog--done',
			'<form method="dialog" class="flitagent-inline-form flitagent-done-close"><button type="submit" class="flitagent-dialog-close" aria-label="' . esc_attr__( 'Close', 'flitagent' ) . '">' . Ui::icon( 'x', 20 ) . '</button></form>'
			. '<div class="flitagent-done-head"><span class="flitagent-done-tile flitagent-done-tile--' . esc_attr( $tool ) . '" aria-hidden="true">' . Ui::app_icon( $tool ) . '<span class="flitagent-done-badge">' . Ui::icon( 'check', 12 ) . '</span></span>'
			/* translators: %s: an AI tool, such as ChatGPT. */
			. '<h2 id="' . esc_attr( $id . '-title' ) . '">' . esc_html( sprintf( __( '%s is connected', 'flitagent' ), $label ) ) . '</h2>'
			. '<p>' . esc_html( $line ) . '</p></div>'
			. self::question_card( $id, $question )
			. $footer,
			true
		);
	}

	/**
	 * The "is connected" dialog's first question: "Let’s check", the question in quotation marks and a Copy button
	 * (CopyButton in assets/js/copy-button.js) that copies it without them and says "Copied" in the card's live
	 * region. Where the browser copies nothing, the script selects the question (`<id>-question`) instead.
	 *
	 * @param string $id       The dialog's id.
	 * @param string $question The question.
	 * @return string
	 */
	private static function question_card( string $id, string $question ): string {
		return '<div class="flitagent-done-question" role="group" aria-labelledby="' . esc_attr( $id . '-check' ) . '"><div class="flitagent-done-question-head">'
			. '<span id="' . esc_attr( $id . '-check' ) . '">' . esc_html__( 'Let’s check', 'flitagent' ) . '</span>'
			. '<button type="button" class="flitagent-copy-text" data-flitagent-copy-text="' . esc_attr( $question ) . '" data-flitagent-copied="' . esc_attr__( 'Copied', 'flitagent' ) . '" data-flitagent-copy-status="' . esc_attr( $id . '-copied' ) . '" data-flitagent-copy-select="' . esc_attr( $id . '-question' ) . '">'
			. Ui::icon( 'copy', 14 ) . '<span data-flitagent-copy-label>' . esc_html__( 'Copy', 'flitagent' ) . '</span></button></div>'
			/* translators: %s: the question to ask the AI tool. */
			. '<p>' . sprintf( esc_html__( '“%s”', 'flitagent' ), '<span id="' . esc_attr( $id . '-question' ) . '">' . esc_html( $question ) . '</span>' ) . '</p>'
			. '<span id="' . esc_attr( $id . '-copied' ) . '" class="screen-reader-text" role="status" aria-live="polite"></span></div>';
	}

	/**
	 * The first question the "is connected" dialog suggests.
	 *
	 * @return string
	 */
	public static function first_question(): string {
		return __( 'Using FlitAgent, show me the connected site details', 'flitagent' );
	}

	/**
	 * A new chat in an AI tool with a question in its message box (CHATGPT_ASK, CLAUDE_ASK), or '' for Manual
	 * configuration, which has no chat to open.
	 *
	 * @param string $tool     Tool (Apps::TOOLS).
	 * @param string $question The question.
	 * @return string
	 */
	public static function try_url( string $tool, string $question ): string {
		$bases = array(
			Apps::CHATGPT => self::CHATGPT_ASK,
			Apps::CLAUDE  => self::CLAUDE_ASK,
		);
		return isset( $bases[ $tool ] ) ? $bases[ $tool ] . rawurlencode( $question ) : '';
	}

	/**
	 * What stops working when the site is disconnected, naming the connected tools.
	 *
	 * @param string[] $labels Connected tools' names.
	 * @return string
	 */
	private static function stop_working( array $labels ): string {
		switch ( count( $labels ) ) {
			case 0:
				return __( 'Your AI tools stop working with this site until you connect it again.', 'flitagent' );
			case 1:
				/* translators: %s: an AI tool, such as ChatGPT. */
				return sprintf( __( '%s stops working with this site until you connect it again.', 'flitagent' ), $labels[0] );
			case 2:
				/* translators: 1, 2: AI tools, such as ChatGPT and Claude. */
				return sprintf( __( '%1$s and %2$s stop working with this site until you connect it again.', 'flitagent' ), $labels[0], $labels[1] );
			default:
				/* translators: 1, 2, 3: the AI tools ChatGPT, Claude and Manual configuration. */
				return sprintf( __( '%1$s, %2$s and %3$s stop working with this site until you connect it again.', 'flitagent' ), $labels[0], $labels[1], $labels[2] );
		}
	}

	/**
	 * A dialog labelled by its heading (`<id>-title`). Open (in the page, without a script) when asked; the admin
	 * script then shows it as a modal.
	 *
	 * @param string $id         Id.
	 * @param string $classes    Extra classes, each after a space.
	 * @param string $body       Escaped content.
	 * @param bool   $open       Whether it starts open.
	 * @param string $attributes Escaped extra attributes, each after a space.
	 * @return string
	 */
	private static function dialog( string $id, string $classes, string $body, bool $open = false, string $attributes = '' ): string {
		return '<dialog id="' . esc_attr( $id ) . '" class="flitagent-dialog' . esc_attr( $classes ) . '" aria-labelledby="' . esc_attr( $id . '-title' ) . '"' . $attributes . ( $open ? ' open data-flitagent-dialog-open' : '' ) . '>'
			. $body . '</dialog>';
	}

	/**
	 * Why this site cannot start connecting (a local or private site, or an invalid gateway override), as a notice, or ''
	 * when it can.
	 *
	 * @param array<string, mixed> $facts Facts (see facts()).
	 * @return string
	 */
	public static function blocker( array $facts ): string {
		if ( ! empty( $facts['local'] ) ) {
			return self::notice( __( 'flitagent.com must reach this site over the internet, so local and private sites cannot be connected.', 'flitagent' ) );
		}
		if ( '' === (string) ( $facts['gateway'] ?? '' ) ) {
			return self::notice( self::invalid_gateway_message() );
		}
		return '';
	}

	/**
	 * The notice asking for HTTPS, or '' on HTTPS.
	 *
	 * @param array<string, mixed> $facts Facts (see facts()).
	 * @return string
	 */
	public static function https_notice( array $facts ): string {
		if ( ! empty( $facts['https'] ) ) {
			return '';
		}
		return self::notice( __( 'flitagent.com needs this site\'s HTTPS address. Turn on HTTPS, make sure the WordPress Address and Site Address in Settings → General start with https://, and open this page over https://.', 'flitagent' ) );
	}

	/**
	 * What connecting does, then the Connect button (or why it cannot). Both connect forms (this one and setup step 3)
	 * ask flitagent.com for read access: the beta cannot change a site.
	 *
	 * @param array<string, mixed> $facts Facts.
	 * @param bool                 $guide Whether in the setup guide.
	 * @return string
	 */
	private static function connect_form( array $facts, bool $guide = false ): string {
		$html = '<p>' . esc_html__( 'Requests from your AI apps pass through flitagent.com, which relays them to this site and keeps no content. Nothing is sent to flitagent.com until you click Connect.', 'flitagent' ) . '</p>';

		$blocker = self::blocker( $facts );
		if ( '' !== $blocker ) {
			return $html . $blocker;
		}

		$scope = '<input type="hidden" name="scope" value="' . esc_attr( Context::SCOPE_READ ) . '" />';
		if ( ! $guide ) {
			$scope .= '<p class="description">' . esc_html__( 'AI tools can read this site but cannot change it. Requests act as you and still need your administrator access.', 'flitagent' ) . '</p>';
		}

		return $html . self::https_notice( $facts ) . self::connect_form_open() . $scope
			. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Connect to flitagent.com', 'flitagent' ) . '</button></p>'
			. self::connect_form_close();
	}

	/**
	 * The start of a form that connects this site (the Connect screen and setup step 3). With the script it opens
	 * flitagent.com in a new tab and shows `data-flitagent-connect` in this tab's status line (#198); without it, it
	 * submits here as it always did. `autocomplete="off"` keeps Firefox from restoring the script's disabled button
	 * after a reload.
	 *
	 * @return string
	 */
	public static function connect_form_open(): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-flitagent-connect="' . esc_attr__( 'Finish connecting in the new tab. This page updates once the site is connected.', 'flitagent' ) . '" data-flitagent-connect-again="' . esc_attr__( 'Start again', 'flitagent' ) . '" autocomplete="off">'
			. '<input type="hidden" name="action" value="' . esc_attr( AdminSection::CONNECT ) . '" />'
			. wp_nonce_field( AdminSection::CONNECT, '_wpnonce', true, false );
	}

	/**
	 * "None yet" while no AI tool has made a request, with a link to flitagent.com's check page (#198 review: the new-tab
	 * flow skips that page, and flitagent.com drops a site it never confirmed).
	 *
	 * @param string $check_url The check page for the connection, or ''.
	 * @return string Escaped HTML.
	 */
	private static function no_request_yet( string $check_url ): string {
		$html = esc_html__( 'None yet', 'flitagent' );
		return '' === $check_url ? $html : $html . '. ' . Ui::external_link( $check_url, __( 'Check the connection on flitagent.com', 'flitagent' ) );
	}

	/**
	 * The end of a connect form: the status line the script fills, then the closing tag.
	 *
	 * @return string
	 */
	public static function connect_form_close(): string {
		return '<p class="flitagent-connect-status" role="status"></p></form>';
	}

	/**
	 * ChatGPT's steps: a custom MCP server with OAuth.
	 *
	 * @param string $url The MCP Server URL.
	 * @return string
	 */
	private static function chatgpt_steps( string $url ): string {
		return self::panel(
			'chatgpt',
			__( 'ChatGPT', 'flitagent' ),
			'<ol>'
			/* translators: 1: link to ChatGPT's Plugins page, 2: "+", a button there, 3: "Add custom MCP server", a menu item. */
			. '<li>' . sprintf( esc_html__( 'Open %1$s, click %2$s and choose %3$s.', 'flitagent' ), Ui::external_link( self::CHATGPT_PLUGINS, __( 'ChatGPT Plugins', 'flitagent' ), '', array( self::OPEN_TOOL => Apps::CHATGPT ) ), self::strong( '+' ), self::strong( __( 'Add custom MCP server', 'flitagent' ) ) ) . '</li>'
			. '<li><div class="flitagent-fields"><span>' . esc_html__( 'Enter the name and, under Connection, the MCP Server URL.', 'flitagent' ) . '</span>'
			. self::copy_field( 'flitagent-chatgpt-name', __( 'Name', 'flitagent' ), self::CONNECTOR_NAME ) . self::copy_field( 'flitagent-chatgpt-url', __( 'MCP Server URL', 'flitagent' ), $url ) . '</div></li>'
			/* translators: 1: "Authentication", a section of ChatGPT's form, 2: "OAuth", its value. */
			. '<li>' . sprintf( esc_html__( 'Under %1$s, choose %2$s.', 'flitagent' ), self::strong( __( 'Authentication', 'flitagent' ) ), self::strong( 'OAuth' ) ) . '</li>'
			/* translators: %s: "Create as a plugin", a button in ChatGPT. */
			. '<li>' . sprintf( esc_html__( 'Confirm the warning, then click %s.', 'flitagent' ), self::strong( __( 'Create as a plugin', 'flitagent' ) ) ) . '</li>'
			. '<li>' . esc_html__( 'Install FlitAgent from your plugins.', 'flitagent' ) . '</li>'
			/* translators: %s: "Allow", the button on flitagent.com. */
			. '<li>' . sprintf( esc_html__( 'Log in to flitagent.com and click %s.', 'flitagent' ), self::strong( __( 'Allow', 'flitagent' ) ) ) . '</li>'
			. '</ol>'
			. '<p class="flitagent-guide-link"><a href="' . esc_url( Config::GUIDE_CHATGPT ) . '">' . esc_html__( 'See full guide with screenshots', 'flitagent' ) . '</a></p>'
		);
	}

	/**
	 * Claude's steps: a custom connector.
	 *
	 * @param string $url The MCP Server URL.
	 * @return string
	 */
	private static function claude_steps( string $url ): string {
		return self::panel(
			'claude',
			__( 'Claude', 'flitagent' ),
			'<ol>'
			/* translators: 1: link to Claude's "Add custom connector" dialog, 2: that dialog's name. */
			. '<li>' . sprintf( esc_html__( 'Open %1$s: Claude opens %2$s with the name and MCP Server URL filled in.', 'flitagent' ), Ui::external_link( self::claude_add_url( $url ), __( 'Claude\'s connectors', 'flitagent' ), '', array( self::OPEN_TOOL => Apps::CLAUDE ) ), self::strong( __( 'Add custom connector', 'flitagent' ) ) ) . '</li>'
			// The name and the MCP Server URL to copy, in case a field is empty (the board's step 2).
			. '<li><div class="flitagent-fields">' . self::copy_field( 'flitagent-claude-name', __( 'Name', 'flitagent' ), self::CONNECTOR_NAME ) . self::copy_field( 'flitagent-claude-url', __( 'MCP Server URL', 'flitagent' ), $url ) . '</div></li>'
			/* translators: %s: "Add", a button in Claude. */
			. '<li>' . sprintf( esc_html__( 'Click %s.', 'flitagent' ), self::strong( __( 'Add', 'flitagent' ) ) ) . '</li>'
			/* translators: %s: "Connect", a button in Claude. */
			. '<li>' . sprintf( esc_html__( 'Click %s next to FlitAgent.', 'flitagent' ), self::strong( __( 'Connect', 'flitagent' ) ) ) . '</li>'
			/* translators: %s: "Allow", the button on flitagent.com. */
			. '<li>' . sprintf( esc_html__( 'Log in to flitagent.com and click %s.', 'flitagent' ), self::strong( __( 'Allow', 'flitagent' ) ) ) . '</li>'
			. '</ol>'
			. '<p class="flitagent-guide-link"><a href="' . esc_url( Config::GUIDE_CLAUDE ) . '">' . esc_html__( 'See full guide with screenshots', 'flitagent' ) . '</a></p>'
		);
	}

	/**
	 * Claude's "Add custom connector" dialog filled in with FlitAgent's name and MCP Server URL (Anthropic documents
	 * `modal=add-custom-connector`, `connectorName` and `connectorUrl`, each value percent-encoded).
	 *
	 * @param string $url The MCP Server URL.
	 * @return string
	 */
	public static function claude_add_url( string $url ): string {
		return self::CLAUDE_ADD_CONNECTOR . '&connectorName=' . rawurlencode( self::CONNECTOR_NAME ) . '&connectorUrl=' . rawurlencode( $url );
	}

	/**
	 * Manual configuration: Claude Code, Codex and any other MCP client with OAuth.
	 *
	 * @param string $url The MCP Server URL.
	 * @return string
	 */
	private static function manual_steps( string $url ): string {
		return self::panel(
			'manual',
			__( 'Manual configuration', 'flitagent' ),
			'<p>' . esc_html__( 'For Claude Code, Codex or any MCP client that supports remote servers with OAuth.', 'flitagent' ) . '</p>'
			. self::copy_field( 'flitagent-claude-code', __( 'Claude Code', 'flitagent' ), 'claude mcp add --transport http flitagent ' . $url )
			/* translators: %s: the /mcp command. */
			. '<p class="description">' . sprintf( esc_html__( 'Then run %s in Claude Code and choose Authenticate.', 'flitagent' ), '<code>/mcp</code>' ) . '</p>'
			. self::copy_field( 'flitagent-codex', __( 'Codex', 'flitagent' ), 'codex mcp add flitagent --url ' . $url )
			/* translators: %s: the codex mcp login command. */
			. '<p class="description">' . sprintf( esc_html__( 'Then run %s.', 'flitagent' ), '<code>codex mcp login flitagent</code>' ) . '</p>'
			/* translators: %s: "Allow", the button on flitagent.com. */
			. '<p>' . sprintf( esc_html__( 'Other clients: add a remote (Streamable HTTP) server with this MCP Server URL and OAuth sign-in. Your browser opens flitagent.com; log in and click %s.', 'flitagent' ), self::strong( __( 'Allow', 'flitagent' ) ) ) . '</p>'
			. self::copy_field( 'flitagent-mcp-url', __( 'MCP Server URL', 'flitagent' ), $url )
			. '<p class="flitagent-guide-link"><a href="' . esc_url( Config::GUIDE_MANUAL ) . '">' . esc_html__( 'See full guide with screenshots', 'flitagent' ) . '</a></p>'
		);
	}

	/**
	 * One app's steps, shown in its Set up dialog, headed for screen readers.
	 *
	 * @param string $app   App.
	 * @param string $label App name (plain text).
	 * @param string $body  Escaped HTML.
	 * @return string
	 */
	private static function panel( string $app, string $label, string $body ): string {
		return '<div class="flitagent-app-panel flitagent-app-panel--' . esc_attr( $app ) . '"><h3 class="screen-reader-text">' . esc_html( $label ) . '</h3>'
			. $body . '</div>';
	}

	/**
	 * Bold text, such as the name of a button in another app.
	 *
	 * @param string $text Text (plain).
	 * @return string
	 */
	private static function strong( string $text ): string {
		return '<strong>' . esc_html( $text ) . '</strong>';
	}

	/**
	 * A read-only value with its label above and a Copy button.
	 *
	 * @param string $id    Input id.
	 * @param string $label Label (plain text).
	 * @param string $value Value (plain text).
	 * @return string
	 */
	private static function copy_field( string $id, string $label, string $value ): string {
		return '<div class="flitagent-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
			. '<span class="flitagent-copy-row"><input type="text" id="' . esc_attr( $id ) . '" class="large-text code" value="' . esc_attr( $value ) . '" readonly /> '
			. '<button type="button" class="button" data-flitagent-copy="' . esc_attr( $id ) . '">' . esc_html__( 'Copy', 'flitagent' ) . '</button></span></div>';
	}

	/**
	 * A date and time in UTC.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function time( int $time ): string {
		return gmdate( 'Y-m-d H:i', $time ) . ' UTC';
	}

	/**
	 * A warning notice.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function notice( string $message ): string {
		return '<div class="notice notice-warning inline flitagent-notice"><p>' . esc_html( $message ) . '</p></div>';
	}
}
