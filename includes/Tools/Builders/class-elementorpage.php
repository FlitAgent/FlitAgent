<?php
/**
 * Tool: get_elementor_page.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a post's Elementor element tree from `_elementor_data`, the JSON Elementor saves (what
 * `Document::get_elements_data()` decodes).
 */
final class ElementorPage extends PageTool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_elementor_page';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function builder(): string {
		return 'Elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function describe(): string {
		return 'Returns the element tree (containers, sections, columns and widgets with id, elType, widgetType, settings and nested elements), the edit mode ("builder" when Elementor is in use), template type and the Elementor version that saved it.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function layout_schema(): array {
		$nullable = array( 'type' => array( 'string', 'null' ) );

		return array(
			'edit_mode'         => $nullable,
			'template_type'     => $nullable,
			'elementor_version' => $nullable,
			'elements'          => array( 'type' => 'array' ),
		);
	}

	/**
	 * Reads the layout fields, or null when the post has no layout for this builder.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $extra   Validated extra arguments.
	 * @return array<string, mixed>|null
	 */
	protected function layout( int $post_id, array $extra ): ?array {
		$elements = Output::json( self::meta( $post_id, '_elementor_data' ) );
		if ( null === $elements || array() === $elements ) {
			return null;
		}

		return array(
			'edit_mode'         => self::meta_string( $post_id, '_elementor_edit_mode' ),
			'template_type'     => self::meta_string( $post_id, '_elementor_template_type' ),
			'elementor_version' => self::meta_string( $post_id, '_elementor_version' ),
			'elements'          => $elements,
		);
	}
}
