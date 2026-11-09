<?php
/**
 * Tool: list_elementor_widgets.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Mcp\Tool;

defined( 'ABSPATH' ) || exit;

/**
 * Lists Elementor's registered widgets plus structural and atomic elements.
 */
final class ElementorWidgets implements Tool {

	/**
	 * Non-atomic elements that are listed (others are internal).
	 */
	const STRUCTURAL = array( 'container', 'section', 'column' );

	/**
	 * Returns the Elementor registry.
	 *
	 * @var callable
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Returns the registry like LiveSources::elementor(). Defaults to the live registry.
	 */
	public function __construct( ?callable $source = null ) {
		$this->source = $source ?? array( LiveSources::class, 'elementor' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_elementor_widgets';
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
			'List FlitAgent Elementor widgets',
			'lists every Elementor widget registered on this site, plus the structural (container, section, column) and atomic v4 elements, with slug, title, categories, whether it needs Elementor Pro, kind and whether it is atomic. Use the slug with get_elementor_widget_schema.',
			array(),
			array(),
			array(
				'elementor_version' => array( 'type' => array( 'string', 'null' ) ),
				'count'             => array( 'type' => 'integer' ),
				'items'             => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'       => array( 'type' => 'string' ),
							'title'      => array( 'type' => 'string' ),
							'categories' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'is_pro'     => array( 'type' => 'boolean' ),
							'kind'       => array(
								'type' => 'string',
								'enum' => array( 'widget', 'element' ),
							),
							'atomic'     => array( 'type' => 'boolean' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Lists the widgets and elements.
	 *
	 * @param array<string, mixed> $arguments None accepted.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		Args::only( $arguments, array() );
		$registry = ( $this->source )();

		$items = array();
		foreach ( $registry['widgets'] as $slug => $widget ) {
			$items[] = array(
				'slug'       => (string) $slug,
				'title'      => (string) $widget->get_title(),
				'categories' => array_values( (array) $widget->get_categories() ),
				'is_pro'     => ElementorSchema::is_pro( $widget ),
				'kind'       => 'widget',
				'atomic'     => ElementorSchema::is_atomic( $widget ),
			);
		}
		foreach ( $registry['elements'] as $slug => $element ) {
			$atomic = ElementorSchema::is_atomic( $element );
			if ( ! $atomic && ! in_array( $slug, self::STRUCTURAL, true ) ) {
				continue;
			}
			$items[] = array(
				'slug'       => (string) $slug,
				'title'      => (string) $element->get_title(),
				'categories' => $atomic ? array( 'v4-elements', 'structural' ) : array( 'structural' ),
				'is_pro'     => ElementorSchema::is_pro( $element ),
				'kind'       => 'element',
				'atomic'     => $atomic,
			);
		}

		return Output::finish(
			array(
				'elementor_version' => $registry['version'],
				'count'             => count( $items ),
				'items'             => $items,
			)
		);
	}
}
