<?php
/**
 * Write tool: restore_post_revision.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Content;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\WriteTool;
use FlitAgent\Writes\Summary;

defined( 'ABSPATH' ) || exit;

/**
 * `restore_post_revision`: restores the title, content and excerpt of one post from one of its revisions. The write is
 * an update_post of the fields that differ: it saves the current state as a revision first, and has the same refusals,
 * tier, auto-approval and rollback.
 */
final class RestorePostRevision implements WriteTool, Reversible {

	/**
	 * Revisioned argument => post field.
	 */
	const FIELDS = UpdatePost::FIELDS;

	/**
	 * The update that performs the write.
	 *
	 * @var UpdatePost
	 */
	private $update;

	/**
	 * The update_post arguments of the last apply(), for verify().
	 *
	 * @var array<string, mixed>
	 */
	private $applied = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->update = new UpdatePost();
	}

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'restore_post_revision';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: restore a post revision',
			'Restores the title, content and excerpt of one post from one of its revisions (revision ids come from the post\'s revision history). Saves the current state as a revision first; roll it back with rollback_change. Same approval rules as update_post.',
			array(
				'post_id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the post.',
				),
				'revision_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of a revision of that post.',
				),
			),
			array( 'post_id', 'revision_id' ),
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
		return $this->update->tiers();
	}

	/**
	 * May auto-approve (as update_post).
	 */
	public function may_auto_approve(): bool {
		return $this->update->may_auto_approve();
	}

	/**
	 * Assesses the restore. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$planned = $this->plan( $args );
		if ( $planned instanceof Assessment ) {
			return $planned;
		}
		$inner = $this->update->assess( $planned[1] );
		if ( $inner->is_refused() ) {
			return $inner;
		}
		return $inner->with_summary( sprintf( 'Restore revision %d of %s: %s.', (int) $args['revision_id'], Posts::name( $planned[0] ), Summary::fields( array_diff( array_keys( $planned[1] ), array( 'post_id' ) ) ) ) . Posts::live_note( $planned[0] ) );
	}

	/**
	 * Writes the revision's fields through update_post.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the post or revision changed.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$planned = $this->plan( $args );
		if ( $planned instanceof Assessment ) {
			throw new ToolError( sprintf( 'Post %d changed while the restore was being applied; nothing was changed.', (int) $args['post_id'] ) );
		}
		$this->applied = $planned[1];
		return $this->update->apply( $this->applied, $assessment )->with_result( array( 'restored_revision_id' => (int) $args['revision_id'] ) );
	}

	/**
	 * Reads the fields back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return $this->update->verify( $this->applied, $outcome );
	}

	/**
	 * Assesses the rollback (as update_post). Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		return $this->update->assess_rollback( $rollback );
	}

	/**
	 * Rolls back (as update_post).
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		return $this->update->rollback( $rollback, $expected_state );
	}

	/**
	 * The post and the update_post arguments (fields that differ from the revision), or a refusal. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array{0: object, 1: array<string, mixed>}|Assessment
	 * @throws ToolError When the revision already matches the post.
	 */
	private function plan( array $args ) {
		$post_id     = (int) $args['post_id'];
		$revision_id = (int) $args['revision_id'];
		$post        = Posts::find_untrashed( $post_id, 'edit_post' );
		if ( $post instanceof Assessment ) {
			return $post;
		}
		$revision = wp_get_post_revision( $revision_id );
		if ( ! $revision || (int) $revision->post_parent !== $post_id ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Revision %d of post %d was not found.', $revision_id, $post_id ) );
		}

		$update = array( 'post_id' => $post_id );
		foreach ( self::FIELDS as $arg => $field ) {
			if ( (string) $revision->$field !== (string) $post->$field ) {
				$update[ $arg ] = (string) $revision->$field;
			}
		}
		if ( 1 === count( $update ) ) {
			throw new ToolError( sprintf( 'Post %d already matches revision %d; nothing to restore.', (int) $post_id, (int) $revision_id ) );
		}
		return array( $post, $update );
	}
}
