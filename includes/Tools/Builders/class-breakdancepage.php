<?php
/**
 * Tool: get_breakdance_page.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a post's Breakdance tree: meta `_breakdance_data` is JSON whose `tree_json_string` is the tree as JSON (what
 * `Breakdance\Data\get_meta( $id, '_breakdance_data', 'tree_json_string' )` returns). Both levels are json_decoded.
 */
final class BreakdancePage extends PageTool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_breakdance_page';
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
	protected function describe(): string {
		return 'Returns the full tree envelope: root node with nested children (id, data.type, data.properties) plus Breakdance\'s bookkeeping keys such as _nextNodeId.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function layout_schema(): array {
		return array(
			'tree' => array( 'type' => 'object' ),
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
		$envelope = Output::json( self::meta( $post_id, self::prefix() . 'data' ) );
		$tree     = Output::json( $envelope['tree_json_string'] ?? null );
		if ( null === $tree || array() === $tree ) {
			return null;
		}

		return array( 'tree' => $tree );
	}

	/**
	 * Breakdance's meta prefix: `_breakdance_`, or `_oxygen_` when the build runs in Oxygen mode.
	 *
	 * @return string
	 */
	private static function prefix(): string {
		$bdox = '\Breakdance\BreakdanceOxygen\Strings\__bdox';
		if ( function_exists( $bdox ) ) {
			$prefix = $bdox( '_meta_prefix' );
			if ( is_string( $prefix ) && '' !== $prefix ) {
				return $prefix;
			}
		}
		return '_breakdance_';
	}
}
