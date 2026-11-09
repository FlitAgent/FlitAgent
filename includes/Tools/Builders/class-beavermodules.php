<?php
/**
 * Tool: list_beaver_modules.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Mcp\Tool;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the modules Beaver Builder has loaded.
 */
final class BeaverModules implements Tool {

	/**
	 * Returns the module registry.
	 *
	 * @var callable
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Returns modules like LiveSources::beaver(). Defaults to the live registry.
	 */
	public function __construct( ?callable $source = null ) {
		$this->source = $source ?? array( LiveSources::class, 'beaver' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_beaver_modules';
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
		return Definition::read_only(
			$this->name(),
			'List FlitAgent Beaver Builder modules',
			'lists the Beaver Builder modules loaded on this site, sorted by slug, with name, category and whether the module is enabled. Use the slug with get_beaver_module_schema.',
			array(),
			array(),
			array(
				'count'   => array( 'type' => 'integer' ),
				'modules' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'     => array( 'type' => 'string' ),
							'name'     => array( 'type' => 'string' ),
							'category' => array( 'type' => array( 'string', 'null' ) ),
							'enabled'  => array( 'type' => 'boolean' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Lists the modules.
	 *
	 * @param array<string, mixed> $arguments None accepted.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		Args::only( $arguments, array() );

		$modules = array();
		foreach ( ( $this->source )() as $slug => $module ) {
			$modules[] = array(
				'slug'     => (string) $slug,
				'name'     => isset( $module->name ) && is_string( $module->name ) && '' !== $module->name ? $module->name : (string) $slug,
				'category' => isset( $module->category ) && is_string( $module->category ) ? $module->category : null,
				'enabled'  => ! isset( $module->enabled ) || (bool) $module->enabled,
			);
		}
		usort(
			$modules,
			function ( $a, $b ) {
				return strcmp( $a['slug'], $b['slug'] );
			}
		);

		return Output::finish(
			array(
				'count'   => count( $modules ),
				'modules' => $modules,
			)
		);
	}
}
