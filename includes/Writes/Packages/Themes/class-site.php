<?php
/**
 * Theme package checks shared by the install, update and delete tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Themes;

use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Ops\ErrorText;

defined( 'ABSPATH' ) || exit;

/**
 * Preflight refusals (capability, multisite, file modifications, filesystem method), installed theme state and
 * fingerprints, and loading of the wp-admin files the upgrader needs.
 */
final class Site {

	/**
	 * Fingerprint version of a theme that is not installed.
	 */
	const ABSENT = 'absent';

	/**
	 * Loads wp-admin files (theme.php, file.php, misc.php) that a REST request does not load, when present.
	 */
	public static function load_admin(): void {
		foreach ( array( 'file.php', 'misc.php', 'theme.php' ) as $file ) {
			$path = ABSPATH . 'wp-admin/includes/' . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * A refusal when the user lacks the capability or the site cannot change theme files here, else null.
	 *
	 * @param string $capability `install_themes`, `update_themes` or `delete_themes`.
	 * @param string $context    Context passed to wp_is_file_mod_allowed().
	 * @return Assessment|null
	 */
	public static function preflight( string $capability, string $context ): ?Assessment {
		// Core removes these capabilities when file changes are disallowed: name that cause rather than the capability.
		if ( ! wp_is_file_mod_allowed( $context ) ) {
			return Assessment::prohibited( 'file_mods_disallowed', 'This site does not allow file changes (DISALLOW_FILE_MODS or the file_mod_allowed filter), so themes cannot be installed, updated or deleted.' );
		}
		if ( ! current_user_can( $capability ) ) {
			return Assessment::prohibited( 'forbidden', sprintf( 'This needs the %s capability.', $capability ) );
		}
		if ( is_multisite() ) {
			return Assessment::prohibited( 'multisite', 'Installing, updating and deleting themes is not available on multisite.' );
		}
		self::load_admin();
		$method = (string) get_filesystem_method( array(), get_theme_root() );
		if ( 'direct' !== $method ) {
			return Assessment::prohibited( 'filesystem', sprintf( 'WordPress cannot write the themes folder directly (filesystem method: %s); it would ask for FTP or SSH credentials, which FlitAgent never handles.', $method ) );
		}
		return null;
	}

	/**
	 * The installed theme, or null.
	 *
	 * @param string $slug Directory name.
	 * @return object|null
	 */
	public static function theme( string $slug ) {
		$theme = wp_get_theme( $slug );
		return $theme->exists() ? $theme : null;
	}

	/**
	 * Installed version, or '' when not installed.
	 *
	 * @param string $slug Directory name.
	 * @return string
	 */
	public static function version( string $slug ): string {
		$theme = self::theme( $slug );
		return null === $theme ? '' : (string) $theme->get( 'Version' );
	}

	/**
	 * Fingerprint of a theme's presence and version: `slug|version` or `slug|absent`.
	 *
	 * @param string $slug Directory name.
	 * @return string
	 */
	public static function fingerprint( string $slug ): string {
		$theme = self::theme( $slug );
		return $slug . '|' . ( null === $theme ? self::ABSENT : (string) $theme->get( 'Version' ) );
	}

	/**
	 * How a theme is in use: '' (not), 'active' or 'parent' (of the active theme).
	 *
	 * @param string $slug Directory name.
	 * @return string
	 */
	public static function use_of( string $slug ): string {
		if ( (string) get_stylesheet() === $slug ) {
			return 'active';
		}
		return (string) get_template() === $slug ? 'parent' : '';
	}

	/**
	 * Installed child themes of a theme.
	 *
	 * @param string $slug Directory name.
	 * @return string[]
	 */
	public static function children( string $slug ): array {
		$children = array();
		foreach ( wp_get_themes( array( 'errors' => null ) ) as $stylesheet => $theme ) {
			if ( (string) $stylesheet !== $slug && (string) $theme->get_template() === $slug ) {
				$children[] = (string) $stylesheet;
			}
		}
		return $children;
	}

	/**
	 * Refusal for a theme in use or with child themes, else null.
	 *
	 * @param string $slug Directory name.
	 * @param string $verb What would happen, e.g. "deleted".
	 * @return Assessment|null
	 */
	public static function in_use( string $slug, string $verb ): ?Assessment {
		$use = self::use_of( $slug );
		if ( 'active' === $use ) {
			return Assessment::prohibited( 'active', sprintf( 'Theme %s is the active theme, so it cannot be %s. Switch to another theme first.', $slug, $verb ) );
		}
		if ( 'parent' === $use ) {
			return Assessment::prohibited( 'active', sprintf( 'Theme %s is the parent of the active theme %s, so it cannot be %s.', $slug, (string) get_stylesheet(), $verb ) );
		}
		$children = self::children( $slug );
		if ( array() !== $children ) {
			return Assessment::prohibited( 'has_children', sprintf( 'Installed child themes depend on %s (%s), so it cannot be %s; they would break.', $slug, implode( ', ', $children ), $verb ) );
		}
		return null;
	}

	/**
	 * Requirement problem of a WordPress.org entry, or '' when the site meets it.
	 *
	 * @param array<string, string> $info Directory entry.
	 * @return string
	 */
	public static function incompatibility( array $info ): string {
		if ( ! is_wp_version_compatible( $info['requires'] ) ) {
			return sprintf( '%s %s needs WordPress %s or newer.', $info['name'], $info['version'], $info['requires'] );
		}
		if ( ! is_php_version_compatible( $info['requires_php'] ) ) {
			return sprintf( '%s %s needs PHP %s or newer.', $info['name'], $info['version'], $info['requires_php'] );
		}
		return '';
	}

	/**
	 * Safe message of an error value (HTML and file paths removed).
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function clean( string $message ): string {
		return ErrorText::clean( $message );
	}
}
