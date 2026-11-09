<?php
/**
 * Installed plugins and the preflight checks shared by the plugin package tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Plugins;

use FlitAgent\Writes\Assessment;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only helpers: the preflight every plugin install, update and delete passes before anything is written, and the
 * installed state of one plugin (version, active, fingerprint).
 */
final class Site {

	/**
	 * Refusal for a write this site cannot take, or null when it can: multisite, file modifications disallowed
	 * (`DISALLOW_FILE_MODS` or the `file_mod_allowed` filter for context `flitagent_plugin_<action>`), a filesystem
	 * WordPress cannot write directly (it would ask for FTP or SSH credentials), or a missing capability.
	 *
	 * @param string $action     `install`, `update` or `delete`.
	 * @param bool   $capability Whether a missing capability is refused here (false when the tool checks it per plugin).
	 * @return Assessment|null
	 */
	public static function preflight( string $action, bool $capability ): ?Assessment {
		self::load_admin();
		if ( is_multisite() ) {
			return Assessment::prohibited( 'multisite', sprintf( 'FlitAgent does not %s plugins on a multisite network; use Network Admin.', $action ) );
		}
		if ( ! wp_is_file_mod_allowed( 'flitagent_plugin_' . $action ) ) {
			return Assessment::prohibited( 'file_mods_disallowed', sprintf( 'This site does not allow plugin files to be changed (DISALLOW_FILE_MODS or a file_mod_allowed filter), so FlitAgent cannot %s plugins here.', $action ) );
		}
		if ( 'direct' !== get_filesystem_method( array(), defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' ) ) {
			return Assessment::prohibited( 'filesystem', sprintf( 'WordPress cannot write plugin files directly on this server (it would ask for FTP or SSH credentials), so FlitAgent cannot %s plugins here; use wp-admin.', $action ) );
		}
		if ( $capability && ! current_user_can( $action . '_plugins' ) ) {
			return Assessment::prohibited( 'forbidden', sprintf( 'You cannot %s plugins on this site.', $action ) );
		}
		return null;
	}

	/**
	 * Header data of an installed plugin, or null.
	 *
	 * @param string $plugin Plugin file, e.g. `akismet/akismet.php`.
	 * @return array<string, mixed>|null
	 */
	public static function plugin( string $plugin ): ?array {
		self::load_admin();
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) || is_wp_error( validate_plugin( $plugin ) ) ) {
			return null;
		}
		return (array) $plugins[ $plugin ];
	}

	/**
	 * File of the installed plugin in folder `$slug`, or `$slug.php` in the plugins folder, or null.
	 *
	 * @param string $slug Folder name.
	 * @return string|null
	 */
	public static function find_by_slug( string $slug ): ?string {
		self::load_admin();
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( 0 === strpos( (string) $file, $slug . '/' ) || $slug . '.php' === $file ) {
				return (string) $file;
			}
		}
		return null;
	}

	/**
	 * WordPress.org slug of a plugin file: its folder, or '' for a single file in the plugins folder.
	 *
	 * @param string $plugin Plugin file.
	 * @return string
	 */
	public static function slug_of( string $plugin ): string {
		$slug = dirname( $plugin );
		return '.' === $slug ? '' : $slug;
	}

	/**
	 * Whether a plugin file is FlitAgent itself.
	 *
	 * @param string $plugin Plugin file.
	 * @return bool
	 */
	public static function is_self( string $plugin ): bool {
		return defined( 'FLITAGENT_FILE' ) && plugin_basename( FLITAGENT_FILE ) === $plugin;
	}

	/**
	 * Whether a WordPress.org slug is FlitAgent's own folder.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public static function is_self_slug( string $slug ): bool {
		return defined( 'FLITAGENT_FILE' ) && self::slug_of( plugin_basename( FLITAGENT_FILE ) ) === $slug;
	}

	/**
	 * Installed version, or '' when not installed.
	 *
	 * @param string $plugin Plugin file.
	 * @return string
	 */
	public static function version( string $plugin ): string {
		$data = self::plugin( $plugin );
		return null === $data ? '' : self::text( $data, 'Version' );
	}

	/**
	 * Display name of an installed plugin (its file when it has none).
	 *
	 * @param string               $plugin Plugin file.
	 * @param array<string, mixed> $data   Header data.
	 * @return string
	 */
	public static function name( string $plugin, array $data ): string {
		$name = self::text( $data, 'Name' );
		return '' === $name ? $plugin : $name;
	}

	/**
	 * Whether the plugin is active on this site or network-wide.
	 *
	 * @param string $plugin Plugin file.
	 * @return bool
	 */
	public static function is_active( string $plugin ): bool {
		return (bool) is_plugin_active( $plugin ) || (bool) is_plugin_active_for_network( $plugin );
	}

	/**
	 * Fingerprint of a plugin's installed state, compared before a rollback.
	 *
	 * @param string $plugin Plugin file.
	 * @return string
	 */
	public static function fingerprint( string $plugin ): string {
		$version = self::version( $plugin );
		if ( '' === $version && null === self::plugin( $plugin ) ) {
			return $plugin . '|absent';
		}
		return $plugin . '|' . $version . '|' . ( self::is_active( $plugin ) ? 'active' : 'inactive' );
	}

	/**
	 * A header field as plain text.
	 *
	 * @param array<string, mixed> $data Header data.
	 * @param string               $key  Field.
	 * @return string
	 */
	public static function text( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? trim( wp_strip_all_tags( (string) $data[ $key ] ) ) : '';
	}

	/**
	 * Loads the wp-admin plugin and file functions when a REST request has not.
	 */
	public static function load_admin(): void {
		foreach ( array(
			'get_plugins'           => 'plugin.php',
			'get_filesystem_method' => 'file.php',
			'plugins_api'           => 'plugin-install.php',
		) as $function => $file ) {
			if ( ! function_exists( $function ) && is_readable( ABSPATH . 'wp-admin/includes/' . $file ) ) {
				require_once ABSPATH . 'wp-admin/includes/' . $file;
			}
		}
	}
}
