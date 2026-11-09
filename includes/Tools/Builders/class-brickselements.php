<?php
/**
 * Tool: list_bricks_elements.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Mcp\Tool;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the elements registered with Bricks.
 */
final class BricksElements implements Tool {

	/**
	 * Returns the element registry.
	 *
	 * @var callable
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Returns the registry like LiveSources::bricks(). Defaults to the live registry.
	 */
	public function __construct( ?callable $source = null ) {
		$this->source = $source ?? array( LiveSources::class, 'bricks' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_bricks_elements';
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
			'List FlitAgent Bricks elements',
			'lists every element registered with Bricks on this site, sorted by name, with a label and whether it can hold child elements (nestable; null when unknown). The name is the element "name" used in get_bricks_page layouts.',
			array(),
			array(),
			array(
				'count'    => array( 'type' => 'integer' ),
				'elements' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'     => array( 'type' => 'string' ),
							'label'    => array( 'type' => 'string' ),
							'nestable' => array( 'type' => array( 'boolean', 'null' ) ),
						),
					),
				),
			)
		);
	}

	/**
	 * Lists the elements.
	 *
	 * @param array<string, mixed> $arguments None accepted.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		Args::only( $arguments, array() );

		$elements = array();
		foreach ( ( $this->source )() as $name => $element ) {
			$name  = (string) $name;
			$label = is_array( $element ) && ! empty( $element['label'] ) ? (string) $element['label'] : ucwords( str_replace( '-', ' ', $name ) );
			$class = is_array( $element ) && isset( $element['class'] ) && is_string( $element['class'] ) ? $element['class'] : '';

			$nestable = null;
			if ( '' !== $class && class_exists( $class ) ) {
				$vars     = get_class_vars( $class );
				$nestable = ! empty( $vars['nestable'] );
			}

			$elements[] = array(
				'name'     => $name,
				'label'    => $label,
				'nestable' => $nestable,
			);
		}
		usort(
			$elements,
			function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return Output::finish(
			array(
				'count'    => count( $elements ),
				'elements' => $elements,
			)
		);
	}
}
