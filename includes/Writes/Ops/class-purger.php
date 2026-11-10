<?php
/**
 * Calls each cache plugin's own purge API.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

defined( 'ABSPATH' ) || exit;

/**
 * Purges one engine (an id from Tools\System\CacheEngines) through the plugin's own public API; FlitAgent never deletes
 * cache files itself. Throws when the API is missing or fails, so the caller can report that engine and go on.
 */
final class Purger {

	/**
	 * Engines that can purge a single URL.
	 */
	const URL_ENGINES = array( 'litespeed', 'wp_rocket', 'siteground', 'w3_total_cache', 'wp_super_cache' );

	/**
	 * Whether an engine can purge a single URL.
	 *
	 * @param string $engine Engine id.
	 * @return bool
	 */
	public static function supports_url( string $engine ): bool {
		return in_array( $engine, self::URL_ENGINES, true );
	}

	/**
	 * Purges an engine, everything or one URL.
	 *
	 * @param string      $engine Engine id.
	 * @param string|null $url    URL on this site, or null for everything.
	 * @throws \RuntimeException When the engine's API is not available.
	 */
	public function __invoke( string $engine, ?string $url ): void {
		switch ( $engine ) {
			case 'litespeed':
				if ( null === $url ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's purge API.
					do_action( 'litespeed_purge_all' );
				} else {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's purge API.
					do_action( 'litespeed_purge_url', $url );
				}
				return;
			case 'wp_rocket':
				null === $url ? self::call( 'rocket_clean_domain' ) : self::call( 'rocket_clean_files', array( $url ) );
				return;
			case 'siteground':
				self::call( 'sg_cachepress_purge_cache', null === $url ? false : $url );
				return;
			case 'w3_total_cache':
				null === $url ? self::call( 'w3tc_flush_all' ) : self::call( 'w3tc_flush_url', $url );
				return;
			case 'wp_super_cache':
				null === $url ? self::call( 'wp_cache_clear_cache' ) : self::call( 'wpsc_delete_url_cache', $url );
				return;
			case 'breeze':
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's purge API.
				do_action( 'breeze_clear_all_cache' );
				return;
			case 'cloudflare':
				$class = 'CF\\WordPress\\Hooks';
				if ( ! class_exists( $class, false ) ) {
					throw new \RuntimeException( 'The Cloudflare plugin is not loaded.' );
				}
				( new $class() )->purgeCacheEverything();
				return;
			case 'elementor':
				if ( ! class_exists( 'Elementor\\Plugin', false ) || ! isset( \Elementor\Plugin::$instance->files_manager ) ) {
					throw new \RuntimeException( 'Elementor is not loaded.' );
				}
				\Elementor\Plugin::$instance->files_manager->clear_cache();
				return;
			case 'divi':
				if ( ! is_callable( array( 'ET_Core_PageResource', 'remove_static_resources' ) ) ) {
					throw new \RuntimeException( 'Divi is not loaded.' );
				}
				\ET_Core_PageResource::remove_static_resources( 'all', 'all' );
				return;
			default:
				throw new \RuntimeException( sprintf( 'Unknown cache engine %s.', esc_textarea( $engine ) ) );
		}//end switch
	}

	/**
	 * Calls a plugin function if it exists.
	 *
	 * @param string $name    Function name.
	 * @param mixed  ...$args Arguments.
	 * @throws \RuntimeException When the function does not exist.
	 */
	private static function call( string $name, ...$args ): void {
		if ( ! function_exists( $name ) ) {
			throw new \RuntimeException( sprintf( '%s() is not available.', esc_textarea( $name ) ) );
		}
		call_user_func_array( $name, $args );
	}
}
