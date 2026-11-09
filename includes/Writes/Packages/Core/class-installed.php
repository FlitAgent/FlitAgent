<?php
/**
 * The WordPress version on disk.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `wp-includes/version.php` as text, so the result is what is on disk now: the `$wp_version` and
 * `$wp_db_version` globals keep the values loaded at the start of the request, and including the file again would
 * overwrite them. Also reads the stored `db_version` option past the object cache.
 */
final class Installed {

	/**
	 * Version file, relative to ABSPATH.
	 */
	const VERSION_FILE = 'wp-includes/version.php';

	/**
	 * Maintenance file core writes while it updates, relative to ABSPATH.
	 */
	const MAINTENANCE_FILE = '.maintenance';

	/**
	 * Version and DB version from version.php, or null when it cannot be read.
	 *
	 * @return array{version: string, db_version: int}|null
	 */
	public static function read(): ?array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local core file under ABSPATH.
		$source = file_get_contents( ABSPATH . self::VERSION_FILE );
		if ( ! is_string( $source )
			|| 1 !== preg_match( '/^\s*\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/m', $source, $version )
			|| 1 !== preg_match( '/^\s*\$wp_db_version\s*=\s*(\d+)\s*;/m', $source, $db_version ) ) {
			return null;
		}
		return array(
			'version'    => $version[1],
			'db_version' => (int) $db_version[1],
		);
	}

	/**
	 * The stored `db_version` option, read past the object cache (another request may have just upgraded it).
	 */
	public static function stored_db_version(): int {
		wp_cache_delete( 'db_version', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return (int) get_option( 'db_version' );
	}

	/**
	 * Whether core's maintenance file exists.
	 */
	public static function in_maintenance(): bool {
		return file_exists( ABSPATH . self::MAINTENANCE_FILE );
	}

	/**
	 * Deletes core's maintenance file.
	 */
	public static function end_maintenance(): void {
		wp_delete_file( ABSPATH . self::MAINTENANCE_FILE );
	}
}
