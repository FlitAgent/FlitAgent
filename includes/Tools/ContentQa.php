<?php
/**
 * Tool: get_content_qa.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Triage\Content;
use FlitAgent\Tools\Triage\ContentSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Content QA (#121): content problems and what to do about each in wp-admin.
 */
final class ContentQa implements Tool {

	/**
	 * Returns the snapshot for post types and a limit.
	 *
	 * @var callable
	 */
	private $snapshot;

	/**
	 * Constructor.
	 *
	 * @param callable|null $snapshot Takes (string[] $types, int $limit) and returns the snapshot; ContentSnapshot::read by default.
	 */
	public function __construct( ?callable $snapshot = null ) {
		$this->snapshot = $snapshot ? $snapshot : array( ContentSnapshot::class, 'read' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_content_qa';
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		return Definition::read_only(
			$this->name(),
			'Check FlitAgent content quality',
			'checks the site\'s content and returns findings, warnings first: media-library images and images in content without alt text, links to this site\'s own content that is missing, trashed or unpublished, empty and duplicate titles, items with neither an excerpt nor an SEO description (Yoast SEO or Rank Math), content under 300 words, published content not updated for 18 months, drafts untouched for 6 months, and categories or tags with no posts. Each finding has an id, severity, title and detail; the detail says in plain words what a person should do in wp-admin. FlitAgent only reads: it changes nothing on the site. Checks the most recently modified published items (limit, default 200) of post_types (default post and page); lists at most 200 findings (truncated says when more were found). Reads this site\'s database only and never fetches links.',
			array(
				'post_types' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Post types to check (default ["post", "page"]).',
				),
				'limit'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'description' => 'Most recently modified published items to check (default 200).',
				),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $arguments `post_types`, `limit`.
	 * @throws ToolError On invalid arguments.
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array( 'post_types', 'limit' ), $this->name() );
		$types = Arguments::strings( $arguments, 'post_types' );
		$types = null === $types ? array( 'post', 'page' ) : $types;
		if ( count( $types ) > 10 ) {
			throw new ToolError( 'post_types accepts at most 10 post types.' );
		}
		foreach ( $types as $type ) {
			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,20}$/', $type ) ) {
				throw new ToolError( 'post_types must be post type names such as "post" or "page".' );
			}
		}
		$limit = Arguments::int( $arguments, 'limit', 200, 1, 500 );

		return Content::run( call_user_func( $this->snapshot, $types, $limit ) );
	}
}
