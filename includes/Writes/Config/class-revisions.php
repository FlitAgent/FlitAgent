<?php
/**
 * Revision rollback points for templates and global styles.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;

defined( 'ABSPATH' ) || exit;

/**
 * Saves a revision of a post's current state before a write (like update_post), finds it again, and refuses posts
 * whose revisions are off.
 */
final class Revisions {

	/**
	 * Fields a revision restore puts back.
	 */
	const FIELDS = array( 'post_content', 'post_title', 'post_excerpt' );

	/**
	 * Refusal when the post's type or the site does not keep revisions, or null.
	 *
	 * @param object $post Post.
	 * @param string $what What is being changed, for the message.
	 * @return Assessment|null
	 */
	public static function refusal( $post, string $what ): ?Assessment {
		if ( ! post_type_supports( $post->post_type, 'revisions' ) || ! wp_revisions_enabled( $post ) ) {
			return Assessment::prohibited( 'no_revisions', sprintf( 'Revisions are off for %s, so the change could not be rolled back.', $what ) );
		}
		return null;
	}

	/**
	 * Saves a revision identical to the current state (unless the latest one already is) and returns its id.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 * @throws ToolError When no revision could be saved.
	 */
	public static function save_current( int $post_id ): int {
		wp_save_post_revision( $post_id );
		$post     = get_post( $post_id );
		$revision = $post ? self::latest_matching( $post ) : 0;
		if ( 0 === $revision ) {
			throw new ToolError( sprintf( 'Could not save a revision of post %d, so nothing was changed.', (int) $post_id ) );
		}
		return $revision;
	}

	/**
	 * The revision a rollback points to, if it still belongs to the post.
	 *
	 * @param int $revision_id Revision id.
	 * @param int $post_id     Post id.
	 * @return object|null
	 */
	public static function revision( int $revision_id, int $post_id ) {
		$revision = $revision_id > 0 ? wp_get_post_revision( $revision_id ) : null;
		return $revision && (int) $revision->post_parent === $post_id ? $revision : null;
	}

	/**
	 * Restores the content, title and excerpt from a revision.
	 *
	 * @param int $revision_id Revision id.
	 * @throws ToolError When WordPress could not restore it.
	 */
	public static function restore( int $revision_id ): void {
		if ( ! wp_restore_post_revision( $revision_id, self::FIELDS ) ) {
			throw new ToolError( sprintf( 'WordPress could not restore revision %d.', (int) $revision_id ) );
		}
	}

	/**
	 * Fingerprint of a post's content, title and excerpt.
	 *
	 * @param object|null $post Post or revision.
	 * @return string
	 */
	public static function fingerprint( $post ): string {
		return Canonical::hash( $post ? array( (string) $post->post_content, (string) $post->post_title, (string) $post->post_excerpt ) : null );
	}

	/**
	 * The latest non-autosave revision whose content, title and excerpt equal the post's, or 0.
	 *
	 * @param object $post Post.
	 * @return int
	 */
	private static function latest_matching( $post ): int {
		foreach ( wp_get_post_revisions( $post->ID ) as $revision ) {
			if ( false === strpos( (string) $revision->post_name, 'autosave' ) ) {
				return self::comparable( $revision ) === self::comparable( $post ) ? (int) $revision->ID : 0;
			}
		}
		return 0;
	}

	/**
	 * Content, title and excerpt for matching a revision. JSON content (global styles) is compared by value, because
	 * WordPress re-encodes it when it saves the revision.
	 *
	 * @param object $post Post or revision.
	 * @return string
	 */
	private static function comparable( $post ): string {
		$content = (string) $post->post_content;
		$decoded = json_decode( $content, true );
		return Canonical::hash( array( is_array( $decoded ) ? Canonical::json( $decoded ) : $content, (string) $post->post_title, (string) $post->post_excerpt ) );
	}
}
