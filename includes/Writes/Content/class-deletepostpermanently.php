<?php
/**
 * Write tool: delete_post_permanently.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Content;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `delete_post_permanently`: deletes one trashed post with its revisions and meta. Destructive (always a human
 * approval), no rollback. Refused for posts that are not in the trash.
 */
final class DeletePostPermanently implements WriteTool {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_post_permanently';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete a trashed post permanently',
			'Permanently deletes one post, page or custom post type item that is already in the trash, with its revisions and custom fields. Cannot be rolled back. Always returns approval_required: an administrator must approve it in wp-admin. Trash it first with trash_post.',
			array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the trashed post.',
				),
			),
			array( 'post_id' ),
			$this->tiers(),
			false
		);
	}

	/**
	 * Tiers.
	 *
	 * @return string[]
	 */
	public function tiers(): array {
		return array( Risk::DESTRUCTIVE );
	}

	/**
	 * Never auto-approved.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the deletion. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$post_id = (int) $args['post_id'];
		$post    = Posts::find( $post_id, 'delete_post' );
		if ( $post instanceof Assessment ) {
			return $post;
		}
		if ( 'trash' !== $post->post_status ) {
			return Assessment::prohibited( 'not_in_trash', sprintf( 'Post %d is not in the trash (status: %s); move it there with trash_post first.', $post_id, $post->post_status ) );
		}

		$revisions = count( wp_get_post_revisions( $post_id ) );
		return Assessment::destructive(
			0 === $revisions
				? sprintf( 'Permanently delete %s. This cannot be undone.', Posts::name_with_id( $post ) )
				: sprintf( 'Permanently delete %s and its %s. This cannot be undone.', Posts::name_with_id( $post ), Summary::count( $revisions, 'revision', 'revisions' ) ),
			array(
				'post_id' => $post_id,
				'before'  => array(
					'post_title'          => (string) $post->post_title,
					'post_type'           => (string) $post->post_type,
					'status_before_trash' => Trashing::status_before_trash( $post_id ),
					'revisions'           => (string) $revisions,
				),
				'after'   => array(),
			),
			Posts::state( $post )
		);
	}

	/**
	 * Deletes the post.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the post changed or WordPress did not delete it.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post || 'trash' !== $post->post_status || Posts::state( $post ) !== $assessment->preconditions() ) {
			throw new ToolError( sprintf( 'Post %d changed after it was assessed; nothing was deleted.', (int) $post_id ) );
		}
		if ( ! wp_delete_post( $post_id, true ) ) {
			throw new ToolError( sprintf( 'WordPress could not delete post %d.', (int) $post_id ) );
		}
		return new Outcome(
			array(
				'post_id' => $post_id,
				'deleted' => true,
			),
			Rollback::none()
		);
	}

	/**
	 * Checks the post is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return get_post( (int) $args['post_id'] ) ? $outcome->with_verification_failure( 'The post still exists after the delete.' ) : $outcome;
	}
}
