<?php
/**
 * The verify_plugin_checksums tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Http;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Compares installed plugins with the checksums WordPress.org publishes for their version, like
 * `wp plugin verify-checksums`. Plugins not on WordPress.org (premium, custom) are reported as unavailable.
 */
final class PluginChecksums implements Tool {

	/**
	 * WordPress.org plugin checksums (downloads.wordpress.org, not api.wordpress.org).
	 */
	const API = 'https://downloads.wordpress.org/plugin-checksums/';

	/**
	 * Files WordPress.org regenerates after release, so they legitimately differ; WP-CLI skips them unless --strict.
	 */
	const SOFT_CHANGE_FILES = array( 'readme.txt', 'readme.md' );

	/**
	 * Plugins folder.
	 *
	 * @var string|null
	 */
	private $plugins_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $plugins_dir Plugins folder; defaults to WP_PLUGIN_DIR.
	 */
	public function __construct( $plugins_dir = null ) {
		$this->plugins_dir = $plugins_dir;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'verify_plugin_checksums';
	}

	/**
	 * Protected: the result tells an attacker which plugin files differ, and each call makes the site contact
	 * WordPress.org once per plugin.
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		$plugin = CoreChecksums::output_schema(
			array(
				'slug'    => array( 'type' => 'string' ),
				'version' => array( 'type' => 'string' ),
				'status'  => array(
					'type'        => 'string',
					'enum'        => array( 'verified', 'modified', 'unavailable' ),
					'description' => 'unavailable: WordPress.org has no checksums for this plugin version (not hosted there, e.g. premium or custom, or could not be reached).',
				),
			),
			array( 'slug', 'version', 'status', 'counts', 'files', 'truncated' )
		);
		unset( $plugin['properties']['verified'], $plugin['properties']['files_checked'] );

		return array(
			'name'         => $this->name(),
			'title'        => 'Verify plugin files with FlitAgent',
			'description'  => 'From the FlitAgent WordPress plugin: checks installed plugin files against the checksums WordPress.org publishes for each plugin version (like wp plugin verify-checksums). Returns per plugin whether it verifies, and the relative paths of modified, missing or extra files. readme files are skipped. Plugins not on WordPress.org are reported as unavailable. Never returns file contents. Contacts downloads.wordpress.org once per plugin. Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'slug' => array(
						'type'        => 'string',
						'description' => 'Plugin slug (its folder name, e.g. "akismet"). Omit to check every installed plugin.',
					),
				),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'plugins' => array(
						'type'  => 'array',
						'items' => $plugin,
					),
				),
				'required'   => array( 'plugins' ),
			),
			'annotations'  => Annotations::read_only( 'Verify plugin files with FlitAgent', true ),
		);
	}

	/**
	 * Verifies one plugin or all of them.
	 *
	 * @param array<string, mixed> $arguments Optional `slug`.
	 * @return array<string, mixed>
	 * @throws ToolError When the arguments are invalid or the plugin is not installed.
	 */
	public function call( array $arguments ): array {
		foreach ( array_keys( $arguments ) as $key ) {
			if ( 'slug' !== $key ) {
				throw new ToolError( 'Unknown argument. verify_plugin_checksums accepts only slug.' );
			}
		}
		$slug = null;
		if ( isset( $arguments['slug'] ) ) {
			$slug = $arguments['slug'];
			if ( ! is_string( $slug ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug ) ) {
				throw new ToolError( 'slug must be a plugin folder name such as "akismet".' );
			}
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$file   = (string) $file;
			$folder = dirname( $file );
			$single = '.' === $folder;
			$key    = $single ? basename( $file, '.php' ) : $folder;
			if ( null === $slug || $slug === $key ) {
				$plugins[ $key ] = array(
					'file'    => $file,
					'single'  => $single,
					'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				);
			}
		}
		if ( null !== $slug && array() === $plugins ) {
			throw new ToolError( sprintf( 'Plugin "%s" is not installed.', esc_textarea( $slug ) ) );
		}

		$results = array();
		foreach ( $plugins as $key => $plugin ) {
			$results[] = $this->verify( (string) $key, $plugin['file'], $plugin['single'], $plugin['version'] );
		}

		return array( 'plugins' => $results );
	}

	/**
	 * Verifies one plugin.
	 *
	 * @param string $slug    Slug.
	 * @param string $file    Plugin file relative to the plugins folder.
	 * @param bool   $single  Whether it is a single-file plugin in the plugins folder root.
	 * @param string $version Installed version.
	 * @return array<string, mixed>
	 */
	private function verify( string $slug, string $file, bool $single, string $version ): array {
		$manifest = '' === $version ? null : self::manifest( $slug, $version );
		if ( null === $manifest ) {
			return array(
				'slug'      => $slug,
				'version'   => $version,
				'status'    => 'unavailable',
				'counts'    => array(
					'modified' => 0,
					'missing'  => 0,
					'extra'    => 0,
				),
				'files'     => array(),
				'truncated' => false,
			);
		}

		$plugins_dir = rtrim( null === $this->plugins_dir ? WP_PLUGIN_DIR : $this->plugins_dir, '/\\' ) . '/';
		$own_file    = basename( $file );
		$result      = FileChecksums::compare(
			$single ? $plugins_dir : $plugins_dir . dirname( $file ),
			$manifest,
			static function ( string $path ) use ( $single, $own_file ): bool {
				if ( $single ) {
					// The plugins folder root holds other plugins too: only this plugin's own file is in scope.
					return $own_file === $path;
				}
				return ! in_array( strtolower( $path ), self::SOFT_CHANGE_FILES, true );
			},
			$single ? array() : array( '' => true ),
			static function (): bool {
				return true;
			}
		);

		return array(
			'slug'      => $slug,
			'version'   => $version,
			'status'    => $result['verified'] ? 'verified' : 'modified',
			'counts'    => $result['counts'],
			'files'     => $result['files'],
			'truncated' => $result['truncated'],
		);
	}

	/**
	 * Fetches a plugin's checksums.
	 *
	 * @param string $slug    Slug.
	 * @param string $version Version.
	 * @return array<string, string[]>|null Relative path => MD5 hashes, or null if WordPress.org has none.
	 */
	private static function manifest( string $slug, string $version ) {
		$body = FileChecksums::fetch_json( self::API . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json' );
		if ( ! isset( $body['files'] ) || ! is_array( $body['files'] ) || array() === $body['files'] ) {
			return null;
		}
		$manifest = array();
		foreach ( $body['files'] as $path => $hashes ) {
			$manifest[ (string) $path ] = FileChecksums::md5_list( $hashes );
		}
		return $manifest;
	}
}
