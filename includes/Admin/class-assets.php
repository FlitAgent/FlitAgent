<?php
/**
 * Compiled scripts and styles.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Locates the files @wordpress/scripts compiles from src/ into build/ (`pnpm build`), with the dependencies and
 * content-hash version it writes to build/<entry>.asset.php.
 */
final class Assets {

	/**
	 * Folder of the compiled files, relative to the plugin.
	 */
	const DIR = 'build';

	/**
	 * Dependencies and version of an entry. Without a build, no dependencies and the plugin version.
	 *
	 * @param string      $entry Entry name (admin).
	 * @param string|null $dir   Build folder; the plugin's by default.
	 * @return array{dependencies: string[], version: string}
	 */
	public static function meta( string $entry, ?string $dir = null ): array {
		$file = ( null === $dir ? dirname( FLITAGENT_FILE ) . '/' . self::DIR : $dir ) . '/' . $entry . '.asset.php';
		$data = is_file( $file ) ? include $file : null;

		return array(
			'dependencies' => is_array( $data ) && isset( $data['dependencies'] ) && is_array( $data['dependencies'] ) ? array_values( $data['dependencies'] ) : array(),
			'version'      => is_array( $data ) && isset( $data['version'] ) && is_string( $data['version'] ) ? $data['version'] : FLITAGENT_VERSION,
		);
	}

	/**
	 * URL of a compiled file.
	 *
	 * @param string $file File name, such as admin.css.
	 * @return string
	 */
	public static function url( string $file ): string {
		return plugins_url( self::DIR . '/' . $file, FLITAGENT_FILE );
	}
}
