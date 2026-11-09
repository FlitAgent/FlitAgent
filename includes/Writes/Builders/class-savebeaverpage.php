<?php
/**
 * Write tool: save_beaver_page.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces and publishes a post's Beaver Builder layout through `FLBuilderModel` (draft layout, then
 * `save_layout( false )`, which copies it to `_fl_builder_data`, rewrites post_content and renders the assets without
 * changing the post status).
 */
final class SaveBeaverPage extends SaveLayout {

	/**
	 * Modules that output raw HTML.
	 */
	const CODE_MODULES = array( 'html' );

	/**
	 * Node settings that hold custom JavaScript or CSS.
	 */
	const CODE_SETTINGS = array( 'bb_js_code', 'bb_css_code', 'js', 'css', 'code' );

	/**
	 * Constructor.
	 *
	 * @param Engine|null $engine Pipeline; Beaver Builder's own by default.
	 */
	public function __construct( ?Engine $engine = null ) {
		parent::__construct( $engine ?? new BeaverEngine() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'save_beaver_page';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function builder(): string {
		return 'Beaver Builder';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function title(): string {
		return 'FlitAgent: save a Beaver Builder page';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function describe(): string {
		return 'Replaces the Beaver Builder layout of one post through Beaver\'s own model (draft layout, then publish the layout) and renders its CSS and JS. The post type must be enabled in Beaver Builder and the caller needs builder access; users without unfiltered_html are held to Beaver\'s own HTML check.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function property(): string {
		return 'nodes';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function property_schema(): array {
		return array(
			'type'        => 'array',
			'minItems'    => 1,
			'items'       => array( 'type' => 'object' ),
			'description' => 'Every node of the new layout, in the shape get_beaver_page returns: node (unique id), type (row, column-group, column, module), parent (node id, null at the root), position and settings (modules need settings.type, the module slug).',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta_keys(): array {
		return array( '_fl_builder_data', '_fl_builder_draft', '_fl_builder_data_settings', '_fl_builder_draft_settings', '_fl_builder_enabled' );
	}

	/**
	 * Validates the nodes and returns Beaver's own shape: node id => stdClass node with stdClass settings (compound
	 * fields inside settings stay arrays, as Beaver's CSS generation expects).
	 *
	 * @param mixed $raw Nodes argument.
	 * @return array<string, \stdClass>
	 * @throws ToolError When a node is malformed.
	 */
	protected function normalize( $raw ) {
		if ( ! is_array( $raw ) || array() === $raw || array_keys( $raw ) !== range( 0, count( $raw ) - 1 ) ) {
			throw new ToolError( 'nodes must be a non-empty list of Beaver Builder nodes.' );
		}
		$map = array();
		foreach ( $raw as $index => $node ) {
			if ( ! is_array( $node ) || ! isset( $node['node'] ) || ! is_string( $node['node'] ) || '' === $node['node'] ) {
				throw new ToolError( sprintf( 'nodes[%d] must be an object with a string node id.', (int) $index ) );
			}
			if ( isset( $map[ $node['node'] ] ) ) {
				throw new ToolError( sprintf( 'Duplicate node id "%s".', esc_textarea( $node['node'] ) ) );
			}
			if ( ! isset( $node['type'] ) || ! is_string( $node['type'] ) || '' === $node['type'] ) {
				throw new ToolError( sprintf( 'Node "%s" needs a type.', esc_textarea( $node['node'] ) ) );
			}
			$settings = $node['settings'] ?? array();
			if ( ! is_array( $settings ) ) {
				throw new ToolError( sprintf( 'Node "%s" has settings that are not an object.', esc_textarea( $node['node'] ) ) );
			}
			if ( 'module' === $node['type'] && ( ! isset( $settings['type'] ) || ! is_string( $settings['type'] ) || '' === $settings['type'] ) ) {
				throw new ToolError( sprintf( 'Module node "%s" needs settings.type (the module slug).', esc_textarea( $node['node'] ) ) );
			}
			$object               = (object) $node;
			$object->settings     = (object) $settings;
			$map[ $node['node'] ] = $object;
		}//end foreach
		foreach ( $map as $id => $node ) {
			$parent = $node->parent ?? null;
			if ( null !== $parent && '' !== $parent && ! ( is_string( $parent ) && isset( $map[ $parent ] ) ) ) {
				throw new ToolError( sprintf( 'Node "%s" has parent "%s", which is not in nodes.', esc_textarea( $id ), is_scalar( $parent ) ? esc_textarea( (string) $parent ) : '?' ) );
			}
		}
		return $map;
	}

	/**
	 * The stored layout, or null.
	 *
	 * @param array<string, mixed> $values Meta values.
	 * @return mixed
	 */
	protected function layout_from( array $values ) {
		$nodes = $values['_fl_builder_data'] ?? null;
		return is_array( $nodes ) && array() !== $nodes ? $nodes : null;
	}

	/**
	 * HTML modules and custom code settings.
	 *
	 * @param mixed $layout Nodes.
	 * @return string[]
	 */
	protected function code_elements( $layout ): array {
		$found = array();
		foreach ( (array) $layout as $id => $node ) {
			$settings = is_array( $node ) && isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
			if ( isset( $settings['type'] ) && in_array( $settings['type'], self::CODE_MODULES, true ) && 'module' === ( $node['type'] ?? '' ) ) {
				$found[] = sprintf( '%s module %s', $settings['type'], (string) $id );
			}
			foreach ( self::CODE_SETTINGS as $key ) {
				if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
					$found[] = sprintf( '%s in %s', $key, (string) $id );
				}
			}
		}
		return $found;
	}
}
