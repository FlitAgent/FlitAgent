<?php
/**
 * Shared checks for the comment write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\TermsComments;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;

defined( 'ABSPATH' ) || exit;

/**
 * Comment lookups, refusals, fingerprints and status moves used by create_comment, moderate_comment and
 * delete_comment_permanently.
 */
final class Comments {

	/**
	 * Comment types the tools act on: visitor comments, pings and (WooCommerce) reviews. Editor notes and plugin log
	 * types (order notes, action logs, ...) are refused.
	 */
	const TYPES = array( '', 'comment', 'pingback', 'trackback', 'review' );

	/**
	 * Status from wp_get_comment_status() => comment_approved value.
	 */
	const APPROVED_VALUES = array(
		'approved'   => '1',
		'unapproved' => '0',
		'spam'       => 'spam',
		'trash'      => 'trash',
	);

	/**
	 * Whether trashing keeps comments in the trash. When EMPTY_TRASH_DAYS is 0, wp_trash_comment() deletes permanently.
	 */
	public static function trash_enabled(): bool {
		return ! defined( 'EMPTY_TRASH_DAYS' ) || (bool) constant( 'EMPTY_TRASH_DAYS' );
	}

	/**
	 * The comment if it exists, the user has `moderate_comments` and `edit_comment` on it, and its type is supported;
	 * otherwise a refusal (one message for missing and not allowed).
	 *
	 * @param int $comment_id Comment ID.
	 * @return object|Assessment
	 */
	public static function allowed( int $comment_id ) {
		$comment = $comment_id > 0 ? get_comment( $comment_id ) : null;
		if ( ! $comment || ! is_object( $comment ) || ! current_user_can( 'moderate_comments' ) || ! current_user_can( 'edit_comment', $comment_id ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Comment %d was not found or you cannot moderate it.', $comment_id ) );
		}
		if ( ! in_array( (string) $comment->comment_type, self::TYPES, true ) ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'Comment %d is a %s, not a visitor comment, ping or review, so FlitAgent does not change it.', $comment_id, $comment->comment_type ) );
		}
		return $comment;
	}

	/**
	 * Status of a comment: approved, unapproved, spam or trash ('' if unknown).
	 *
	 * @param int $comment_id Comment ID.
	 * @return string
	 */
	public static function status( int $comment_id ): string {
		$status = wp_get_comment_status( $comment_id );
		return is_string( $status ) && isset( self::APPROVED_VALUES[ $status ] ) ? $status : '';
	}

	/**
	 * The status wp_untrash_comment() or wp_unspam_comment() will restore, from `_wp_trash_meta_status`.
	 *
	 * @param int $comment_id Comment ID.
	 * @return string
	 */
	public static function restored_status( int $comment_id ): string {
		$previous = (string) get_comment_meta( $comment_id, '_wp_trash_meta_status', true );
		$status   = array_search( '' === $previous ? '0' : $previous, self::APPROVED_VALUES, true );
		return false === $status || 'trash' === $status ? 'unapproved' : (string) $status;
	}

	/**
	 * Fingerprint of a comment: post, parent, status and content.
	 *
	 * @param object $comment Comment.
	 * @return string
	 */
	public static function fingerprint( $comment ): string {
		return Canonical::hash( array( (int) $comment->comment_ID, (int) $comment->comment_post_ID, (int) $comment->comment_parent, (string) $comment->comment_approved, (string) $comment->comment_content ) );
	}

	/**
	 * Moves a comment to a status the way wp-admin does: out of the trash or spam first (restoring and cleaning the
	 * stored previous status), then to the target.
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $from       Current status.
	 * @param string $to         Target status (approved, unapproved, spam, trash).
	 * @throws ToolError When WordPress refuses a step.
	 */
	public static function move( int $comment_id, string $from, string $to ): void {
		$ok = true;
		if ( 'trash' === $from && 'trash' !== $to ) {
			$ok = (bool) wp_untrash_comment( $comment_id );
		} elseif ( 'spam' === $from && 'spam' !== $to ) {
			$ok = (bool) wp_unspam_comment( $comment_id );
		}
		if ( $ok && self::status( $comment_id ) !== $to ) {
			switch ( $to ) {
				case 'approved':
					$ok = (bool) wp_set_comment_status( $comment_id, 'approve' );
					break;
				case 'unapproved':
					$ok = (bool) wp_set_comment_status( $comment_id, 'hold' );
					break;
				case 'spam':
					$ok = (bool) wp_spam_comment( $comment_id );
					break;
				case 'trash':
					$ok = (bool) wp_trash_comment( $comment_id );
					break;
				default:
					$ok = false;
			}
		}
		if ( ! $ok ) {
			throw new ToolError( sprintf( 'WordPress could not move comment %d to %s.', (int) $comment_id, esc_textarea( $to ) ) );
		}
	}

	/**
	 * Comment content shortened for a preview.
	 *
	 * @param string $content Content.
	 * @param int    $length  Most characters.
	 * @return string
	 */
	public static function excerpt( string $content, int $length = 200 ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $content ) ) );
		preg_match( '/^.{0,' . $length . '}/su', $text, $match );
		$short = isset( $match[0] ) ? $match[0] : '';
		return $short === $text ? $text : $short . '...';
	}
}
