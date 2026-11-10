<?php
/**
 * Write tool: trash_post.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Content;

use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `trash_post`: moves one post to the trash. Reversible: rollback restores it with the status it had. Auto-approvable
 * only for drafts and pending posts. Refused when the site's trash is disabled (core would delete permanently).
 */
final class TrashPost implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'trash_post';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: move a post to the trash',
			'Moves one post, page or custom post type item to the trash (refused when the site\'s trash is disabled). Roll it back with rollback_change, which restores it with its previous status, or use restore_post. Drafts and pending items may be trashed at once if an administrator enabled auto-approve; anything public returns approval_required.',
			array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the post to trash.',
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
		return array( Risk::REVERSIBLE );
	}

	/**
	 * May auto-approve (drafts and pending posts).
	 */
	public function may_auto_approve(): bool {
		return true;
	}

	/**
	 * Assesses trashing. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		return Trashing::assess_trash( (int) $args['post_id'], 'Move %s to the trash', false );
	}

	/**
	 * Trashes the post.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$preconditions = $assessment->preconditions();
		return Trashing::trash(
			(int) $args['post_id'],
			$preconditions,
			array(
				'action' => 'untrash',
				'status' => (string) $preconditions['status'],
			)
		);
	}

	/**
	 * Checks the post is in the trash.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return Trashing::verify( (int) $args['post_id'], 'trash', $outcome );
	}

	/**
	 * Assesses restoring the post with its previous status. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$status = isset( $rollback['refs']['status'] ) && in_array( $rollback['refs']['status'], Posts::STATUSES, true ) ? (string) $rollback['refs']['status'] : 'draft';
		return Trashing::assess_untrash( isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0, $status, 'Undo the trash: restore', true );
	}

	/**
	 * Restores the post with its previous status.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$post_id = (int) $rollback['target']['id'];
		return Trashing::verify( $post_id, (string) $expected_state['restore_status'], Trashing::untrash( $post_id, $expected_state, null ) );
	}
}
