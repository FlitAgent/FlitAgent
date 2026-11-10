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
	 * Most redirects the home page check follows.
	 */
	const HOME_REDIRECTS = 3;

	/**
	 * Most redirects followed for the database upgrade request (core's default).
	 */
	const UPGRADE_REDIRECTS = 5;

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
		self::get(
			admin_url( 'upgrade.php?step=upgrade_db' ),
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- core's update_core() allows the database upgrade 60 seconds too.
			array( 'timeout' => 60 ),
			self::UPGRADE_REDIRECTS
		);
	}

	/**
	 * Fetches the home page and adds a warning when it fails.
	 *
	 * @param Outcome $outcome Outcome.
	 * @return Outcome
	 */
	public static function home( Outcome $outcome ): Outcome {
		$response = self::get(
			home_url( '/' ),
			array(
				'timeout'    => self::TIMEOUT,
				'cookies'    => array(),
				'user-agent' => 'FlitAgent/' . ( defined( 'FLITAGENT_VERSION' ) ? FLITAGENT_VERSION : '0' ) . ' (update_core)',
			),
			self::HOME_REDIRECTS
		);
		if ( is_wp_error( $response ) ) {
			return $outcome->with_warning( 'site_check_unavailable', 'The home page could not be fetched after the update to check it (' . \FlitAgent\Writes\Ops\ErrorText::clean( $response->get_error_message() ) . '). Open the site to check it loads.' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 300 && $status < 400 ) {
			return $outcome->with_warning( 'site_check_unavailable', sprintf( 'The home page could not be checked after the update: it redirects (HTTP %d) off this site, in a loop or more than %d times, and FlitAgent follows redirects only on this site. Open the site to check it loads.', $status, self::HOME_REDIRECTS ) );
		}
		$fatal = self::fatal( (string) wp_remote_retrieve_body( $response ) );
		if ( $status >= 500 || null !== $fatal ) {
			return $outcome->with_warning( 'site_check_failed', sprintf( 'After the update the home page returned HTTP %d', $status ) . ( null === $fatal ? '.' : ' with a PHP fatal error: ' . $fatal ) . ' A plugin or theme may be incompatible with the new version.' );
		}
		return $outcome;
	}

	/**
	 * GETs a URL on this site and follows at most $redirects redirects itself, only while they stay on the first URL's
	 * scheme, host and port. WordPress never follows one (`redirection` 0): TLS verification follows core's
	 * `https_local_ssl_verify` filter, which may turn it off, and a hop to another host must not reuse that. A redirect
	 * it does not follow (off the site, without one usable Location, or one too many) is returned as the response.
	 *
	 * @param string               $url       URL on this site.
	 * @param array<string, mixed> $args      Request options.
	 * @param int                  $redirects Most redirects followed.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function get( string $url, array $args, int $redirects ) {
		$origin = self::origin( $url );
		$base   = (string) preg_replace( '#^([a-z][a-z0-9+.-]*://[^/?\#]*).*$#is', '$1', $url );
		for ( $hop = 0; ; $hop++ ) {
			$response = wp_safe_remote_get(
				$url,
				array(
					'redirection' => 0,
					'sslverify'   => self::sslverify( $url ),
				) + $args
			);
			if ( is_wp_error( $response ) || $hop >= $redirects ) {
				return $response;
			}
			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = wp_remote_retrieve_header( $response, 'location' );
			// A repeated Location header comes back as an array: ambiguous, so it is not followed.
			$next = $status >= 300 && $status < 400 && is_string( $location ) ? self::same_site_target( $origin, $base, $location ) : null;
			if ( null === $next ) {
				return $response;
			}
			$url = $next;
		}
	}

	/**
	 * A redirect's Location as a URL on the given origin, or null when it leaves it or is not a plain path or URL.
	 *
	 * @param string|null $origin   Origin of the first URL (`self::origin()`).
	 * @param string      $base     Scheme, host and port of the first URL as written, for a path Location.
	 * @param string      $location Location header.
	 */
	private static function same_site_target( ?string $origin, string $base, string $location ): ?string {
		if ( null === $origin || '' === $location || 1 === preg_match( '/[\\\s]/', $location ) ) {
			return null;
		}
		if ( '/' === $location[0] && 0 !== strpos( $location, '//' ) ) {
			return $base . $location;
		}
		return self::origin( $location ) === $origin ? $location : null;
	}

	/**
	 * Lower-cased `scheme://host:port` of an http(s) URL without user info, or null.
	 *
	 * @param string $url URL.
	 */
	private static function origin( string $url ): ?string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
	}

	/**
	 * TLS verification of a request to this site, as core's own loopback requests decide it: the
	 * `https_local_ssl_verify` filter, off by default, so a self-signed certificate works.
	 *
	 * @param string $url URL on this site.
	 */
	private static function sslverify( string $url ): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter, applied as core's loopback requests apply it.
		return (bool) apply_filters( 'https_local_ssl_verify', false, $url );
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
