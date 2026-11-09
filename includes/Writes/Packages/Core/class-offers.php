<?php
/**
 * Core update offers WordPress cached.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Core;

use FlitAgent\Writes\Assessment;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the `update_core` site transient that `wp_version_check()` fills from WordPress.org. Never checks for updates
 * itself (that writes the transient and contacts WordPress.org), so a missing or stale cache is a refusal.
 */
final class Offers {

	/**
	 * Oldest usable check, in seconds. WordPress checks twice a day.
	 */
	const MAX_AGE = 86400;

	/**
	 * Offer types that are updates. `latest` is a reinstall and `development` a nightly build.
	 */
	const UPDATE_TYPES = array( 'upgrade', 'autoupdate' );

	/**
	 * Guidance when the cache cannot be used.
	 */
	const CHECK_FIRST = 'check for updates first: open Dashboard > Updates in wp-admin (or wait for WordPress\'s twice-daily check), then confirm the offer with get_core_updates and try again.';

	/**
	 * Cached transient, or null.
	 *
	 * @var object|null
	 */
	private $cache;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$cache       = get_site_transient( 'update_core' );
		$this->cache = is_object( $cache ) ? $cache : null;
	}

	/**
	 * When WordPress last checked, in ISO 8601 (UTC), or null.
	 */
	public function checked_at(): ?string {
		return null === $this->cache || empty( $this->cache->last_checked ) ? null : gmdate( 'c', (int) $this->cache->last_checked );
	}

	/**
	 * A refusal when the cache is missing, stale or was made for another installed version.
	 *
	 * @param string $installed Installed version.
	 * @return Assessment|null
	 */
	public function unusable( string $installed ): ?Assessment {
		if ( null === $this->cache || empty( $this->cache->last_checked ) || ! isset( $this->cache->updates ) || ! is_array( $this->cache->updates ) ) {
			return Assessment::prohibited( 'no_update_data', 'WordPress has no cached core update information yet; ' . self::CHECK_FIRST );
		}
		if ( time() - (int) $this->cache->last_checked > self::MAX_AGE ) {
			return Assessment::prohibited( 'stale_update_data', sprintf( 'WordPress last checked for core updates at %s, more than 24 hours ago; ', $this->checked_at() ) . self::CHECK_FIRST );
		}
		$checked_for = isset( $this->cache->version_checked ) ? (string) $this->cache->version_checked : '';
		if ( $checked_for !== $installed ) {
			return Assessment::prohibited( 'stale_update_data', sprintf( 'The cached core update information was fetched for WordPress %s, but %s is installed; ', '' === $checked_for ? 'an unknown version' : $checked_for, $installed ) . self::CHECK_FIRST );
		}
		return null;
	}

	/**
	 * The offer to install, or a refusal.
	 *
	 * @param string      $version   Requested version.
	 * @param string|null $locale    Requested locale, or null for the site locale (then en_US).
	 * @param string      $installed Installed version.
	 * @return object|Assessment
	 */
	public function find( string $version, ?string $locale, string $installed ) {
		if ( $version === $installed ) {
			return Assessment::prohibited( 'reinstall', sprintf( 'WordPress %s is already installed. Reinstalling is not offered; use Dashboard > Updates > Re-install in wp-admin.', $version ) );
		}
		if ( version_compare( $version, $installed, '<' ) ) {
			return Assessment::prohibited( 'downgrade', sprintf( 'WordPress %s is older than the installed %s. Downgrading is not offered.', $version, $installed ) );
		}

		$development = Assessment::prohibited( 'development', sprintf( 'WordPress %s is a development build (beta, release candidate or nightly). Only stable releases are offered.', $version ) );
		if ( false !== strpos( $version, '-' ) ) {
			return $development;
		}

		$matching = array();
		foreach ( $this->offers() as $offer ) {
			if ( (string) $offer->current !== $version ) {
				continue;
			}
			if ( 'development' === $offer->response ) {
				return $development;
			}
			if ( in_array( $offer->response, self::UPDATE_TYPES, true ) ) {
				$matching[] = $offer;
			}
		}
		if ( array() === $matching ) {
			$offered = $this->offered_versions();
			return Assessment::prohibited( 'not_offered', sprintf( 'WordPress.org does not offer WordPress %s as an update for this site. ', $version ) . ( array() === $offered ? 'No update is offered.' : 'Offered: ' . implode( ', ', $offered ) . '.' ) );
		}

		$wanted = null === $locale ? array( get_locale(), 'en_US' ) : array( $locale );
		foreach ( $wanted as $code ) {
			foreach ( $matching as $offer ) {
				if ( (string) $offer->locale === $code ) {
					return $offer;
				}
			}
		}
		$locales = array_values( array_unique( array_map( 'strval', array_column( array_map( 'get_object_vars', $matching ), 'locale' ) ) ) );
		return Assessment::prohibited( 'not_offered', sprintf( 'WordPress %s is not offered in locale %s. Offered locales: %s.', $version, implode( ' or ', $wanted ), implode( ', ', $locales ) ) );
	}

	/**
	 * Cached offers that have the fields an update needs.
	 *
	 * @return object[]
	 */
	private function offers(): array {
		$offers = array();
		foreach ( null === $this->cache || ! isset( $this->cache->updates ) ? array() : (array) $this->cache->updates as $offer ) {
			if ( is_object( $offer ) && isset( $offer->current, $offer->response, $offer->locale, $offer->packages ) && is_scalar( $offer->current ) ) {
				$offers[] = $offer;
			}
		}
		return $offers;
	}

	/**
	 * Stable versions offered as updates, in offer order.
	 *
	 * @return string[]
	 */
	private function offered_versions(): array {
		$versions = array();
		foreach ( $this->offers() as $offer ) {
			if ( in_array( $offer->response, self::UPDATE_TYPES, true ) && false === strpos( (string) $offer->current, '-' ) ) {
				$versions[] = (string) $offer->current;
			}
		}
		return array_values( array_unique( $versions ) );
	}
}
