<?php
/**
 * Write tool: restore_post.
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
 * `restore_post`: restores one post from the trash, as a draft (core's default) or with the status it had before it was
 * trashed. Reversible: rollback trashes it again. Auto-approvable only when the restored status is not public.
 */
final class RestorePost implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'restore_post';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: restore a post from the trash',
			'Restores one trashed post, page or custom post type item, as a draft (default) or, with status "previous", with the status it had before it was trashed. Roll it back with rollback_change, which trashes it again. Restoring as a draft may be applied at once if an administrator enabled auto-approve; restoring to a public status returns approval_required.',
			array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the trashed post.',
				),
				'status'  => array(
					'type'        => 'string',
					'enum'        => array( 'draft', 'previous' ),
					'description' => 'draft (default) or previous (the status before trashing).',
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
	 * May auto-approve (restoring as a draft or pending).
	 */
	public function may_auto_approve(): bool {
		return true;
	}

	/**
	 * Assesses restoring. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$previous = isset( $args['status'] ) && 'previous' === $args['status'];
		return Trashing::assess_untrash( (int) $args['post_id'], $previous ? '' : 'draft', 'Restore', false );
	}

	/**
	 * Restores the post.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$preconditions = $assessment->preconditions();
		return Trashing::untrash( (int) $args['post_id'], $preconditions, array( 'action' => 'trash' ) )
			->with_result( array( 'restored_as' => (string) $preconditions['restore_status'] ) );
	}

	/**
	 * Checks the post has the restored status.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return Trashing::verify( (int) $args['post_id'], (string) $outcome->result()['restored_as'], $outcome );
	}

	/**
	 * Assesses trashing the post again. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		return Trashing::assess_trash( isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0, 'Undo the restore: move back to the trash', true );
	}

	/**
	 * Trashes the post again.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$post_id = (int) $rollback['target']['id'];
		return Trashing::verify( $post_id, 'trash', Trashing::trash( $post_id, $expected_state, null ) );
	}
}
