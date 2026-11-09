<?php
/**
 * Home page check after a plugin's code changed.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Plugins;

use FlitAgent\Writes\Ops\ErrorText;

defined( 'ABSPATH' ) || exit;

/**
 * Requests this site's home page once (the same host, so `wp_safe_remote_get()` allows it; no redirects followed, no
 * cookies sent) and reports a server error or PHP fatal-error output as a warning. It never changes anything.
 */
final class Loopback {

	/**
	 * Request timeout, in seconds.
	 */
	const TIMEOUT = 10;

	/**
	 * Most bytes read.
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Output that means PHP or WordPress failed while rendering.
	 */
	const FATAL = '#(<b>)?(Fatal error|Parse error)(</b>)?:|There has been a critical error on this website#i';

	/**
	 * Warnings (code => message); empty when the home page looks fine.
	 *
	 * @param string $what What changed, e.g. "Updating Akismet".
	 * @param string $hint Sentence added to an error warning, or ''.
	 * @return array<string, string>
	 */
	public static function check( string $what, string $hint = '' ): array {
		$response = wp_safe_remote_get(
			home_url( '/' ),
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES,
				'cookies'             => array(),
				'user-agent'          => 'FlitAgent/' . FLITAGENT_VERSION . ' (plugin update check)',
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'loopback_failed' => sprintf( '%s is done, but FlitAgent could not load the home page to check the site (%s). Check the site now.', $what, rtrim( ErrorText::clean( $response->get_error_message() ), '.' ) ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 500 ) {
			return array( 'site_error' => sprintf( '%s is done, but the home page now answers HTTP %d. Check the site now.%s', $what, $status, '' === $hint ? '' : ' ' . $hint ) );
		}
		if ( 1 === preg_match( self::FATAL, (string) wp_remote_retrieve_body( $response ) ) ) {
			return array( 'site_error' => sprintf( '%s is done, but the home page now shows a PHP fatal error. Check the site now.%s', $what, '' === $hint ? '' : ' ' . $hint ) );
		}
		return array();
	}
}
