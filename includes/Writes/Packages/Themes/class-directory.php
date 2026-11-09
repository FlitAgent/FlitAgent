<?php
/**
 * WordPress.org theme directory lookups.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Themes;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the WordPress.org theme directory with core's themes_api() (no cache, nothing written) and checks packages:
 * only `https://downloads.wordpress.org/theme/<slug>[.<version>].zip` is ever installed.
 */
final class Directory {

	/**
	 * Hosts of an Update URI that still means WordPress.org.
	 */
	const WPORG_HOSTS = array( 'wordpress.org', 'w.org' );

	/**
	 * Package host and path.
	 */
	const DOWNLOADS = 'https://downloads.wordpress.org/theme/';

	/**
	 * Theme page base.
	 */
	const PAGES = 'https://wordpress.org/themes/';

	/**
	 * Timeout of the package HEAD check, in seconds.
	 */
	const TIMEOUT = 10;

	/**
	 * The directory entry of a theme: name, slug, version, package, requires, requires_php, parent (slug or '') and page.
	 *
	 * @param string $slug Theme slug.
	 * @return array<string, string>
	 * @throws DirectoryError When the theme is not in the directory or the directory cannot be reached.
	 */
	public static function info( string $slug ): array {
		Site::load_admin();
		$response = themes_api(
			'theme_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections'       => false,
					'tags'           => false,
					'screenshot_url' => false,
					'rating'         => false,
					'downloaded'     => false,
					'parent'         => true,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new DirectoryError( esc_textarea( Site::clean( (string) $response->get_error_message() ) ) );
		}
		$data = (array) $response;
		if ( ! isset( $data['version'], $data['download_link'] ) || ( isset( $data['slug'] ) && (string) $data['slug'] !== $slug ) ) {
			throw new DirectoryError( 'The directory returned no installable package for this theme.' );
		}
		$parent = '';
		if ( isset( $data['template'] ) && is_string( $data['template'] ) ) {
			$parent = $data['template'];
		} elseif ( isset( $data['parent'] ) ) {
			$entry  = (array) $data['parent'];
			$parent = isset( $entry['slug'] ) ? (string) $entry['slug'] : '';
		}
		return array(
			'name'         => wp_strip_all_tags( (string) ( $data['name'] ?? $slug ) ),
			'slug'         => $slug,
			'version'      => (string) $data['version'],
			'package'      => (string) $data['download_link'],
			'requires'     => isset( $data['requires'] ) && is_scalar( $data['requires'] ) ? (string) $data['requires'] : '',
			'requires_php' => isset( $data['requires_php'] ) && is_scalar( $data['requires_php'] ) ? (string) $data['requires_php'] : '',
			'parent'       => $parent === $slug ? '' : $parent,
			'page'         => self::page( $slug ),
		);
	}

	/**
	 * Theme page URL on WordPress.org.
	 *
	 * @param string $slug Theme slug.
	 * @return string
	 */
	public static function page( string $slug ): string {
		return self::PAGES . $slug . '/';
	}

	/**
	 * Whether a package URL is a WordPress.org download of this theme, over https.
	 *
	 * @param string $url  Package URL.
	 * @param string $slug Theme slug.
	 * @return bool
	 */
	public static function is_package( string $url, string $slug ): bool {
		return 1 === preg_match( '#^' . preg_quote( self::DOWNLOADS . $slug, '#' ) . '(?:\.[0-9A-Za-z][0-9A-Za-z._-]*)?\.zip$#D', $url );
	}

	/**
	 * Package URL of one version.
	 *
	 * @param string $slug    Theme slug.
	 * @param string $version Version.
	 * @return string '' when the version has characters a package name cannot have.
	 */
	public static function version_package( string $slug, string $version ): string {
		return 1 === preg_match( '/^[0-9A-Za-z][0-9A-Za-z._-]*$/D', $version ) ? self::DOWNLOADS . $slug . '.' . $version . '.zip' : '';
	}

	/**
	 * Whether WordPress.org serves a package (HEAD answers 200).
	 *
	 * @param string $url Package URL.
	 * @return bool
	 */
	public static function package_exists( string $url ): bool {
		$response = wp_safe_remote_head(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
			)
		);
		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * The directory entry, or a refusal: not in the directory, a package not on downloads.wordpress.org, or unmet
	 * requirements.
	 *
	 * @param string $slug Theme slug.
	 * @param string $code Refusal code when the theme is not in the directory.
	 * @return array<string, string>|Assessment
	 */
	public static function checked( string $slug, string $code ) {
		try {
			$info = self::info( $slug );
		} catch ( DirectoryError $e ) {
			return Assessment::prohibited( $code, sprintf( 'Theme %s was not found in the WordPress.org theme directory: %s', $slug, ToolError::plain( $e ) ) );
		}
		if ( ! self::is_package( $info['package'], $slug ) ) {
			return Assessment::prohibited( 'package', sprintf( 'The WordPress.org package of %s is not an https download from downloads.wordpress.org, so it is not installed.', $slug ) );
		}
		$problem = Site::incompatibility( $info );
		if ( '' !== $problem ) {
			return Assessment::prohibited( 'requirements', $problem . ' This site does not meet that.' );
		}
		return $info;
	}

	/**
	 * Whether an Update URI still points at WordPress.org (core treats these hosts as the directory).
	 *
	 * @param string $uri Update URI header.
	 * @return bool
	 */
	public static function is_wporg_uri( string $uri ): bool {
		$uri = trim( $uri );
		if ( '' === $uri ) {
			return true;
		}
		$host = wp_parse_url( false === strpos( $uri, '://' ) ? 'https://' . $uri : $uri, PHP_URL_HOST );
		return is_string( $host ) && in_array( strtolower( $host ), self::WPORG_HOSTS, true );
	}
}
