<?php
/**
 * Tool: get_elementor_widget_schema.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Returns one Elementor widget's or element's settings schema: classic controls, or atomic props.
 */
final class ElementorWidgetSchema implements Tool {

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
		return 'get_elementor_widget_schema';
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
			'Get FlitAgent Elementor widget schema',
			'returns the settings schema of one Elementor widget or element. Classic widgets: value controls (name, type; with names or prefix also default, options, condition, responsive, group prefix). Group-control sub-fields such as typography_* are not expanded. Atomic v4 elements: props (name, type key, union member types; with names or prefix also kind, default, options, required, shape); their visual styling uses get_elementor_style_schema.',
			array_merge(
				array(
					'slug' => array(
						'type'        => 'string',
						'description' => 'Widget or element slug from list_elementor_widgets, e.g. "heading" or "e-heading".',
					),
				),
				Args::filter_schema( 'settings' )
			),
			array( 'slug' ),
			array(
				'slug'              => array( 'type' => 'string' ),
				'kind'              => array(
					'type' => 'string',
					'enum' => array( 'widget', 'element' ),
				),
				'title'             => array( 'type' => 'string' ),
				'is_pro'            => array( 'type' => 'boolean' ),
				'atomic'            => array( 'type' => 'boolean' ),
				'elementor_version' => array( 'type' => array( 'string', 'null' ) ),
				'mode'              => array(
					'type' => 'string',
					'enum' => array( 'discovery', 'targeted', 'prefix' ),
				),
				'count'             => array( 'type' => 'integer' ),
				'settings'          => array(
					'type'        => 'array',
					'description' => 'Controls (classic) or props (atomic).',
				),
			)
		);
	}

	/**
	 * Returns the schema.
	 *
	 * @param array<string, mixed> $arguments slug, optional names or prefix.
	 * @return array<string, mixed>
	 * @throws ToolError On invalid arguments or an unknown slug.
	 */
	public function call( array $arguments ): array {
		Args::only( $arguments, array( 'slug', 'names', 'prefix' ) );
		$slug   = Args::slug( $arguments );
		$filter = Args::filter( $arguments );

		$registry = ( $this->source )();
		$kind     = 'widget';
		$element  = $registry['widgets'][ $slug ] ?? null;
		if ( ! is_object( $element ) ) {
			$kind    = 'element';
			$element = $registry['elements'][ $slug ] ?? null;
		}
		if ( ! is_object( $element ) ) {
			throw new ToolError( sprintf( 'No Elementor widget or element is registered as "%s". List them with list_elementor_widgets.', esc_textarea( $slug ) ) );
		}

		$atomic   = ElementorSchema::is_atomic( $element );
		$settings = array();
		if ( $atomic ) {
			$settings = ElementorSchema::props( (array) $element::get_props_schema(), $filter );
		} elseif ( method_exists( $element, 'get_controls' ) ) {
			$settings = ElementorSchema::controls( (array) $element->get_controls(), $filter );
		}

		return Output::finish(
			array(
				'slug'              => $slug,
				'kind'              => $kind,
				'title'             => (string) $element->get_title(),
				'is_pro'            => ElementorSchema::is_pro( $element ),
				'atomic'            => $atomic,
				'elementor_version' => $registry['version'],
				'mode'              => $filter['mode'],
				'count'             => count( $settings ),
				'settings'          => $settings,
			)
		);
	}
}
