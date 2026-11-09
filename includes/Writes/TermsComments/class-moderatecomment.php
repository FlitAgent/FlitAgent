<?php
/**
 * Write tool: moderate_comment.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\TermsComments;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `moderate_comment`: approves, holds, spams, trashes or untrashes one comment, with `moderate_comments` and
 * `edit_comment`. Reversible: rollback restores the previous status. Changes that make a comment public (approve, or
 * untrash of a comment that was approved) are never auto-approved.
 */
final class ModerateComment implements WriteTool, Reversible {

	/**
	 * Requested action => resulting status (untrash: from the stored previous status).
	 */
	const ACTIONS = array(
		'approve' => 'approved',
		'hold'    => 'unapproved',
		'spam'    => 'spam',
		'trash'   => 'trash',
		'untrash' => '',
	);

	/**
	 * Whether the site keeps trashed comments.
	 *
	 * @var bool
	 */
	private $trash;

	/**
	 * Constructor.
	 *
	 * @param bool|null $trash Whether the site keeps trashed comments (default: from EMPTY_TRASH_DAYS).
	 */
	public function __construct( ?bool $trash = null ) {
		$this->trash = null === $trash ? Comments::trash_enabled() : $trash;
	}

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'moderate_comment';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: moderate a comment',
			'Sets the status of one comment: approve (public), hold (pending), spam, trash, or untrash (restore a trashed comment to its previous status). Rollback (rollback_change) restores the previous status. hold, spam and trash may be applied at once if an administrator enabled auto-approve; anything that makes a comment public returns approval_required.',
			array(
				'comment_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the comment.',
				),
				'status'     => array(
					'type'        => 'string',
					'enum'        => array_keys( self::ACTIONS ),
					'description' => 'approve, hold, spam, trash or untrash.',
				),
			),
			array( 'comment_id', 'status' ),
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
	 * May auto-approve (hold, spam, trash, and untrash to a non-public status).
	 */
	public function may_auto_approve(): bool {
		return true;
	}

	/**
	 * Assesses the status change. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the comment already has that status.
	 */
	public function assess( array $args ): Assessment {
		$comment_id = (int) $args['comment_id'];
		$action     = (string) $args['status'];
		$comment    = Comments::allowed( $comment_id );
		if ( $comment instanceof Assessment ) {
			return $comment;
		}

		$from = Comments::status( $comment_id );
		if ( 'untrash' === $action && 'trash' !== $from ) {
			return Assessment::prohibited( 'not_in_trash', sprintf( 'Comment %d is not in the trash (status: %s).', $comment_id, $from ) );
		}
		if ( 'trash' === $from && 'untrash' !== $action && 'trash' !== $action ) {
			return Assessment::prohibited( 'in_trash', sprintf( 'Comment %d is in the trash; use status untrash to restore it first.', $comment_id ) );
		}
		if ( 'trash' === $action && ! $this->trash ) {
			return Assessment::prohibited( 'no_trash', 'The trash is disabled on this site (EMPTY_TRASH_DAYS is 0), so trashing would delete the comment; use delete_comment_permanently on a spam comment instead.' );
		}

		$to = 'untrash' === $action ? Comments::restored_status( $comment_id ) : self::ACTIONS[ $action ];
		if ( $from === $to ) {
			throw new ToolError( sprintf( 'Comment %d is already %s; nothing to change.', (int) $comment_id, esc_textarea( $from ) ) );
		}

		return Assessment::reversible(
			sprintf( 'Change comment %d by %s on post %d from %s to %s.', $comment_id, $comment->comment_author, (int) $comment->comment_post_ID, $from, $to ),
			array(
				'comment_id' => $comment_id,
				'post_id'    => (int) $comment->comment_post_ID,
				'content'    => Comments::excerpt( (string) $comment->comment_content ),
				'before'     => array( 'status' => $from ),
				'after'      => array( 'status' => $to ),
			),
			array( 'fingerprint' => Comments::fingerprint( $comment ) ),
			'approved' !== $to
		);
	}

	/**
	 * Changes the status.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the comment changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$comment_id = (int) $args['comment_id'];
		Recheck::unchanged( $this, $args, $assessment, sprintf( 'Comment %d', $comment_id ) );
		$from = Comments::status( $comment_id );
		$to   = (string) $assessment->preview()['after']['status'];

		Comments::move( $comment_id, $from, $to );

		$comment = get_comment( $comment_id );
		return new Outcome(
			array(
				'comment_id' => $comment_id,
				'previous'   => $from,
				'status'     => Comments::status( $comment_id ),
			),
			Rollback::make( Rollback::SNAPSHOT, 'comment', $comment_id, array(), array( 'status' => $from ) ),
			array( 'fingerprint' => $comment ? Comments::fingerprint( $comment ) : '' )
		);
	}

	/**
	 * Reads the status back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$action = (string) $args['status'];
		$status = Comments::status( (int) $args['comment_id'] );
		if ( 'untrash' === $action ? 'trash' !== $status && '' !== $status : self::ACTIONS[ $action ] === $status ) {
			return $outcome;
		}
		return $outcome->with_verification_failure( sprintf( 'The comment status is %s after the change.', '' === $status ? 'unknown' : $status ) );
	}

	/**
	 * Assesses restoring the previous status. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$comment_id = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$comment    = Comments::allowed( $comment_id );
		if ( $comment instanceof Assessment ) {
			return $comment;
		}
		$restored = isset( $rollback['before']['status'] ) ? (string) $rollback['before']['status'] : '';
		if ( ! isset( Comments::APPROVED_VALUES[ $restored ] ) ) {
			return Assessment::prohibited( 'no_rollback', 'The rollback reference has no previous comment status.' );
		}
		if ( 'trash' === $restored && ! $this->trash ) {
			return Assessment::prohibited( 'no_trash', 'The trash is disabled on this site, so the comment cannot be put back in the trash.' );
		}
		$current = Comments::status( $comment_id );

		return Assessment::reversible(
			sprintf( 'Restore comment %d from %s to %s.', $comment_id, $current, $restored ),
			array(
				'comment_id' => $comment_id,
				'current'    => array( 'status' => $current ),
				'restored'   => array( 'status' => $restored ),
			),
			array( 'fingerprint' => Comments::fingerprint( $comment ) ),
			'approved' !== $restored
		);
	}

	/**
	 * Restores the previous status if the comment is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the comment changed or WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$comment_id = (int) $rollback['target']['id'];
		$comment    = get_comment( $comment_id );
		$restored   = isset( $rollback['before']['status'] ) ? (string) $rollback['before']['status'] : '';
		if ( ! $comment || ! isset( Comments::APPROVED_VALUES[ $restored ] ) || ! isset( $expected_state['fingerprint'] ) || Comments::fingerprint( $comment ) !== $expected_state['fingerprint'] ) {
			throw new ToolError( sprintf( 'Comment %d changed after the rollback was assessed; nothing was restored.', (int) $comment_id ) );
		}

		Comments::move( $comment_id, Comments::status( $comment_id ), $restored );

		$status  = Comments::status( $comment_id );
		$outcome = new Outcome(
			array(
				'comment_id' => $comment_id,
				'status'     => $status,
			),
			Rollback::none()
		);
		return $status === $restored ? $outcome : $outcome->with_verification_failure( sprintf( 'The comment status is %s after the rollback.', $status ) );
	}
}
