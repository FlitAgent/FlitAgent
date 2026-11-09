<?php
/**
 * Write tool: delete_post_meta.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Content;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `delete_post_meta`: deletes every value of one meta key of one post (PostMeta rules). Reversible: the values are
 * snapshotted and restored by rollback. Auto-approvable only on drafts and pending posts.
 */
final class DeletePostMeta implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_post_meta';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete a post custom field',
			'Deletes every value of one custom field (meta key) of one post. Same key rules as set_post_meta. The values are kept so rollback_change can restore them. Changes to drafts and pending posts may be applied at once if an administrator enabled auto-approve.',
			array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the post.',
				),
				'key'     => PostMeta::key_property(),
			),
			array( 'post_id', 'key' ),
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
	 * May auto-approve (drafts and pending posts): it stores no new text. Restoring the values always waits for a person.
	 */
	public function may_auto_approve(): bool {
		return true;
	}

	/**
	 * Assesses the deletion. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the key has no value.
	 */
	public function assess( array $args ): Assessment {
		$post_id = (int) $args['post_id'];
		$key     = (string) $args['key'];
		$target  = PostMeta::target( $post_id, $key, 'delete_post_meta' );
		if ( $target instanceof Assessment ) {
			return $target;
		}
		list( $post, $values ) = $target;
		if ( array() === $values ) {
			throw new ToolError( sprintf( 'Post %d has no meta key %s; nothing to delete.', (int) $post_id, esc_textarea( $key ) ) );
		}

		return Assessment::reversible(
			sprintf( 'Delete meta key %s (%s) of %s.', $key, Summary::count( count( $values ), 'value', 'values' ), Posts::name( $post ) ) . Posts::live_note( $post ),
			array(
				'post_id' => $post_id,
				'key'     => $key,
				'before'  => PostMeta::display( $key, $values ),
				'after'   => array(),
			),
			PostMeta::state( $post, $key, $values ),
			Posts::is_private( (string) $post->post_status )
		);
	}

	/**
	 * Deletes the values.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$post_id = (int) $args['post_id'];
		$key     = (string) $args['key'];
		$before  = PostMeta::expect( $post_id, $key, $assessment->preconditions() );
		PostMeta::replace( $post_id, $key, $before, array() );
		return PostMeta::outcome( $post_id, $key, $before );
	}

	/**
	 * Checks the key is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return array() === PostMeta::values( (int) $args['post_id'], (string) $args['key'] ) ? $outcome : $outcome->with_verification_failure( sprintf( 'Meta key %s still has values after the delete.', $args['key'] ) );
	}

	/**
	 * Assesses restoring the deleted values. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		return PostMeta::assess_restore( $rollback );
	}

	/**
	 * Restores the deleted values.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		return PostMeta::restore( $rollback, $expected_state );
	}
}
