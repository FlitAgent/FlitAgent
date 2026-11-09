<?php
/**
 * Tool: get_beaver_page.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a post's Beaver Builder nodes from `_fl_builder_data` (published) or `_fl_builder_draft` (draft), the keys
 * `FLBuilderModel::get_layout_data()` reads. Beaver stores PHP-serialized stdClass nodes; WordPress's own meta API
 * decodes them, FlitAgent never unserializes and converts the objects to plain arrays.
 */
final class BeaverPage extends PageTool {

	const KEYS = array(
		'published' => '_fl_builder_data',
		'draft'     => '_fl_builder_draft',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_beaver_page';
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
	protected function describe(): string {
		return 'Returns the nodes (rows, column groups, columns and modules with node, type, parent, position and settings) of the published layout, or of the unpublished builder draft with status "draft", and whether the builder is enabled for the post.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function extra_inputs(): array {
		return array(
			'status' => array(
				'type'        => 'string',
				'enum'        => array_keys( self::KEYS ),
				'default'     => 'published',
				'description' => 'Which layout: the published one, or the unsaved builder draft.',
			),
		);
	}

	/**
	 * Validates the layout status.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When status is not "published" or "draft".
	 */
	protected function extra_arguments( array $arguments ): array {
		$status = $arguments['status'] ?? 'published';
		if ( ! is_string( $status ) || ! isset( self::KEYS[ $status ] ) ) {
			throw new ToolError( 'status must be "published" or "draft".' );
		}
		return array( 'status' => $status );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function layout_schema(): array {
		return array(
			'builder_enabled' => array( 'type' => 'boolean' ),
			'layout_status'   => array(
				'type' => 'string',
				'enum' => array_keys( self::KEYS ),
			),
			'nodes'           => array( 'type' => 'array' ),
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
		$nodes = self::meta( $post_id, self::KEYS[ $extra['status'] ] );
		if ( ! is_array( $nodes ) || array() === $nodes ) {
			return null;
		}

		return array(
			'builder_enabled' => (bool) self::meta( $post_id, '_fl_builder_enabled' ),
			'layout_status'   => $extra['status'],
			'nodes'           => array_values( Output::plain( $nodes ) ),
		);
	}
}
