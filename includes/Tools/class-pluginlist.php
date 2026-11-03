<?php
/**
 * Tool: list_plugins.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;

defined( 'ABSPATH' ) || exit;

/**
 * Lists installed plugins with their status and any update WordPress already found.
 */
final class PluginList implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		$string = array( 'type' => 'string' );

		return array(
			'name'         => $this->name(),
			'title'        => 'List FlitAgent site plugins',
			'description'  => 'From the FlitAgent WordPress plugin: lists the installed plugins with name, slug, version, whether each is active, and whether an update is available (from the last update check WordPress saved; this tool never checks for updates itself). Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'plugins'            => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'             => $string,
								'slug'             => $string,
								'version'          => $string,
								'status'           => array(
									'type' => 'string',
									'enum' => array( 'active', 'inactive' ),
								),
								'update_available' => array( 'type' => 'boolean' ),
								'new_version'      => array( 'type' => array( 'string', 'null' ) ),
							),
							'required'   => array( 'name', 'slug', 'version', 'status', 'update_available', 'new_version' ),
						),
					),
					'updates_checked_at' => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'When WordPress last checked for updates (ISO 8601, UTC); null if it has not checked yet.',
					),
				),
				'required'   => array( 'plugins', 'updates_checked_at' ),
			),
			'annotations'  => Annotations::read_only( 'List FlitAgent site plugins' ),
		);
	}

	/**
	 * Lists the plugins. Takes no arguments.
	 *
	 * @param array<string, mixed> $arguments Ignored.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array(), $this->name() );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$updates = new CachedUpdates( 'update_plugins' );
		$plugins = array();

		foreach ( get_plugins() as $file => $data ) {
			$file        = (string) $file;
			$version     = isset( $data['Version'] ) ? (string) $data['Version'] : '';
			$new_version = $updates->new_version( $file, $version );

			$plugins[] = array(
				'name'             => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'slug'             => self::slug( $file ),
				'version'          => $version,
				'status'           => is_plugin_active( $file ) ? 'active' : 'inactive',
				'update_available' => null !== $new_version,
				'new_version'      => $new_version,
			);
		}

		return array(
			'plugins'            => $plugins,
			'updates_checked_at' => $updates->checked_at(),
		);
	}

	/**
	 * Turns a plugin file (`akismet/akismet.php`, `hello.php`) into its slug, so no file path is returned.
	 *
	 * @param string $file Plugin file relative to the plugins folder.
	 * @return string
	 */
	private static function slug( string $file ): string {
		$slash = strpos( $file, '/' );
		if ( false !== $slash ) {
			return substr( $file, 0, $slash );
		}
		return basename( $file, '.php' );
	}
}
