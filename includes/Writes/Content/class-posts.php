<?php
/**
 * Shared rules of the content write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Content;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Summary;

defined( 'ABSPATH' ) || exit;

/**
 * Which posts the content tools may touch, how a post's state is fingerprinted, and trash handling.
 */
final class Posts {

	/**
	 * Statuses that are not publicly visible: only writes that keep a post in one of them can be auto-approved.
	 * `private` and `future` are not included.
	 */
	const NOT_PUBLIC = array( 'draft', 'pending' );

	/**
	 * Each status as a summary says it.
	 */
	const STATUS_WORDS = array(
		'draft'   => 'draft',
		'pending' => 'pending review',
		'publish' => 'published',
		'private' => 'private',
		'future'  => 'scheduled',
		'trash'   => 'trashed',
	);

	/**
	 * Statuses a tool may set.
	 */
	const STATUSES = array( 'draft', 'pending', 'publish', 'private', 'future' );

	/**
	 * Statuses that need the type's `publish_posts` capability.
	 */
	const PUBLISHING = array( 'publish', 'private', 'future' );

	/**
	 * Post types that store code a site runs.
	 */
	const CODE_TYPES = array( 'wpcode', 'custom-css-js', 'wbcr-snippets', 'elementor_snippet' );

	/**
	 * Post types another write package (media, templates and styles, menus) or nothing at all owns.
	 */
	const OTHER_TYPES = array( 'attachment', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'custom_css', 'customize_changeset', 'nav_menu_item', 'wp_font_family', 'wp_font_face', 'user_request', 'oembed_cache' );

	/**
	 * Page-builder template types (their layout lives in builder meta; builder saves are a separate package).
	 */
	const BUILDER_TYPES = array( 'elementor_library', 'bricks_template', 'fl-builder-template' );

	/**
	 * Valid date format for `date` arguments (site time).
	 */
	const DATE_PATTERN = '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$';

	/**
	 * A post the caller holds `$capability` on, or a refusal: a missing post, a revision and a post the caller may not
	 * touch get the same message; unsupported types are refused (see type_refusal()).
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $capability `edit_post` or `delete_post`.
	 * @param string $verb       What the refusal says the caller cannot do; '' for the capability's verb.
	 * @return object|Assessment
	 */
	public static function find( int $post_id, string $capability, string $verb = '' ) {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post || 'revision' === $post->post_type || ! current_user_can( $capability, $post_id ) ) {
			if ( '' === $verb ) {
				$verb = 'delete_post' === $capability ? 'delete' : 'edit';
			}
			return Assessment::prohibited( 'not_found', sprintf( 'Post %d was not found or you cannot %s it.', $post_id, $verb ) );
		}
		if ( 'auto-draft' === $post->post_status ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'Post %d is an auto-draft; save it first.', $post_id ) );
		}
		$refusal = self::type_refusal( (string) $post->post_type );
		return null === $refusal ? $post : $refusal;
	}

	/**
	 * Like find(), and also refuses trashed posts.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $capability Capability.
	 * @return object|Assessment
	 */
	public static function find_untrashed( int $post_id, string $capability ) {
		$post = self::find( $post_id, $capability );
		if ( ! $post instanceof Assessment && 'trash' === $post->post_status ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'Post %d is in the trash; restore it first with restore_post.', $post_id ) );
		}
		return $post;
	}

	/**
	 * Why items of a post type cannot be written by the content tools, or null.
	 *
	 * @param string $type Post type.
	 * @return Assessment|null
	 */
	public static function type_refusal( string $type ): ?Assessment {
		if ( in_array( $type, self::CODE_TYPES, true ) ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'Post type %s stores code the site runs; FlitAgent never writes code snippets.', $type ) );
		}
		if ( in_array( $type, self::OTHER_TYPES, true ) ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'Post type %s has its own tool (media, templates, styles or menus); the content tools do not write it.', $type ) );
		}
		if ( in_array( $type, self::BUILDER_TYPES, true ) || 0 === strpos( $type, 'breakdance_' ) ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'Post type %s is a page-builder template; the content tools do not write it.', $type ) );
		}
		$object = get_post_type_object( $type );
		if ( ! $object || empty( $object->show_in_rest ) ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'Post type %s is not available over the REST API, so FlitAgent does not edit it.', $type ) );
		}
		return null;
	}

	/**
	 * Refusal when the caller may not give a post of this type a public status.
	 *
	 * @param string $type   Post type.
	 * @param string $status Status to set.
	 * @return Assessment|null
	 */
	public static function publish_refusal( string $type, string $status ): ?Assessment {
		if ( ! in_array( $status, self::PUBLISHING, true ) ) {
			return null;
		}
		$object = get_post_type_object( $type );
		if ( $object && current_user_can( $object->cap->publish_posts ) ) {
			return null;
		}
		return Assessment::prohibited( 'forbidden', sprintf( 'You cannot publish items of type %s (status: %s).', $type, self::status_word( $status ) ) );
	}

	/**
	 * A status in plain words: "published", not "publish".
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_word( string $status ): string {
		return self::STATUS_WORDS[ $status ] ?? $status;
	}

	/**
	 * Whether a status is not public.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_private( string $status ): bool {
		return in_array( $status, self::NOT_PUBLIC, true );
	}

	/**
	 * Checks a `date` argument is a real date.
	 *
	 * @param string $date Date `Y-m-d H:i:s`.
	 * @throws ToolError When it is not.
	 */
	public static function check_date( string $date ): void {
		$parts = array_map( 'intval', preg_split( '/[- :]/', $date ) );
		if ( ! checkdate( $parts[1], $parts[2], $parts[0] ) || $parts[3] > 23 || $parts[4] > 59 || $parts[5] > 59 ) {
			throw new ToolError( sprintf( 'date %s is not a valid date.', esc_textarea( $date ) ) );
		}
	}

	/**
	 * Checks a `parent` argument for a post of `$type`: 0, or an existing item of the same hierarchical type that is not
	 * the post itself or one of its descendants.
	 *
	 * @param string $type      Post type.
	 * @param int    $parent_id Parent ID.
	 * @param int    $post_id   The post's ID, or 0 for a new post.
	 * @throws ToolError When invalid.
	 */
	public static function check_parent( string $type, int $parent_id, int $post_id ): void {
		$object = get_post_type_object( $type );
		if ( ! $object || empty( $object->hierarchical ) ) {
			throw new ToolError( sprintf( 'Post type %s is not hierarchical, so it has no parent.', esc_textarea( $type ) ) );
		}
		if ( 0 === $parent_id ) {
			return;
		}
		$ancestor = $parent_id;
		for ( $depth = 0; $ancestor > 0 && $depth < 100; $depth++ ) {
			if ( $ancestor === $post_id ) {
				throw new ToolError( sprintf( 'Post %d cannot be its own parent or ancestor.', (int) $post_id ) );
			}
			$found = get_post( $ancestor );
			if ( ! $found || $found->post_type !== $type || 'trash' === $found->post_status ) {
				throw new ToolError( sprintf( 'Parent %d is not an existing %s.', (int) $ancestor, esc_textarea( $type ) ) );
			}
			$ancestor = (int) $found->post_parent;
		}
	}

	/**
	 * The site's current time, `Y-m-d H:i:s`.
	 *
	 * @return string
	 */
	public static function now(): string {
		return (string) current_time( 'mysql' );
	}

	/**
	 * Whether the site keeps a trash. With `EMPTY_TRASH_DAYS` 0, wp_trash_post() deletes permanently.
	 *
	 * @return bool
	 */
	public static function trash_enabled(): bool {
		return ! defined( 'EMPTY_TRASH_DAYS' ) || (int) constant( 'EMPTY_TRASH_DAYS' ) > 0;
	}

	/**
	 * Refusal when the trash is disabled.
	 *
	 * @param int $post_id Post ID.
	 * @return Assessment|null
	 */
	public static function trash_refusal( int $post_id ): ?Assessment {
		return self::trash_enabled() ? null : Assessment::prohibited( 'trash_disabled', sprintf( 'The trash is disabled on this site (EMPTY_TRASH_DAYS is 0), so trashing post %d would delete it permanently.', $post_id ) );
	}

	/**
	 * Moves a post to the trash.
	 *
	 * @param int $post_id Post ID.
	 * @throws ToolError When the trash is disabled or WordPress did not trash it.
	 */
	public static function trash( int $post_id ): void {
		if ( ! self::trash_enabled() ) {
			throw new ToolError( sprintf( 'The trash is disabled, so post %d was not trashed.', (int) $post_id ) );
		}
		if ( ! wp_trash_post( $post_id ) ) {
			throw new ToolError( sprintf( 'WordPress could not move post %d to the trash.', (int) $post_id ) );
		}
	}

	/**
	 * Restores a post from the trash with the given status (core would use `draft`).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Status to restore.
	 * @throws ToolError When WordPress did not restore it.
	 */
	public static function untrash( int $post_id, string $status ): void {
		$set = static function () use ( $status ) {
			return $status;
		};
		add_filter( 'wp_untrash_post_status', $set, PHP_INT_MAX );
		$restored = wp_untrash_post( $post_id );
		remove_filter( 'wp_untrash_post_status', $set, PHP_INT_MAX );
		if ( ! $restored ) {
			throw new ToolError( sprintf( 'WordPress could not restore post %d from the trash.', (int) $post_id ) );
		}
	}

	/**
	 * Preconditions: modification time, status and the full fingerprint.
	 *
	 * @param object $post Post.
	 * @return array<string, string>
	 */
	public static function state( $post ): array {
		return array(
			'fingerprint'       => self::fingerprint( $post ),
			'post_modified_gmt' => (string) $post->post_modified_gmt,
			'status'            => (string) $post->post_status,
		);
	}

	/**
	 * Fingerprint of everything the post tools write: text, status, slug, dates, parent, author, template and featured
	 * image.
	 *
	 * @param object $post Post.
	 * @return string
	 */
	public static function fingerprint( $post ): string {
		return Canonical::hash(
			array(
				self::text_fingerprint( $post ),
				(string) $post->post_status,
				(string) $post->post_name,
				(string) $post->post_date,
				(string) $post->post_date_gmt,
				(int) $post->post_parent,
				(string) $post->post_author,
				(string) get_page_template_slug( $post ),
				self::featured_id( $post ),
			)
		);
	}

	/**
	 * The post's featured image attachment id, 0 for none.
	 *
	 * @param object $post Post.
	 * @return int
	 */
	public static function featured_id( $post ): int {
		return (int) get_post_thumbnail_id( $post->ID );
	}

	/**
	 * Checks a featured image for a post type, like the REST API's featured_media: the type must support thumbnails,
	 * and a non-zero id must be an image attachment, not trashed, that the caller can read. Read-only.
	 *
	 * @param string $type          Post type.
	 * @param int    $attachment_id Attachment id, 0 to remove.
	 * @return Assessment|null A refusal, or null.
	 * @throws ToolError When the attachment cannot be used.
	 */
	public static function check_featured( string $type, int $attachment_id ): ?Assessment {
		if ( ! post_type_supports( $type, 'thumbnail' ) ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'Post type %s does not support featured images.', $type ) );
		}
		if ( 0 === $attachment_id ) {
			return null;
		}
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type || 'trash' === $attachment->post_status || ! wp_attachment_is_image( $attachment_id ) || ! current_user_can( 'read_post', $attachment_id ) ) {
			throw new ToolError( sprintf( 'Attachment %d was not found or is not an image you can use as a featured image.', (int) $attachment_id ) );
		}
		return null;
	}

	/**
	 * Sets (or with 0 removes) a post's featured image.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $attachment_id Attachment id, 0 to remove.
	 */
	public static function set_featured( int $post_id, int $attachment_id ): void {
		if ( 0 === $attachment_id ) {
			delete_post_thumbnail( $post_id );
			return;
		}
		set_post_thumbnail( $post_id, $attachment_id );
	}

	/**
	 * Title of a featured image attachment for previews, or null for none.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string|null
	 */
	public static function featured_title( int $attachment_id ): ?string {
		$attachment = 0 === $attachment_id ? null : get_post( $attachment_id );
		return $attachment ? (string) $attachment->post_title : null;
	}

	/**
	 * Fingerprint of the revisioned fields: title, content and excerpt.
	 *
	 * @param object $post Post or revision.
	 * @return string
	 */
	public static function text_fingerprint( $post ): string {
		return Canonical::hash( array( (string) $post->post_title, (string) $post->post_content, (string) $post->post_excerpt ) );
	}

	/**
	 * Singular label of the post's type, lower case.
	 *
	 * @param object $post Post.
	 * @return string
	 */
	public static function label( $post ): string {
		$type = get_post_type_object( $post->post_type );
		return $type && isset( $type->labels->singular_name ) ? strtolower( (string) $type->labels->singular_name ) : (string) $post->post_type;
	}

	/**
	 * `"Title"` for every summary that names a post (Summary::named()), or `post 5` when it has no title.
	 *
	 * @param object $post Post.
	 * @return string
	 */
	public static function name( $post ): string {
		return Summary::named( (string) $post->post_title, sprintf( '%s %d', self::label( $post ), (int) $post->ID ) );
	}

	/**
	 * `the page "Home" (ID 2)` for a destructive summary (Summary::named_with_id()).
	 *
	 * @param object $post Post.
	 * @return string
	 */
	public static function name_with_id( $post ): string {
		return Summary::named_with_id( self::label( $post ), (string) $post->post_title, (int) $post->ID );
	}

	/**
	 * " This changes the live page." when the post is published, '' otherwise (Summary::live_note()).
	 *
	 * @param object $post Post.
	 * @return string
	 */
	public static function live_note( $post ): string {
		return Summary::live_note( (string) $post->post_status, self::label( $post ) );
	}

	/**
	 * A title shortened for a summary.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function short( string $title ): string {
		return Summary::short( $title );
	}
}
