<?php
/**
 * Home page check after changing the active theme's files.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Themes;

use FlitAgent\Writes\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * Requests this site's home page the way get_page_html does (home_url(), no cookies, no redirects followed, a size
 * cap, wp_safe_remote_get()) and reports a 5xx status, PHP fatal error output or WordPress's critical error page as a
 * `home_page_error` warning, and a failed request as `home_page_unchecked`. It never fails the write: the files are
 * already in place, and rollback_change is the way back.
 */
final class Loopback {

	/**
	 * Request timeout, in seconds.
	 */
	const TIMEOUT = 15;

	/**
	 * Most bytes read.
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Body patterns of a broken page.
	 */
	const FATAL = '/(?:<b>)?(?:Fatal|Parse) error(?:<\/b>)?:|There has been a critical error on (?:this|your) website/i';

	/**
	 * Adds the home page result to an outcome: `home_page` = ok, error or unchecked, plus a warning when not ok.
	 *
	 * @param Outcome $outcome Outcome.
	 * @return Outcome
	 */
	public static function check( Outcome $outcome ): Outcome {
		$url      = home_url( '/' );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::MAX_BYTES,
				'cookies'             => array(),
				// TLS verified as core's own loopback requests do it (off by default, so a self-signed certificate works).
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter, applied as core's loopback requests apply it.
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false, $url ),
				'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8' ),
				'user-agent'          => 'FlitAgent/' . ( defined( 'FLITAGENT_VERSION' ) ? FLITAGENT_VERSION : '0' ) . ' (theme package check)',
			)
		);
		if ( is_wp_error( $response ) ) {
			return $outcome->with_result( array( 'home_page' => 'unchecked' ) )->with_warning(
				'home_page_unchecked',
				'The home page could not be requested after the change (' . Site::clean( (string) $response->get_error_message() ) . '); check the site by hand.'
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code >= 500 ) {
			return $outcome->with_result( array( 'home_page' => 'error' ) )->with_warning(
				'home_page_error',
				sprintf( 'The home page answers HTTP %d after the change. rollback_change restores the previous files.', $code )
			);
		}
		if ( 1 === preg_match( self::FATAL, $body ) ) {
			return $outcome->with_result( array( 'home_page' => 'error' ) )->with_warning(
				'home_page_error',
				sprintf( 'The home page shows a PHP error after the change (HTTP %d). rollback_change restores the previous files.', $code )
			);
		}
		return $outcome->with_result( array( 'home_page' => 'ok' ) );
	}
}
