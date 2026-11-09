<?php
/**
 * WordPress.org plugin directory lookups for the plugin package tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Plugins;

use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Ops\ErrorText;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the WordPress.org directory with core's `plugins_api()` (no caching, nothing written) and holds the rules for
 * package URLs: only https downloads from downloads.wordpress.org for the plugin's own slug are accepted.
 */
final class Directory {

	/**
	 * Host every package must come from.
	 */
	const DOWNLOADS_HOST = 'downloads.wordpress.org';

	/**
	 * Timeout of the HEAD request that checks a package exists, in seconds.
	 */
	const HEAD_TIMEOUT = 10;

	/**
	 * Pattern of a WordPress.org plugin slug.
	 */
	const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,199}$/';

	/**
	 * Pattern of a version string that can be put in a download URL.
	 */
	const VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z._-]{0,63}$/';

	/**
	 * Latest release of a plugin on WordPress.org, or a refusal when the directory does not know the slug (or cannot be
	 * reached) or the package URL is not a WordPress.org download.
	 *
	 * @param string $slug Slug.
	 * @return array{name: string, slug: string, version: string, requires: string, requires_php: string, package: string}|Assessment
	 */
	public static function latest( string $slug ) {
		Site::load_admin();
		$response = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections'          => false,
					'short_description' => false,
					'description'       => false,
					'reviews'           => false,
					'ratings'           => false,
					'banners'           => false,
					'icons'             => false,
					'versions'          => false,
					'tags'              => false,
					'contributors'      => false,
					'donate_link'       => false,
					'screenshots'       => false,
					'compatibility'     => false,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'WordPress.org has no plugin %s, or could not be reached (%s). Only WordPress.org plugins can be installed or updated.', $slug, rtrim( ErrorText::clean( $response->get_error_message() ), '.' ) ) );
		}

		$info    = (array) $response;
		$version = self::field( $info, 'version' );
		$package = self::field( $info, 'download_link' );
		if ( '' === $version || ! self::is_package( $package, $slug ) ) {
			return Assessment::prohibited( 'package', sprintf( 'WordPress.org did not return a download for %s on %s over https, so FlitAgent will not install it.', $slug, self::DOWNLOADS_HOST ) );
		}

		$name = self::field( $info, 'name' );
		return array(
			'name'         => '' === $name ? $slug : html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ),
			'slug'         => $slug,
			'version'      => $version,
			'requires'     => self::field( $info, 'requires' ),
			'requires_php' => self::field( $info, 'requires_php' ),
			'package'      => $package,
		);
	}

	/**
	 * Whether a URL is an https download of this slug from downloads.wordpress.org.
	 *
	 * @param string $url  URL.
	 * @param string $slug Slug.
	 * @return bool
	 */
	public static function is_package( string $url, string $slug ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || self::DOWNLOADS_HOST !== strtolower( (string) ( $parts['host'] ?? '' ) ) ) {
			return false;
		}
		if ( isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['query'] ) ) {
			return false;
		}
		return 1 === preg_match( '#^/plugin/' . preg_quote( $slug, '#' ) . '(\.[0-9A-Za-z._-]+)?\.zip$#', (string) ( $parts['path'] ?? '' ) );
	}

	/**
	 * Download URL of one version of a plugin, or '' when the version cannot be part of a URL.
	 *
	 * @param string $slug    Slug.
	 * @param string $version Version.
	 * @return string
	 */
	public static function version_package( string $slug, string $version ): string {
		if ( 1 !== preg_match( self::SLUG_PATTERN, $slug ) || 1 !== preg_match( self::VERSION_PATTERN, $version ) ) {
			return '';
		}
		return 'https://' . self::DOWNLOADS_HOST . '/plugin/' . $slug . '.' . $version . '.zip';
	}

	/**
	 * Whether WordPress.org serves a package: a HEAD request that answers 200 without redirects.
	 *
	 * @param string $url Package URL.
	 * @return bool
	 */
	public static function exists( string $url ): bool {
		$response = wp_safe_remote_head(
			$url,
			array(
				'timeout'     => self::HEAD_TIMEOUT,
				'redirection' => 0,
			)
		);
		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Why this site cannot run a release, or '' when it can.
	 *
	 * @param string $name         Plugin name.
	 * @param string $version      Release.
	 * @param string $requires     Required WordPress version, or ''.
	 * @param string $requires_php Required PHP version, or ''.
	 * @return string
	 */
	public static function incompatibility( string $name, string $version, string $requires, string $requires_php ): string {
		if ( '' !== $requires && ! is_wp_version_compatible( $requires ) ) {
			return sprintf( '%s %s requires WordPress %s; this site runs %s.', $name, $version, $requires, self::wp_version() );
		}
		if ( '' !== $requires_php && ! is_php_version_compatible( $requires_php ) ) {
			return sprintf( '%s %s requires PHP %s; this site runs %s.', $name, $version, $requires_php, self::php_version() );
		}
		return '';
	}

	/**
	 * Requirements against this site, for previews.
	 *
	 * @param string $requires     Required WordPress version, or ''.
	 * @param string $requires_php Required PHP version, or ''.
	 * @return array{WordPress: string, php: string}
	 */
	public static function requirements( string $requires, string $requires_php ): array {
		return array(
			'wordpress' => sprintf( '%s, this site has %s', '' === $requires ? 'no minimum' : 'requires ' . $requires, self::wp_version() ),
			'php'       => sprintf( '%s, this site has %s', '' === $requires_php ? 'no minimum' : 'requires ' . $requires_php, self::php_version() ),
		);
	}

	/**
	 * Plugin page and changelog links on WordPress.org.
	 *
	 * @param string $slug Slug.
	 * @return array{plugin_page: string, changelog: string}
	 */
	public static function links( string $slug ): array {
		$page = 'https://wordpress.org/plugins/' . $slug . '/';
		return array(
			'plugin_page' => $page,
			'changelog'   => $page . '#developers',
		);
	}

	/**
	 * This site's WordPress version.
	 */
	private static function wp_version(): string {
		return (string) get_bloginfo( 'version' );
	}

	/**
	 * This site's PHP version.
	 */
	private static function php_version(): string {
		return (string) phpversion();
	}

	/**
	 * A directory field as plain text ('' when WordPress.org sends false or nothing).
	 *
	 * @param array<string, mixed> $info Directory data.
	 * @param string               $key  Field.
	 * @return string
	 */
	private static function field( array $info, string $key ): string {
		return isset( $info[ $key ] ) && is_scalar( $info[ $key ] ) && false !== $info[ $key ] ? trim( wp_strip_all_tags( (string) $info[ $key ] ) ) : '';
	}
}
