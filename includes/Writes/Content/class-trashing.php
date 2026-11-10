<?php
/**
 * Moving posts to and from the trash, with assessment.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Content;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Rollback;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by trash_post and restore_post, and by the rollbacks of create_post (trash it) and restore_post (trash it
 * again) and trash_post (restore it).
 */
final class Trashing {

	/**
	 * Assesses moving a post to the trash: needs `delete_post`, a post that is not trashed and an enabled trash.
	 * Auto-approvable only when the post is not public. Read-only.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $context  Sentence with %s for the post, e.g. "Move %s to the trash".
	 * @param bool   $rollback Whether this is a rollback (preview `current`/`restored` instead of `before`/`after`).
	 * @return Assessment
	 */
	public static function assess_trash( int $post_id, string $context, bool $rollback ): Assessment {
		$post = Posts::find( $post_id, 'delete_post' );
		if ( $post instanceof Assessment ) {
			return $post;
		}
		if ( 'trash' === $post->post_status ) {
			return Assessment::prohibited( 'already_trashed', sprintf( 'Post %d is already in the trash.', $post_id ) );
		}
		$refusal = Posts::trash_refusal( $post_id );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$public = ! Posts::is_private( (string) $post->post_status );
		$note   = array(
			'publish' => ' This takes it off the live site.',
			'future'  => ' This cancels its scheduled publication.',
		);
		return Assessment::reversible(
			sprintf( $context, Posts::name( $post ) ) . '.' . ( $note[ (string) $post->post_status ] ?? '' ),
			self::preview( $post_id, (string) $post->post_status, 'trash', $rollback ),
			Posts::state( $post ),
			! $public
		);
	}

	/**
	 * Assesses restoring a trashed post with a status: needs `delete_post` (as core's untrash) and a trashed post.
	 * Auto-approvable only when the restored status is not public. Read-only.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $status   Status to restore, or '' for the status before trashing.
	 * @param string $context  Sentence start.
	 * @param bool   $rollback Whether this is a rollback.
	 * @return Assessment
	 */
	public static function assess_untrash( int $post_id, string $status, string $context, bool $rollback ): Assessment {
		// A rollback names a post from the caller's own receipt, so saying it is gone reveals nothing new.
		if ( $rollback && ( $post_id < 1 || ! get_post( $post_id ) ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Post %d no longer exists, so it cannot be restored.', $post_id ) );
		}
		$post = Posts::find( $post_id, 'delete_post', $rollback ? 'restore' : '' );
		if ( $post instanceof Assessment ) {
			return $post;
		}
		if ( 'trash' !== $post->post_status ) {
			return Assessment::prohibited( 'not_in_trash', sprintf( 'Post %d is not in the trash (status: %s).', $post_id, Posts::status_word( (string) $post->post_status ) ) );
		}
		if ( '' === $status ) {
			$status = self::status_before_trash( $post_id );
		}
		$as   = 'as ' . Posts::status_word( $status );
		$note = 'future' === $status ? sprintf( ' It will be published on its scheduled date, %s.', $post->post_date ) : '';
		// WordPress publishes a scheduled item whose date has passed as soon as it is restored.
		if ( 'future' === $status && (string) $post->post_date <= Posts::now() ) {
			$status = 'publish';
			$as     = sprintf( 'as scheduled; its date %s has passed, so it will be published now', $post->post_date );
		}
		if ( 'publish' === $status ) {
			$note = ' This puts it back on the live site.';
		}
		return Assessment::reversible(
			sprintf( '%s %s from the trash %s.%s', $context, Posts::name( $post ), $as, $note ),
			self::preview( $post_id, 'trash', $status, $rollback ),
			Posts::state( $post ) + array( 'restore_status' => $status ),
			Posts::is_private( $status )
		);
	}

	/**
	 * Preview of a status change.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $from     Current status.
	 * @param string $to       New status.
	 * @param bool   $rollback Whether this is a rollback.
	 * @return array<string, mixed>
	 */
	private static function preview( int $post_id, string $from, string $to, bool $rollback ): array {
		return array(
			'post_id'                        => $post_id,
			$rollback ? 'current' : 'before' => array( 'post_status' => $from ),
			$rollback ? 'restored' : 'after' => array( 'post_status' => $to ),
		);
	}

	/**
	 * The status a post had before it was trashed (core keeps it in `_wp_trash_meta_status`), or `draft`.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function status_before_trash( int $post_id ): string {
		$status = (string) get_post_meta( $post_id, '_wp_trash_meta_status', true );
		return in_array( $status, Posts::STATUSES, true ) ? $status : 'draft';
	}

	/**
	 * Trashes the post if it is still as assessed.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param array<string, mixed> $expected Preconditions.
	 * @param array<string, mixed> $refs     Rollback refs of the new state (e.g. the status to restore), or null for none.
	 * @return Outcome
	 * @throws ToolError When the post changed or could not be trashed.
	 */
	public static function trash( int $post_id, array $expected, ?array $refs ): Outcome {
		$post = get_post( $post_id );
		if ( ! $post || Posts::state( $post ) !== array_intersect_key( $expected, array_flip( array( 'fingerprint', 'post_modified_gmt', 'status' ) ) ) ) {
			throw new ToolError( sprintf( 'Post %d changed after it was assessed; nothing was trashed.', (int) $post_id ) );
		}
		Posts::trash( $post_id );
		return self::outcome( $post_id, null === $refs ? Rollback::none() : Rollback::make( Rollback::TRASH, 'post', $post_id, $refs ) );
	}

	/**
	 * Restores the post from the trash with `$expected['restore_status']`, if it is still as assessed.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param array<string, mixed> $expected Preconditions (with `restore_status`).
	 * @param array<string, mixed> $refs     Rollback refs of the new state, or null for none.
	 * @return Outcome
	 * @throws ToolError When the post changed or could not be restored.
	 */
	public static function untrash( int $post_id, array $expected, ?array $refs ): Outcome {
		$post   = get_post( $post_id );
		$status = isset( $expected['restore_status'] ) ? (string) $expected['restore_status'] : '';
		if ( ! $post || ! in_array( $status, Posts::STATUSES, true ) || Posts::state( $post ) + array( 'restore_status' => $status ) !== $expected ) {
			throw new ToolError( sprintf( 'Post %d changed after it was assessed; nothing was restored.', (int) $post_id ) );
		}
		Posts::untrash( $post_id, $status );
		return self::outcome( $post_id, null === $refs ? Rollback::none() : Rollback::make( Rollback::TRASH, 'post', $post_id, $refs ) );
	}

	/**
	 * Outcome with the post's new status and fingerprint.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Outcome
	 */
	private static function outcome( int $post_id, array $rollback ): Outcome {
		$after = get_post( $post_id );
		return new Outcome(
			array(
				'post_id' => $post_id,
				'status'  => $after ? (string) $after->post_status : '',
			),
			$rollback,
			array( 'fingerprint' => $after ? Posts::fingerprint( $after ) : '' )
		);
	}

	/**
	 * Verifies the post now has `$status`.
	 *
	 * @param int     $post_id Post ID.
	 * @param string  $status  Expected status.
	 * @param Outcome $outcome Outcome.
	 * @return Outcome
	 */
	public static function verify( int $post_id, string $status, Outcome $outcome ): Outcome {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $outcome->with_verification_failure( sprintf( 'Post %d could not be read back.', $post_id ) );
		}
		if ( $post->post_status !== $status ) {
			return $outcome->with_warning( 'stored_differently', sprintf( 'Post %d has status %s, not %s.', $post_id, Posts::status_word( (string) $post->post_status ), Posts::status_word( $status ) ) );
		}
		return $outcome;
	}
}
