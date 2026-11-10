<?php
/**
 * Tool: search_content.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Searches posts and pages, with the same visibility rules as list_recent_content and a short plain-text snippet.
 */
final class ContentSearch implements Tool {

	const DEFAULT_LIMIT    = 10;
	const MAX_LIMIT        = 50;
	const MAX_QUERY_LENGTH = 200;
	const SNIPPET_LENGTH   = 160;

	/**
	 * Most bytes of a post's source that get a snippet, which keeps the work on a huge post small. A longer one gets none: cut
	 * at this length it could keep an opening shortcode, script, style or comment without its close, and show what that hides.
	 */
	const SNIPPET_SOURCE_BYTES = 65536;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'search_content';
	}

	/**
	 * Public: published items are what any visitor finds with the site's own search; drafts and private items need
	 * the same editor capabilities as list_recent_content.
	 */
	public function access(): string {
		return Tool::ACCESS_PUBLIC;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		$string = array( 'type' => 'string' );

		return array(
			'name'         => $this->name(),
			'title'        => 'Search FlitAgent content',
			'description'  => 'From the FlitAgent WordPress plugin: searches posts and pages for words in their title, excerpt or content, like the site\'s own search, most relevant first. Returns id, title, type, status, author display name, modified date, link and a short plain-text snippet (none for password-protected or very long items); never the full content. Published items only, unless the logged-in user can edit other people\'s posts and pages; then drafts, pending, scheduled and private items too.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'query' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => self::MAX_QUERY_LENGTH,
						'description' => 'Words to search for.',
					),
					'limit' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_LIMIT,
						'default'     => self::DEFAULT_LIMIT,
						'description' => 'How many items to return (1 to 50, default 10).',
					),
					'type'  => array(
						'type'        => 'string',
						'enum'        => RecentContent::POST_TYPES,
						'description' => 'Optional: only posts or only pages.',
					),
				),
				'required'             => array( 'query' ),
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
									'enum' => RecentContent::POST_TYPES,
								),
								'status'   => $string,
								'author'   => array( 'type' => array( 'string', 'null' ) ),
								'modified' => array( 'type' => array( 'string', 'null' ) ),
								'link'     => array( 'type' => array( 'string', 'null' ) ),
								'snippet'  => array(
									'type'        => array( 'string', 'null' ),
									'description' => 'Plain-text start of the excerpt or content, at most 160 characters; null for password-protected, empty or very long items.',
								),
							),
							'required'   => array( 'id', 'title', 'type', 'status', 'author', 'modified', 'link', 'snippet' ),
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
			'annotations'  => Annotations::read_only( 'Search FlitAgent content' ),
		);
	}

	/**
	 * Runs the search.
	 *
	 * @param array<string, mixed> $arguments `query`, optional `limit` and `type`.
	 * @return array<string, mixed>
	 * @throws ToolError When an argument is invalid.
	 */
	public function call( array $arguments ): array {
		list( $search, $limit, $types ) = self::arguments( $arguments );
		$statuses                       = RecentContent::visible_statuses();

		$posts = get_posts(
			array(
				's'                => $search,
				'post_type'        => $types,
				'post_status'      => $statuses,
				'posts_per_page'   => $limit,
				// Empty, not 'relevance': core ranks a search itself when it has a word to rank, and otherwise orders by date.
				// 'relevance' forces the ranking even for a search of only exclusions (-word), which is invalid SQL.
				'orderby'          => '',
				// Explicit: get_posts() suppresses filters by default; other plugins' access and language rules must apply.
				'suppress_filters' => false,
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			// Query filters from other plugins may widen results; the status and type rules still decide.
			if ( ! in_array( $post->post_type, $types, true ) || ! in_array( $post->post_status, $statuses, true ) ) {
				continue;
			}
			$items[] = array_merge(
				RecentContent::item( $post ),
				array( 'snippet' => self::snippet( $post ) )
			);
		}

		return array(
			'items'    => $items,
			'statuses' => $statuses,
		);
	}

	/**
	 * Validates the arguments.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array{0: string, 1: int, 2: string[]} Search text, limit and post types.
	 * @throws ToolError When an argument is unknown or invalid.
	 */
	private static function arguments( array $arguments ): array {
		foreach ( array_keys( $arguments ) as $key ) {
			if ( ! in_array( $key, array( 'query', 'limit', 'type' ), true ) ) {
				throw new ToolError( 'Unknown argument. search_content accepts only query, limit and type.' );
			}
		}

		$search = isset( $arguments['query'] ) && is_string( $arguments['query'] ) ? trim( $arguments['query'] ) : '';
		if ( '' === $search || mb_strlen( $search, 'UTF-8' ) > self::MAX_QUERY_LENGTH ) {
			throw new ToolError( 'query must be a search text of 1 to 200 characters.' );
		}

		$limit = array_key_exists( 'limit', $arguments ) ? $arguments['limit'] : self::DEFAULT_LIMIT;
		if ( ! is_int( $limit ) || $limit < 1 || $limit > self::MAX_LIMIT ) {
			throw new ToolError( 'limit must be an integer from 1 to 50.' );
		}

		$types = RecentContent::POST_TYPES;
		if ( array_key_exists( 'type', $arguments ) ) {
			if ( ! in_array( $arguments['type'], RecentContent::POST_TYPES, true ) ) {
				throw new ToolError( 'type must be post or page.' );
			}
			$types = array( $arguments['type'] );
		}

		return array( $search, $limit, $types );
	}

	/**
	 * Plain-text snippet: the excerpt, or else the content, without HTML, block markup or shortcodes.
	 *
	 * @param object $post WP_Post.
	 * @return string|null Null for password-protected or empty items, and for a source over SNIPPET_SOURCE_BYTES.
	 */
	private static function snippet( $post ): ?string {
		if ( '' !== (string) $post->post_password ) {
			return null;
		}

		$source = '' !== trim( (string) $post->post_excerpt ) ? (string) $post->post_excerpt : (string) $post->post_content;
		if ( strlen( $source ) > self::SNIPPET_SOURCE_BYTES ) {
			return null;
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', SiteText::plain( strip_shortcodes( $source ) ) ) );

		if ( '' === $text ) {
			return null;
		}
		if ( mb_strlen( $text, 'UTF-8' ) > self::SNIPPET_LENGTH ) {
			$text = rtrim( mb_substr( $text, 0, self::SNIPPET_LENGTH - 1, 'UTF-8' ) ) . '…';
		}
		return $text;
	}
}
