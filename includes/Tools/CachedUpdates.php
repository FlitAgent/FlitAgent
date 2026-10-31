<?php
/**
 * Reads update information WordPress already cached.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the `update_plugins` or `update_themes` site transient. Never triggers an update check or HTTP request.
 */
final class CachedUpdates {

	/**
	 * Cached transient value, or null when WordPress has not checked yet.
	 *
	 * @var object|null
	 */
	private $cache;

	/**
	 * Constructor.
	 *
	 * @param string $transient `update_plugins` or `update_themes`.
	 */
	public function __construct( string $transient ) {
		$cache       = get_site_transient( $transient );
		$this->cache = is_object( $cache ) ? $cache : null;
	}

	/**
	 * When WordPress last checked for updates, in ISO 8601 (UTC).
	 *
	 * @return string|null Null when it has not checked yet.
	 */
	public function checked_at(): ?string {
		if ( null === $this->cache || empty( $this->cache->last_checked ) || ! is_numeric( $this->cache->last_checked ) ) {
			return null;
		}
		return gmdate( 'c', (int) $this->cache->last_checked );
	}

	/**
	 * The cached newer version for an item.
	 *
	 * @param string $key       Plugin file or theme stylesheet, as WordPress keys the cache.
	 * @param string $installed Installed version.
	 * @return string|null Null when no newer version is cached. A cached version not newer than the installed one is stale and ignored.
	 */
	public function new_version( string $key, string $installed ): ?string {
		if ( null === $this->cache || ! isset( $this->cache->response ) || ! is_array( $this->cache->response ) || ! isset( $this->cache->response[ $key ] ) ) {
			return null;
		}

		$entry   = (array) $this->cache->response[ $key ];
		$version = isset( $entry['new_version'] ) && is_scalar( $entry['new_version'] ) ? (string) $entry['new_version'] : '';

		if ( '' === $version || ! version_compare( $version, $installed, '>' ) ) {
			return null;
		}
		return $version;
	}
}
