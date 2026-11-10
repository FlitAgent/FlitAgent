<?php
/**
 * Tool: list_recent_content.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the most recently modified posts and pages, without their content.
 */
final class RecentContent implements Tool {

	/**
	 * Post types listed.
	 *
	 * @var string[]
	 */
	const POST_TYPES = array( 'post', 'page' );

	const DEFAULT_LIMIT = 10;
	const MAX_LIMIT     = 50;

	/**
	 * Statuses shown to callers who may see other people's unpublished content. Excludes trash and auto-drafts.
	 */
	const ALL_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Capabilities needed to see drafts and private items, for posts and pages. Editors and administrators have both;
	 * they can already open other people's drafts and private items in wp-admin.
	 */
	const NON_PUBLIC_CAPABILITIES = array( 'edit_others_posts', 'edit_others_pages' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_recent_content';
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return Tool::ACCESS_PUBLIC;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		$string          = array( 'type' => 'string' );
		$nullable_string = array( 'type' => array( 'string', 'null' ) );

		return array(
			'name'         => $this->name(),
			'title'        => 'List FlitAgent recent content',
			'description'  => 'From the FlitAgent WordPress plugin: lists the most recently modified posts and pages with id, title, type, status, author display name, modified date and link. Content is not included. Published items only, unless the logged-in user can edit other people\'s posts and pages; then drafts, pending, scheduled and private items too.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'limit' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_LIMIT,
						'default'     => self::DEFAULT_LIMIT,
						'description' => 'How many items to return (1 to 50, default 10).',
					),
				),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'items'    => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'       => array( 'type' => 'integer' ),
								'title'    => $string,
								'type'     => array(
									'type' => 'string',
									'enum' => array( 'post', 'page' ),
								),
								'status'   => $string,
								'author'   => array(
									'type'        => array( 'string', 'null' ),
									'description' => 'Author display name; null if the author no longer exists or the name is an email address.',
								),
								'modified' => array(
									'type'        => array( 'string', 'null' ),
									'description' => 'Last modified, ISO 8601 in UTC.',
								),
								'link'     => $nullable_string,
							),
							'required'   => array( 'id', 'title', 'type', 'status', 'author', 'modified', 'link' ),
						),
					),
					'statuses' => array(
						'type'        => 'array',
						'items'       => $string,
						'description' => 'Post statuses this caller is allowed to see.',
					),
				),
				'required'   => array( 'items', 'statuses' ),
			),
			'annotations'  => Annotations::read_only( 'List FlitAgent recent content' ),
		);
	}

	/**
	 * Lists recent posts and pages.
	 *
	 * @param array<string, mixed> $arguments Optional `limit`.
	 * @return array<string, mixed>
	 * @throws ToolError When an argument is invalid.
	 */
	public function call( array $arguments ): array {
		$limit    = self::limit( $arguments );
		$statuses = self::visible_statuses();

		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPES,
				'post_status'      => $statuses,
				'posts_per_page'   => $limit,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				// Explicit: get_posts() suppresses filters by default; other plugins' access and language rules must apply.
				'suppress_filters' => false,
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			// Query filters from other plugins may widen results; the status and type rules still decide.
			if ( ! in_array( $post->post_type, self::POST_TYPES, true ) || ! in_array( $post->post_status, $statuses, true ) ) {
				continue;
			}
			$items[] = self::item( $post );
		}

		return array(
			'items'    => $items,
			'statuses' => $statuses,
		);
	}

	/**
	 * Validates the arguments and returns the limit.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return int
	 * @throws ToolError When an argument is unknown or the limit is invalid.
	 */
	private static function limit( array $arguments ): int {
		foreach ( array_keys( $arguments ) as $key ) {
			if ( 'limit' !== $key ) {
				throw new ToolError( 'Unknown argument. list_recent_content accepts only limit.' );
			}
		}

		if ( ! array_key_exists( 'limit', $arguments ) ) {
			return self::DEFAULT_LIMIT;
		}

		$limit = $arguments['limit'];
		if ( ! is_int( $limit ) || $limit < 1 || $limit > self::MAX_LIMIT ) {
			throw new ToolError( 'limit must be an integer from 1 to 50.' );
		}
		return $limit;
	}

	/**
	 * Post statuses the current user may see: drafts and private items only with every NON_PUBLIC_CAPABILITIES
	 * capability. Shared with search_content so both tools apply the same rules.
	 *
	 * @return string[]
	 */
	public static function visible_statuses(): array {
		foreach ( self::NON_PUBLIC_CAPABILITIES as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				return array( 'publish' );
			}
		}
		return self::ALL_STATUSES;
	}

	/**
	 * Builds one item. Only these fields leave the site: never content, excerpt, password or author email.
	 *
	 * @param object $post WP_Post.
	 * @return array<string, mixed>
	 */
	public static function item( $post ): array {
		$link = get_permalink( $post );

		return array(
			'id'       => (int) $post->ID,
			'title'    => SiteText::plain( (string) $post->post_title ),
			'type'     => (string) $post->post_type,
			'status'   => (string) $post->post_status,
			'author'   => self::author( (int) $post->post_author ),
			'modified' => self::iso_date( $post ),
			'link'     => is_string( $link ) ? $link : null,
		);
	}

	/**
	 * Author display name, or null if missing or an email address.
	 *
	 * @param int $user_id Author ID.
	 * @return string|null
	 */
	private static function author( int $user_id ): ?string {
		// Checked as it will read: `Tom&#64;example.com` is an email address too.
		$name = SiteText::plain( (string) get_the_author_meta( 'display_name', $user_id ) );
		if ( '' === $name || false !== strpos( $name, '@' ) ) {
			return null;
		}
		return $name;
	}

	/**
	 * When a post was last modified, as Unix time. WordPress gives a draft or pending post no GMT date until it is saved
	 * again, so its local date, which is in the site's timezone, is read instead. Shared with get_content_qa.
	 *
	 * @param object $post WP_Post.
	 * @return int|null Null when neither date is set.
	 */
	public static function modified_time( $post ): ?int {
		$time = self::time_of( (string) $post->post_modified_gmt, new \DateTimeZone( 'UTC' ) );
		return null !== $time ? $time : self::time_of( (string) $post->post_modified, wp_timezone() );
	}

	/**
	 * The Unix time of a MySQL date.
	 *
	 * @param string        $date Date as `Y-m-d H:i:s`.
	 * @param \DateTimeZone $zone Timezone of the date.
	 * @return int|null Null for an unset (zero) or invalid date. PHP rolls `2026-13-45` over to a later date with only a
	 *                  warning, so a warning counts as invalid. A time in a daylight saving gap gets none, and stays valid.
	 */
	private static function time_of( string $date, \DateTimeZone $zone ): ?int {
		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date, $zone );
		// False, not an array, from PHP 8.2 when there is nothing to report.
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $parsed || ( is_array( $errors ) && $errors['warning_count'] > 0 ) ) {
			return null;
		}
		return $parsed->getTimestamp();
	}

	/**
	 * When a post was last modified, as ISO 8601 in UTC.
	 *
	 * @param object $post WP_Post.
	 * @return string|null Null when neither date is set.
	 */
	private static function iso_date( $post ): ?string {
		$time = self::modified_time( $post );
		return null === $time ? null : gmdate( 'c', $time );
	}
}
