<?php
/**
 * Post-update requests to the site itself.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Core;

use FlitAgent\Writes\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * Same-site loopback requests after an update: core's own database upgrade request, and a home page fetch that warns on
 * a 5xx status or PHP fatal output (the patterns `get_page_html` uses).
 */
final class SiteCheck {

	/**
	 * Timeout of the home page request, in seconds.
	 */
	const TIMEOUT = 15;

	/**
	 * Output PHP or WordPress prints when a page dies with a fatal error.
	 */
	const FATAL_PATTERNS = array(
		'/<b>(?:Fatal|Parse) error<\/b>:/i',
		'/(?:^|\n)[ \t]*(?:PHP )?(?:Fatal|Parse) error: /i',
		'/There has been a critical error on this website/i',
	);

	/**
	 * Asks WordPress to upgrade its database the way update_core() does: a request to upgrade.php?step=upgrade_db,
	 * which runs the new code (the old upgrade code is still loaded in this request). Core POSTs; this uses GET (the step
	 * is read from the query string either way) because some local servers, such as WordPress Studio's, never answer a
	 * body-less loopback POST.
	 */
	public static function upgrade_database(): void {
		// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- core's update_core() allows the database upgrade 60 seconds too.
		wp_safe_remote_get( admin_url( 'upgrade.php?step=upgrade_db' ), array( 'timeout' => 60 ) );
	}

	/**
	 * Fetches the home page and adds a warning when it fails.
	 *
	 * @param Outcome $outcome Outcome.
	 * @return Outcome
	 */
	public static function home( Outcome $outcome ): Outcome {
		$response = wp_safe_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'cookies'     => array(),
				'user-agent'  => 'FlitAgent/' . ( defined( 'FLITAGENT_VERSION' ) ? FLITAGENT_VERSION : '0' ) . ' (update_core)',
			)
		);
		if ( is_wp_error( $response ) ) {
			return $outcome->with_warning( 'site_check_unavailable', 'The home page could not be fetched after the update to check it (' . \FlitAgent\Writes\Ops\ErrorText::clean( $response->get_error_message() ) . '). Open the site to check it loads.' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$fatal  = self::fatal( (string) wp_remote_retrieve_body( $response ) );
		if ( $status >= 500 || null !== $fatal ) {
			return $outcome->with_warning( 'site_check_failed', sprintf( 'After the update the home page returned HTTP %d', $status ) . ( null === $fatal ? '.' : ' with a PHP fatal error: ' . $fatal ) . ' A plugin or theme may be incompatible with the new version.' );
		}
		return $outcome;
	}

	/**
	 * Fatal error excerpt, or null.
	 *
	 * @param string $body Response body.
	 */
	private static function fatal( string $body ): ?string {
		foreach ( self::FATAL_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $body, $match, PREG_OFFSET_CAPTURE ) ) {
				return \FlitAgent\Writes\Ops\ErrorText::clean( substr( $body, (int) $match[0][1], 400 ) );
			}
		}
		return null;
	}
}
