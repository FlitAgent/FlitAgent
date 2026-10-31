<?php
/**
 * What health triage looks at.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Triage;

use FlitAgent\Tools\CachedUpdates;
use FlitAgent\Tools\SiteHealth;
use FlitAgent\Tools\System\Constants;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the facts Triage needs from WordPress, as plain arrays. Uses only data WordPress already has: cached update
 * checks, the cron array, the saved Site Health result. Makes no HTTP requests and changes nothing.
 */
final class SiteSnapshot {

	/**
	 * The snapshot.
	 *
	 * @return array<string, mixed>
	 */
	public static function read(): array {
		$constants = new Constants();

		return array(
			'now'               => time(),
			'plugins'           => self::plugins(),
			'themes'            => self::themes(),
			'default_theme'     => self::default_theme(),
			'core_update'       => self::core_update(),
			'cron'              => self::cron(),
			'disable_wp_cron'   => $constants->is_true( 'DISABLE_WP_CRON' ),
			'maintenance_since' => self::maintenance_since(),
			'debug'             => $constants->is_true( 'WP_DEBUG' ),
			// WordPress defines WP_DEBUG_DISPLAY as true when wp-config.php does not.
			'debug_display'     => (bool) $constants->get( 'WP_DEBUG_DISPLAY', true ),
			'site_health'       => SiteHealth::saved_counts(),
		);
	}

	/**
	 * Installed plugins with their cached update.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$updates = new CachedUpdates( 'update_plugins' );
		$out     = array();
		foreach ( get_plugins() as $file => $data ) {
			$version = (string) ( $data['Version'] ?? '' );
			$out[]   = array(
				'file'    => (string) $file,
				'name'    => (string) ( $data['Name'] ?? $file ),
				'version' => $version,
				'active'  => is_plugin_active( $file ),
				'update'  => $updates->new_version( (string) $file, $version ),
			);
		}
		return $out;
	}

	/**
	 * Installed themes with their cached update.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function themes(): array {
		$active  = get_stylesheet();
		$parent  = get_template();
		$updates = new CachedUpdates( 'update_themes' );
		$out     = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$version = (string) $theme->get( 'Version' );
			$out[]   = array(
				'stylesheet' => (string) $stylesheet,
				'name'       => (string) $theme->get( 'Name' ),
				'version'    => $version,
				'active'     => $active === $stylesheet,
				'parent'     => $parent !== $active && $parent === $stylesheet,
				'update'     => $updates->new_version( (string) $stylesheet, $version ),
			);
		}
		return $out;
	}

	/**
	 * The newest installed default (Twenty-) theme, which WordPress falls back to.
	 */
	private static function default_theme(): string {
		$theme = \WP_Theme::get_core_default_theme();
		if ( $theme ) {
			return (string) $theme->get_stylesheet();
		}
		return defined( 'WP_DEFAULT_THEME' ) ? (string) WP_DEFAULT_THEME : '';
	}

	/**
	 * The stable core version WordPress.org last offered, if newer than the installed one.
	 */
	private static function core_update(): ?string {
		$cached = get_site_transient( 'update_core' );
		if ( ! is_object( $cached ) || empty( $cached->updates ) ) {
			return null;
		}
		$installed = (string) get_bloginfo( 'version' );
		foreach ( (array) $cached->updates as $offer ) {
			$offer   = (array) $offer;
			$version = (string) ( $offer['current'] ?? '' );
			if ( 'upgrade' === ( $offer['response'] ?? '' ) && '' !== $version && version_compare( $version, $installed, '>' ) ) {
				return $version;
			}
		}
		return null;
	}

	/**
	 * Scheduled events: hook and due time.
	 *
	 * @return array<int, array{hook: string, timestamp: int}>
	 */
	private static function cron(): array {
		$crons  = _get_cron_array();
		$events = array();
		foreach ( is_array( $crons ) ? $crons : array() as $timestamp => $hooks ) {
			foreach ( is_array( $hooks ) ? $hooks : array() as $hook => $instances ) {
				foreach ( is_array( $instances ) ? $instances : array() as $unused ) {
					$events[] = array(
						'hook'      => (string) $hook,
						'timestamp' => (int) $timestamp,
					);
				}
			}
		}
		return $events;
	}

	/**
	 * When the .maintenance file was written, null when there is none.
	 */
	private static function maintenance_since(): ?int {
		$file = ABSPATH . '.maintenance';
		if ( ! file_exists( $file ) ) {
			return null;
		}
		$time = filemtime( $file );
		return false === $time ? null : (int) $time;
	}
}
