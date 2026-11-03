<?php
/**
 * The count_comments tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\System;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Counts comments by status (WP-CLI `comment count`).
 */
final class CommentCounts implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'count_comments';
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
			'Count comments with FlitAgent',
			'counts comments by status (approved, pending, spam, trash, on trashed posts, total) for the whole site or one post_id.',
			array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			)
		);
	}

	/**
	 * Runs the tool.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError On invalid arguments.
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array( 'post_id' ), $this->name() );
		$post_id = Arguments::int( $arguments, 'post_id', 0, 1, PHP_INT_MAX );

		if ( 0 !== $post_id && ! get_post( $post_id ) ) {
			throw new ToolError( sprintf( 'Post %d not found.', (int) $post_id ) );
		}

		$counts = (array) wp_count_comments( $post_id );

		return array(
			'post_id'      => 0 === $post_id ? null : $post_id,
			'approved'     => (int) ( $counts['approved'] ?? 0 ),
			'pending'      => (int) ( $counts['moderated'] ?? 0 ),
			'spam'         => (int) ( $counts['spam'] ?? 0 ),
			'trash'        => (int) ( $counts['trash'] ?? 0 ),
			'post_trashed' => (int) ( $counts['post-trashed'] ?? 0 ),
			'total'        => (int) ( $counts['total_comments'] ?? 0 ),
		);
	}
}
