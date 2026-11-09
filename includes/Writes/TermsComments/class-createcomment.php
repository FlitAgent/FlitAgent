<?php
/**
 * Write tool: create_comment.
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
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `create_comment`: posts a comment or reply as the authenticated user on a published post with comments open. The
 * caller needs `moderate_comments` and `read_post`. Reversible (rollback trashes it) but never auto-approved, because
 * the comment is public at once.
 */
final class CreateComment implements WriteTool, Reversible {

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
		return 'create_comment';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: post a comment',
			'Posts a comment, or a reply to an approved comment, as the connected WordPress user on a published post with comments open. The comment is public at once (unless a moderation rule holds it), so this always returns approval_required: an administrator must approve it in wp-admin. Rollback (rollback_change) moves the comment to the trash.',
			array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the post to comment on.',
				),
				'content' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 65525,
					'description' => 'Comment text (basic HTML allowed as for any comment).',
				),
				'parent'  => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'ID of an approved comment on the same post to reply to. Default 0 (top level).',
				),
			),
			array( 'post_id', 'content' ),
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
	 * Never auto-approved: a comment is public.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the comment. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the content is empty or the parent is not allowed.
	 */
	public function assess( array $args ): Assessment {
		$post_id = (int) $args['post_id'];
		$content = (string) $args['content'];
		$parent  = isset( $args['parent'] ) ? (int) $args['parent'] : 0;
		if ( '' === trim( $content ) ) {
			throw new ToolError( 'content must not be empty.' );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'moderate_comments' ) || ! current_user_can( 'read_post', $post_id ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Post %d was not found or you cannot comment on it.', $post_id ) );
		}
		if ( 'publish' !== $post->post_status ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'Post %d is not published (status: %s), so FlitAgent does not comment on it.', $post_id, $post->post_status ) );
		}
		if ( ! comments_open( $post_id ) ) {
			return Assessment::prohibited( 'comments_closed', sprintf( 'Comments are closed on post %d.', $post_id ) );
		}

		$parent_comment = null;
		if ( $parent > 0 ) {
			$parent_comment = get_comment( $parent );
			if ( ! $parent_comment || (int) $parent_comment->comment_post_ID !== $post_id || '1' !== (string) $parent_comment->comment_approved ) {
				throw new ToolError( sprintf( 'Parent comment %d was not found on post %d or is not approved.', (int) $parent, (int) $post_id ) );
			}
		}

		$user = wp_get_current_user();
		return Assessment::reversible(
			sprintf( 'Post a %s as %s on %s; it is public at once.', $parent > 0 ? sprintf( 'reply to comment %d', $parent ) : 'comment', $user->display_name, Summary::named( (string) $post->post_title, sprintf( 'post %d', $post_id ) ) ),
			array(
				'post_id' => $post_id,
				'before'  => array(),
				'after'   => array(
					'author'  => (string) $user->display_name,
					'parent'  => $parent,
					'content' => $content,
				),
			),
			array(
				'post'   => array( $post_id, (string) $post->post_status, 'open' ),
				'parent' => null === $parent_comment ? '' : Comments::fingerprint( $parent_comment ),
				'user'   => (int) $user->ID,
			),
			false
		);
	}

	/**
	 * Inserts the comment through WordPress's comment filters and checks.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the post changed or WordPress refused the comment.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		Recheck::unchanged( $this, $args, $assessment, sprintf( 'Post %d', (int) $args['post_id'] ) );
		$user = wp_get_current_user();

		$data     = wp_filter_comment(
			wp_slash(
				array(
					'comment_post_ID'      => (int) $args['post_id'],
					'comment_parent'       => isset( $args['parent'] ) ? (int) $args['parent'] : 0,
					'comment_content'      => (string) $args['content'],
					'comment_type'         => 'comment',
					'user_id'              => (int) $user->ID,
					'comment_author'       => (string) $user->display_name,
					'comment_author_email' => (string) $user->user_email,
					'comment_author_url'   => (string) $user->user_url,
					'comment_author_IP'    => '',
					'comment_agent'        => 'FlitAgent',
					'comment_date'         => current_time( 'mysql' ),
					'comment_date_gmt'     => current_time( 'mysql', 1 ),
				)
			)
		);
		$approved = wp_allow_comment( $data, true );
		if ( is_wp_error( $approved ) ) {
			throw new ToolError( sprintf( 'WordPress refused the comment (%s); nothing was posted.', esc_textarea( $approved->get_error_code() ) ) );
		}
		$data['comment_approved'] = $approved;

		$comment_id = wp_insert_comment( $data );
		if ( ! $comment_id ) {
			throw new ToolError( 'WordPress could not save the comment.' );
		}
		$comment_id = (int) $comment_id;
		$comment    = get_comment( $comment_id );

		return new Outcome(
			array(
				'comment_id' => $comment_id,
				'post_id'    => (int) $args['post_id'],
				'parent'     => isset( $args['parent'] ) ? (int) $args['parent'] : 0,
				'status'     => Comments::status( $comment_id ),
			),
			Rollback::make( Rollback::TRASH, 'comment', $comment_id ),
			array( 'fingerprint' => $comment ? Comments::fingerprint( $comment ) : '' )
		);
	}

	/**
	 * Reads the comment back and compares its content with the request.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$comment = get_comment( (int) $outcome->result()['comment_id'] );
		if ( ! $comment ) {
			return $outcome->with_verification_failure( 'The comment could not be read back.' );
		}
		if ( (string) $comment->comment_content === (string) $args['content'] ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result( array( 'stored_verbatim' => false ) )
			->with_warning( 'stored_differently', 'WordPress stored the comment content differently from the request (comment filters removed or changed markup).' );
	}

	/**
	 * Assesses trashing the comment. Read-only.
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
		$status = Comments::status( $comment_id );
		if ( 'trash' === $status ) {
			return Assessment::prohibited( 'already_trashed', sprintf( 'Comment %d is already in the trash.', $comment_id ) );
		}

		$preview       = array(
			'comment_id' => $comment_id,
			'current'    => array(
				'status'  => $status,
				'content' => (string) $comment->comment_content,
			),
			'restored'   => array( 'status' => $this->trash ? 'trash' : 'deleted' ),
		);
		$preconditions = array( 'fingerprint' => Comments::fingerprint( $comment ) );
		if ( ! $this->trash ) {
			return Assessment::destructive( sprintf( 'Delete comment %d permanently: the trash is disabled on this site, so trashing deletes.', $comment_id ), $preview, $preconditions );
		}
		return Assessment::reversible( sprintf( 'Move comment %d (posted by that write) to the trash.', $comment_id ), $preview, $preconditions, true );
	}

	/**
	 * Trashes the comment if it is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the comment changed or WordPress could not trash it.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$comment_id = (int) $rollback['target']['id'];
		$comment    = get_comment( $comment_id );
		if ( ! $comment || ! isset( $expected_state['fingerprint'] ) || Comments::fingerprint( $comment ) !== $expected_state['fingerprint'] ) {
			throw new ToolError( sprintf( 'Comment %d changed after the rollback was assessed; nothing was trashed.', (int) $comment_id ) );
		}
		if ( ! wp_trash_comment( $comment_id ) ) {
			throw new ToolError( sprintf( 'WordPress could not trash comment %d.', (int) $comment_id ) );
		}

		$outcome = new Outcome(
			array(
				'comment_id' => $comment_id,
				'status'     => $this->trash ? 'trash' : 'deleted',
			),
			Rollback::none()
		);
		$status  = get_comment( $comment_id ) ? Comments::status( $comment_id ) : 'deleted';
		return in_array( $status, array( 'trash', 'deleted' ), true ) ? $outcome : $outcome->with_verification_failure( 'The comment is not in the trash after the rollback.' );
	}
}
