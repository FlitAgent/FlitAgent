<?php
/**
 * What update readiness looks at.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Triage;

use FlitAgent\Writes\Packages\Core\Readiness;

defined( 'ABSPATH' ) || exit;

/**
 * Reads pending updates from WordPress's cached update checks, plus versions and backup status, as plain arrays. Makes
 * no HTTP requests and changes nothing.
 */
final class UpdateSnapshot {

	/**
	 * The snapshot.
	 *
	 * @return array<string, mixed>
	 */
	public static function read(): array {
		$backup = Readiness::backup();
		return array(
			'now'       => time(),
			'wordpress' => (string) get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'plugins'   => self::plugins(),
			'themes'    => self::themes(),
			'core'      => self::core(),
			'backup'    => array(
				'plugin'      => $backup['plugin'],
				'last_backup' => $backup['last_backup'],
			),
		);
	}

	/**
	 * Plugins with a cached newer version.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$response = self::response( 'update_plugins' );
		$out      = array();
		foreach ( get_plugins() as $file => $data ) {
			$entry   = isset( $response[ $file ] ) ? (array) $response[ $file ] : null;
			$version = (string) ( $data['Version'] ?? '' );
			$new     = null === $entry ? '' : self::field( $entry, 'new_version' );
			if ( '' === $new || ! version_compare( $new, $version, '>' ) ) {
				continue;
			}
			$out[] = array(
				'file'         => (string) $file,
				'name'         => (string) ( $data['Name'] ?? $file ),
				'version'      => $version,
				'new_version'  => $new,
				'active'       => is_plugin_active( $file ),
				'requires'     => self::field( $entry, 'requires' ),
				'requires_php' => self::field( $entry, 'requires_php' ),
				'tested'       => self::field( $entry, 'tested' ),
			);
		}
		return $out;
	}

	/**
	 * Themes with a cached newer version.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function themes(): array {
		$response = self::response( 'update_themes' );
		$in_use   = array( get_stylesheet(), get_template() );
		$out      = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$entry   = isset( $response[ $stylesheet ] ) ? (array) $response[ $stylesheet ] : null;
			$version = (string) $theme->get( 'Version' );
			$new     = null === $entry ? '' : self::field( $entry, 'new_version' );
			if ( '' === $new || ! version_compare( $new, $version, '>' ) ) {
				continue;
			}
			$out[] = array(
				'stylesheet'   => (string) $stylesheet,
				'name'         => (string) $theme->get( 'Name' ),
				'version'      => $version,
				'new_version'  => $new,
				'active'       => in_array( $stylesheet, $in_use, true ),
				'requires'     => self::field( $entry, 'requires' ),
				'requires_php' => self::field( $entry, 'requires_php' ),
			);
		}
		return $out;
	}

	/**
	 * The stable core version last offered, if newer, with its PHP requirement.
	 *
	 * @return array{version: string, php_version: string}|null
	 */
	private static function core(): ?array {
		$cached = get_site_transient( 'update_core' );
		if ( ! is_object( $cached ) || empty( $cached->updates ) ) {
			return null;
		}
		$installed = (string) get_bloginfo( 'version' );
		foreach ( (array) $cached->updates as $offer ) {
			$offer   = (array) $offer;
			$version = self::field( $offer, 'current' );
			if ( 'upgrade' === ( $offer['response'] ?? '' ) && '' !== $version && version_compare( $version, $installed, '>' ) ) {
				return array(
					'version'     => $version,
					'php_version' => self::field( $offer, 'php_version' ),
				);
			}
		}
		return null;
	}

	/**
	 * The `response` list of an update transient.
	 *
	 * @param string $transient update_plugins or update_themes.
	 * @return array<string, mixed>
	 */
	private static function response( string $transient ): array {
		$cached = get_site_transient( $transient );
		return is_object( $cached ) && isset( $cached->response ) && is_array( $cached->response ) ? $cached->response : array();
	}

	/**
	 * A scalar field as a string, '' when missing.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @param string               $key   Key.
	 * @return string
	 */
	private static function field( array $entry, string $key ): string {
		return isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) ? (string) $entry[ $key ] : '';
	}
}
