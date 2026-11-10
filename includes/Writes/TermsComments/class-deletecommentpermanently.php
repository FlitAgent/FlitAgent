<?php
/**
 * Write tool: delete_comment_permanently.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\TermsComments;

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
 * `delete_comment_permanently`: deletes one comment that is already in the trash or marked as spam, with
 * `moderate_comments` and `edit_comment`. Always destructive; there is no rollback. Replies move to its parent.
 */
final class DeleteCommentPermanently implements WriteTool {

	/**
	 * Statuses a comment must have before it can be deleted.
	 */
	const DELETABLE = array( 'trash', 'spam' );

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_comment_permanently';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: permanently delete a comment',
			'Permanently deletes one comment that is already in the trash or marked as spam (use moderate_comment to trash or spam it first). Replies to it move to its parent. Always returns approval_required: an administrator must approve it in wp-admin. There is no rollback.',
			array(
				'comment_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the comment.',
				),
			),
			array( 'comment_id' ),
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
		$comment_id = (int) $args['comment_id'];
		$comment    = Comments::allowed( $comment_id );
		if ( $comment instanceof Assessment ) {
			return $comment;
		}
		$status = Comments::status( $comment_id );
		if ( ! in_array( $status, self::DELETABLE, true ) ) {
			return Assessment::prohibited( 'not_in_trash', sprintf( 'Comment %d is %s; only comments in the trash or spam can be deleted permanently. Use moderate_comment to trash it first.', $comment_id, '' === $status ? 'in an unknown status' : Comments::status_word( $status ) ) );
		}
		// wp_delete_comment() moves every reply, whatever its status: 'any' counts trashed and spam replies too ('all'
		// is only approved and pending ones).
		$replies = get_comments(
			array(
				'parent' => $comment_id,
				'count'  => true,
				'status' => 'any',
			)
		);
		$replies = is_numeric( $replies ) ? (int) $replies : 0;

		return Assessment::destructive(
			sprintf( 'Permanently delete %s comment %d by %s on post %d%s. This cannot be undone.', Comments::status_word( $status ), $comment_id, $comment->comment_author, (int) $comment->comment_post_ID, 0 === $replies ? '' : '; ' . Summary::count( $replies, 'reply moves', 'replies move' ) . ' to its parent' ),
			array(
				'comment_id'         => $comment_id,
				'before'             => array(
					'author'  => (string) $comment->comment_author,
					'post_id' => (int) $comment->comment_post_ID,
					'status'  => $status,
					'content' => Comments::excerpt( (string) $comment->comment_content ),
				),
				'after'              => array(),
				'replies_reparented' => $replies,
			),
			array( 'fingerprint' => Comments::fingerprint( $comment ) )
		);
	}

	/**
	 * Deletes the comment.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the comment changed or WordPress could not delete it.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$comment_id = (int) $args['comment_id'];
		Recheck::unchanged( $this, $args, $assessment, sprintf( 'Comment %d', $comment_id ) );
		if ( ! wp_delete_comment( $comment_id, true ) ) {
			throw new ToolError( sprintf( 'WordPress could not delete comment %d.', (int) $comment_id ) );
		}
		return new Outcome(
			array(
				'comment_id' => $comment_id,
				'deleted'    => true,
			),
			Rollback::none()
		);
	}

	/**
	 * Confirms the comment is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return get_comment( (int) $args['comment_id'] ) ? $outcome->with_verification_failure( 'The comment still exists after the delete.' ) : $outcome;
	}
}
