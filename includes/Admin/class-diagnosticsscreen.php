<?php
/**
 * The "Test connection" section of FlitAgent → Settings.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

use FlitAgent\Auth\Gateway\Config;
use FlitAgent\Mcp\RestController;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Connection section and, on a valid submission (access gate and nonce), runs Diagnostics against the
 * site itself and shows the results. The form posts back to the Settings page, so results are rendered in that response
 * and never stored. Every dynamic value is escaped here, so callers may echo the result.
 */
final class DiagnosticsScreen {

	/**
	 * Nonce action and field name of the button.
	 */
	const ACTION = 'flitagent_test_connection';

	/**
	 * Seconds each loopback request may take. Core's Site Health loopbacks use 10.
	 */
	const TIMEOUT = 5;

	/**
	 * Largest response body read, in bytes.
	 */
	const MAX_BODY = 65536;

	/**
	 * The section, with results when `$post` is a valid "Test connection" submission.
	 *
	 * @param array<string, mixed> $post Sanitized POST fields (Request::post()).
	 * @return string
	 */
	public static function render( array $post ): string {
		$html = '<section class="flitagent-card flitagent-card--form"><h2 id="flitagent-connection">' . esc_html__( 'Connection', 'flitagent' ) . '</h2>'
			. '<p>' . esc_html__( 'Test connection checks, from this server to itself, the host settings that most often break MCP connections: a public HTTPS address, permalinks, the MCP endpoint and custom request headers. No credentials are sent and nothing is stored.', 'flitagent' ) . ' '
			. '<a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Site Health', 'flitagent' ) . '</a> '
			. esc_html__( 'covers general loopback and REST API problems.', 'flitagent' ) . '</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=flitagent-settings#flitagent-connection' ) ) . '">'
			. '<input type="hidden" name="' . esc_attr( self::ACTION ) . '" value="1" />'
			. wp_nonce_field( self::ACTION, '_wpnonce', true, false )
			. '<p><button type="submit" class="button">' . esc_html__( 'Test connection', 'flitagent' ) . '</button></p></form>';

		if ( ! isset( $post[ self::ACTION ] ) ) {
			return $html . '</section>';
		}
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			return $html . self::error( __( 'You are not allowed to do this.', 'flitagent' ) ) . '</section>';
		}
		$nonce = isset( $post['_wpnonce'] ) && is_string( $post['_wpnonce'] ) ? $post['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			return $html . self::error( __( 'The link expired. Reload the page and try again.', 'flitagent' ) ) . '</section>';
		}

		return $html . self::results( self::run() ) . '</section>';
	}

	/**
	 * Runs every check against this site (#148: also from the setup guide). The caller checks access.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function run(): array {
		return self::diagnostics()->run( self::token() );
	}

	/**
	 * Diagnostics fed with this site's URLs and the loopback HTTP function.
	 *
	 * @return Diagnostics
	 */
	private static function diagnostics(): Diagnostics {
		return new Diagnostics(
			array(
				'home'              => home_url( '/' ),
				'mcp'               => rest_url( RestController::REST_NAMESPACE . RestController::ROUTE ),
				'probe'             => rest_url( RestController::REST_NAMESPACE . DiagnosticsRoute::ROUTE ),
				'pretty_permalinks' => '' !== (string) get_option( 'permalink_structure', '' ),
				'gateway'           => Config::url(),
			),
			array( self::class, 'http' )
		);
	}

	/**
	 * A fresh per-run token for the custom header check.
	 *
	 * @return string
	 */
	private static function token(): string {
		return DiagnosticsToken::mint( DiagnosticsRoute::site_secret(), time(), bin2hex( random_bytes( 16 ) ) );
	}

	/**
	 * Loopback HTTP for Diagnostics: core's safe request API (which allows only the home host's own port among private
	 * addresses), a short timeout, no redirects, no cookies, and core's `https_local_ssl_verify` setting as Site Health
	 * uses it. Diagnostics already refuses URLs outside the home URL.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Headers.
	 * @param string                $body    Body.
	 * @return array{code: int, type: string, body: string}|string
	 */
	public static function http( string $method, string $url, array $headers, string $body ) {
		$response = wp_safe_remote_request(
			$url,
			array(
				'method'              => $method,
				'headers'             => $headers,
				'body'                => '' === $body ? null : $body,
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'cookies'             => array(),
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter, applied as Site Health applies it.
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false, $url ),
				'limit_response_size' => self::MAX_BODY,
				'user-agent'          => 'FlitAgent diagnostics',
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Results list: each check with its status chip, message and fix.
	 *
	 * @param array<int, array<string, string>> $results Results.
	 * @return string
	 */
	public static function results( array $results ): string {
		$html = '<div class="flitagent-table-scroll"><ul class="flitagent-checks flitagent-diagnostics">';
		foreach ( $results as $result ) {
			$html .= '<li class="flitagent-check"><strong>' . esc_html( $result['label'] ) . '</strong>'
				. self::chip( $result ) . '<span>' . self::details( $result ) . '</span></li>';
		}
		return $html . '</ul></div>';
	}

	/**
	 * The setup guide's check step (the design's step 2 board): the same results as a table with Check, Result and
	 * Details columns.
	 *
	 * @param array<int, array<string, string>> $results Results.
	 * @return string
	 */
	public static function table( array $results ): string {
		$html = '<div class="flitagent-table-scroll"><table class="flitagent-check-table flitagent-diagnostics"><thead><tr>'
			. '<th scope="col" class="column-check">' . esc_html__( 'Check', 'flitagent' ) . '</th>'
			. '<th scope="col" class="column-result">' . esc_html__( 'Result', 'flitagent' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Details', 'flitagent' ) . '</th></tr></thead><tbody>';
		foreach ( $results as $result ) {
			$html .= '<tr><td>' . esc_html( $result['label'] ) . '</td><td>' . self::chip( $result ) . '</td><td>' . self::details( $result ) . '</td></tr>';
		}
		return $html . '</tbody></table></div>';
	}

	/**
	 * A result's status chip (Ui::chip); an unknown status reads as a warning.
	 *
	 * @param array<string, string> $result A result.
	 * @return string
	 */
	private static function chip( array $result ): string {
		$statuses = array(
			Diagnostics::PASS    => 'pass',
			Diagnostics::WARNING => 'warning',
			Diagnostics::FAIL    => 'fail',
			Diagnostics::SKIPPED => 'skipped',
		);
		return Ui::chip( $statuses[ $result['status'] ] ?? 'warning' );
	}

	/**
	 * A result's message, then its fix if it has one. Escaped.
	 *
	 * @param array<string, string> $result A result.
	 * @return string
	 */
	private static function details( array $result ): string {
		$html = esc_html( $result['message'] );
		if ( '' !== $result['fix'] ) {
			$html .= '<br /><strong>' . esc_html__( 'Fix:', 'flitagent' ) . '</strong> ' . esc_html( $result['fix'] );
		}
		return $html;
	}

	/**
	 * Inline error notice.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function error( string $message ): string {
		return '<div class="notice notice-error inline flitagent-notice"><p>' . esc_html( $message ) . '</p></div>';
	}
}
