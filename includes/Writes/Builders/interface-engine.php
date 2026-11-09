<?php
/**
 * A page builder's own save pipeline.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * The only boundary between the save tools and a builder's runtime. Live implementations (`*Engine` classes in this
 * namespace) call the builder's own APIs; unit tests pass stand-ins.
 */
interface Engine {

	/**
	 * Read-only: whether the builder's own access rule lets the current user edit this post's layout.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function can_edit( int $post_id ): bool;

	/**
	 * Read-only, only for users without `unfiltered_html`: why the builder's own HTML filter would reject the layout,
	 * or null when it accepts it.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $layout  Normalized layout.
	 * @return string|null
	 */
	public function rejects( int $post_id, $layout ): ?string;

	/**
	 * Saves the layout through the builder's pipeline, including its own CSS generation. Throws on failure
	 * (Mcp\ToolError with a message safe for the caller, anything else is reported generically).
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $layout  Normalized layout.
	 * @return array<string, string> Warnings, code => message (e.g. a failed CSS regeneration).
	 */
	public function save( int $post_id, $layout ): array;

	/**
	 * Regenerates the builder's CSS and caches after the layout meta was restored directly.
	 *
	 * @param int $post_id Post ID.
	 */
	public function refresh( int $post_id ): void;
}
