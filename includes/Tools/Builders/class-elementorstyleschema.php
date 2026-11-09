<?php
/**
 * Tool: get_elementor_style_schema.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the style props allowed in Elementor 4 atomic styles (local element styles and global classes).
 */
final class ElementorStyleSchema implements Tool {

	/**
	 * Returns the style schema.
	 *
	 * @var callable
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Returns the schema like LiveSources::elementor_style(). Defaults to the live schema.
	 */
	public function __construct( ?callable $source = null ) {
		$this->source = $source ?? array( LiveSources::class, 'elementor_style' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_elementor_style_schema';
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
			'Get FlitAgent Elementor style schema',
			'returns the style props allowed in Elementor 4 atomic styles (local element styles and global classes): name and type key; with names or prefix also kind, default, options and shape. Needs Elementor 4 or later.',
			Args::filter_schema( 'style props' ),
			array(),
			array(
				'elementor_version' => array( 'type' => array( 'string', 'null' ) ),
				'mode'              => array(
					'type' => 'string',
					'enum' => array( 'discovery', 'targeted', 'prefix' ),
				),
				'count'             => array( 'type' => 'integer' ),
				'props'             => array( 'type' => 'array' ),
			)
		);
	}

	/**
	 * Returns the style props.
	 *
	 * @param array<string, mixed> $arguments Optional names or prefix.
	 * @return array<string, mixed>
	 * @throws ToolError On invalid arguments, or when this Elementor has no atomic style schema.
	 */
	public function call( array $arguments ): array {
		Args::only( $arguments, array( 'names', 'prefix' ) );
		$filter = Args::filter( $arguments );

		$source = ( $this->source )();
		if ( ! is_array( $source['schema'] ) ) {
			throw new ToolError( sprintf( 'This Elementor version (%s) has no atomic style schema; it needs Elementor 4 or later.', esc_textarea( $source['version'] ?? 'unknown' ) ) );
		}

		$props = ElementorSchema::props( $source['schema'], $filter );

		return Output::finish(
			array(
				'elementor_version' => $source['version'],
				'mode'              => $filter['mode'],
				'count'             => count( $props ),
				'props'             => $props,
			)
		);
	}
}
