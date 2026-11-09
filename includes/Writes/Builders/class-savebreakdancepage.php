<?php
/**
 * Write tool: save_breakdance_page.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Builders\Output;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces a post's Breakdance tree: `_breakdance_data` (or the Oxygen-mode prefix) holds JSON whose
 * `tree_json_string` is the tree as JSON.
 */
final class SaveBreakdancePage extends SaveLayout {

	/**
	 * Element types (last segment of the PHP class name) treated as running code: PHP code blocks, code/HTML blocks,
	 * shortcodes and scripts. Deliberately broad: a false match only means approval and unfiltered_html.
	 */
	const CODE_TYPES = '/php|code|html|shortcode|script/i';

	/**
	 * Constructor.
	 *
	 * @param Engine|null $engine Pipeline; Breakdance's own by default.
	 */
	public function __construct( ?Engine $engine = null ) {
		parent::__construct( $engine ?? new BreakdanceEngine() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'save_breakdance_page';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function builder(): string {
		return 'Breakdance';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function title(): string {
		return 'FlitAgent: save a Breakdance page';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function describe(): string {
		return 'Replaces the Breakdance tree of one post the way Breakdance saves it (its meta encoder, a post update, then its CSS cache generation). Needs Breakdance builder access. Send the full envelope get_breakdance_page returns; _nextNodeId and status are set as the Breakdance editor expects.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function property(): string {
		return 'tree';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function property_schema(): array {
		return array(
			'type'        => 'object',
			'description' => 'The full tree replacing the current one: {"root": {"id": 1, "data": {"type": "root", "properties": {}}, "children": [...]}}; every node has id, data.type (the element\'s PHP class name, e.g. "EssentialElements\\\\Heading"), data.properties and children.',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta_keys(): array {
		return array( BreakdanceEngine::data_key() );
	}

	/**
	 * Validates the tree and completes it like the Breakdance editor writes it: missing `properties` and `children`
	 * added, empty properties as an object, `_nextNodeId` above every node id, `status` "exported".
	 *
	 * @param mixed $raw Tree argument.
	 * @return array<string, mixed>
	 * @throws ToolError When the tree is malformed.
	 */
	protected function normalize( $raw ) {
		if ( ! is_array( $raw ) || ! isset( $raw['root'] ) || ! is_array( $raw['root'] ) ) {
			throw new ToolError( 'tree must be an object with a root node.' );
		}
		$raw['root']        = self::node( $raw['root'], 'root' );
		$next               = isset( $raw['_nextNodeId'] ) && is_numeric( $raw['_nextNodeId'] ) ? (int) $raw['_nextNodeId'] : 0;
		$raw['_nextNodeId'] = max( $next, self::max_id( $raw['root'] ) + 1 );
		$raw['status']      = 'exported';
		return $raw;
	}

	/**
	 * The stored layout, or null.
	 *
	 * @param array<string, mixed> $values Meta values.
	 * @return mixed
	 */
	protected function layout_from( array $values ) {
		$envelope = Output::json( $values[ BreakdanceEngine::data_key() ] ?? null );
		$tree     = Output::json( $envelope['tree_json_string'] ?? null );
		return null === $tree || array() === $tree ? null : $tree;
	}

	/**
	 * Nodes whose element type runs code.
	 *
	 * @param mixed $layout Tree.
	 * @return string[]
	 */
	protected function code_elements( $layout ): array {
		return is_array( $layout ) && isset( $layout['root'] ) ? self::code_nodes( $layout['root'] ) : array();
	}

	/**
	 * Code nodes in a subtree.
	 *
	 * @param mixed $node Node.
	 * @return string[]
	 */
	private static function code_nodes( $node ): array {
		if ( ! is_array( $node ) ) {
			return array();
		}
		$found = array();
		$type  = isset( $node['data']['type'] ) && is_string( $node['data']['type'] ) ? $node['data']['type'] : '';
		$short = substr( (string) strrchr( '\\' . $type, '\\' ), 1 );
		if ( 'root' !== $type && 1 === preg_match( self::CODE_TYPES, $short ) ) {
			$found[] = sprintf( '%s node %s', $type, is_scalar( $node['id'] ?? null ) ? (string) $node['id'] : '?' );
		}
		foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
			$found = array_merge( $found, self::code_nodes( $child ) );
		}
		return $found;
	}

	/**
	 * A validated, completed node.
	 *
	 * @param mixed  $node Node.
	 * @param string $path Path for messages.
	 * @return array<string, mixed>
	 * @throws ToolError When the node is malformed.
	 */
	private static function node( $node, string $path ): array {
		if ( ! is_array( $node ) || ! array_key_exists( 'id', $node ) || ! is_scalar( $node['id'] ) || ! isset( $node['data'] ) || ! is_array( $node['data'] ) ) {
			throw new ToolError( sprintf( 'Node at %s needs an id and a data object.', esc_textarea( $path ) ) );
		}
		if ( ! isset( $node['data']['type'] ) || ! is_string( $node['data']['type'] ) || '' === $node['data']['type'] ) {
			throw new ToolError( sprintf( 'Node at %s needs data.type (the element PHP class name).', esc_textarea( $path ) ) );
		}
		$properties = $node['data']['properties'] ?? array();
		if ( ! is_array( $properties ) && ! is_object( $properties ) ) {
			throw new ToolError( sprintf( 'Node at %s has data.properties that is not an object.', esc_textarea( $path ) ) );
		}
		$node['data']['properties'] = array() === $properties ? new \stdClass() : $properties;

		$children = $node['children'] ?? array();
		if ( ! is_array( $children ) ) {
			throw new ToolError( sprintf( 'Node at %s has children that is not a list.', esc_textarea( $path ) ) );
		}
		foreach ( $children as $index => $child ) {
			$children[ $index ] = self::node( $child, sprintf( '%s.children[%s]', $path, $index ) );
		}
		$node['children'] = $children;
		return $node;
	}

	/**
	 * Highest numeric node id in a subtree.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return int
	 */
	private static function max_id( array $node ): int {
		$max = is_numeric( $node['id'] ) ? (int) $node['id'] : 0;
		foreach ( $node['children'] as $child ) {
			$max = max( $max, self::max_id( $child ) );
		}
		return $max;
	}
}
