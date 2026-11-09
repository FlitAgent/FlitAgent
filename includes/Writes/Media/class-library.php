<?php
/**
 * Shared reads of media library attachments.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Media;

use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Summary;

defined( 'ABSPATH' ) || exit;

/**
 * Attachment lookups, fingerprints and references for the media write tools. Read-only.
 */
final class Library {

	/**
	 * Alt text meta key.
	 */
	const ALT = '_wp_attachment_image_alt';

	/**
	 * Statuses whose posts count as references.
	 */
	const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Most references listed per kind.
	 */
	const LIMIT = 50;

	/**
	 * Most posts whose content is checked for the file URL.
	 */
	const CANDIDATES = 200;

	/**
	 * The attachment, or null if the id is not an attachment.
	 *
	 * @param int $id Attachment id.
	 * @return object|null
	 */
	public static function attachment( int $id ) {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post && 'attachment' === $post->post_type ? $post : null;
	}

	/**
	 * File path relative to the uploads folder (`_wp_attached_file`).
	 *
	 * @param object $post Attachment.
	 * @return string
	 */
	public static function file( $post ): string {
		return (string) get_post_meta( $post->ID, '_wp_attached_file', true );
	}

	/**
	 * Alt text.
	 *
	 * @param object $post Attachment.
	 * @return string
	 */
	public static function alt( $post ): string {
		return (string) get_post_meta( $post->ID, self::ALT, true );
	}

	/**
	 * Fingerprint of everything the media tools write or depend on: title, caption, description, alt text, file, type
	 * and parent.
	 *
	 * @param object $post Attachment.
	 * @return string
	 */
	public static function fingerprint( $post ): string {
		return Canonical::hash(
			array(
				(string) $post->post_title,
				(string) $post->post_excerpt,
				(string) $post->post_content,
				self::alt( $post ),
				self::file( $post ),
				(string) $post->post_mime_type,
				(int) $post->post_parent,
			)
		);
	}

	/**
	 * Posts that use the attachment: as featured image (`_thumbnail_id`), or by its file URL (any size) in their
	 * content. At most LIMIT of each, oldest first; `truncated` when there may be more.
	 *
	 * @param object $post Attachment.
	 * @return array{featured: array<int, array<string, mixed>>, content: array<int, array<string, mixed>>, truncated: bool}
	 */
	public static function references( $post ): array {
		$types = array_values( array_diff( get_post_types( array( 'show_in_rest' => true ) ), array( 'attachment' ) ) );
		// get_posts() suppresses query filters by default, so other plugins cannot hide a reference from the preview.
		$query = array(
			'post_type'   => $types,
			'post_status' => self::STATUSES,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		);

		$featured = get_posts(
			$query + array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One indexed meta key, at most LIMIT + 1 rows.
				'meta_key'    => '_thumbnail_id',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
				'meta_value'  => (string) $post->ID,
				'numberposts' => self::LIMIT + 1,
			)
		);

		$content    = array();
		$candidates = array();
		$file       = self::file( $post );
		$extension  = (string) pathinfo( $file, PATHINFO_EXTENSION );
		if ( '' !== $file && '' !== $extension ) {
			$stem       = substr( $file, 0, -strlen( $extension ) - 1 );
			$pattern    = '/(?<![A-Za-z0-9._-])' . preg_quote( $stem, '/' ) . '(?:-e[0-9]+)?(?:-scaled|-rotated)?(?:-[0-9]+x[0-9]+)?\.' . preg_quote( $extension, '/' ) . '/i';
			$candidates = get_posts(
				$query + array(
					's'           => $stem,
					'sentence'    => true,
					'numberposts' => self::CANDIDATES,
				)
			);
			foreach ( $candidates as $candidate ) {
				if ( 1 === preg_match( $pattern, (string) $candidate->post_content ) ) {
					$content[] = $candidate;
				}
			}
		}

		return array(
			'featured'  => self::entries( array_slice( $featured, 0, self::LIMIT ) ),
			'content'   => self::entries( array_slice( $content, 0, self::LIMIT ) ),
			'truncated' => count( $featured ) > self::LIMIT || count( $content ) > self::LIMIT || count( $candidates ) >= self::CANDIDATES,
		);
	}

	/**
	 * Ids of the referencing posts, for preconditions.
	 *
	 * @param array<string, mixed> $references references() result.
	 * @return array<string, int[]>
	 */
	public static function reference_ids( array $references ): array {
		return array(
			'featured' => array_column( $references['featured'], 'id' ),
			'content'  => array_column( $references['content'], 'id' ),
		);
	}

	/**
	 * Number of referencing posts listed.
	 *
	 * @param array<string, mixed> $references references() result.
	 * @return int
	 */
	public static function count( array $references ): int {
		return count( array_unique( array_merge( array_column( $references['featured'], 'id' ), array_column( $references['content'], 'id' ) ) ) );
	}

	/**
	 * The summary sentence for an image that posts use: "1 post uses it…", "3 posts use it…", or "20 or more posts use
	 * it…" when the search stopped at its limit.
	 *
	 * @param int  $used      Number of referencing posts listed.
	 * @param bool $truncated Whether the search stopped before it found them all.
	 * @return string
	 */
	public static function used_note( int $used, bool $truncated ): string {
		if ( $truncated ) {
			return sprintf( '%d or more posts use it and would show a broken image.', $used );
		}
		return Summary::count( $used, 'post uses', 'posts use' ) . ' it and would show a broken image.';
	}

	/**
	 * Reference entries.
	 *
	 * @param object[] $posts Posts.
	 * @return array<int, array<string, mixed>>
	 */
	private static function entries( array $posts ): array {
		$entries = array();
		foreach ( $posts as $post ) {
			$entries[] = array(
				'id'     => (int) $post->ID,
				'type'   => (string) $post->post_type,
				'status' => (string) $post->post_status,
				'title'  => Summary::short( (string) $post->post_title ),
			);
		}
		return $entries;
	}
}
