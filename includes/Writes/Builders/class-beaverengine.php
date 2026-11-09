<?php
/**
 * Beaver Builder's save pipeline.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Saves through `FLBuilderModel` as WPVibe 1.20.1 does (`includes/class-wpvibe-beaver.php` `write_layout()`), but
 * with `save_layout( false )` so the post status never changes: `update_layout_data()` cleans and slashes the draft
 * layout, `save_layout()` copies it to the published layout and settings, clears the asset cache, enables the
 * builder, rewrites post_content and renders the CSS and JS. Checked against Beaver Builder Lite 2.11.0.6
 * (`classes/class-fl-builder-model.php`, `classes/class-fl-builder-user-access.php`).
 */
final class BeaverEngine implements Engine {

	/**
	 * Beaver's own rule: the post type is enabled in Beaver (`FLBuilderModel::get_post_types()`) and the user has
	 * `builder_access` (`FLBuilderUserAccess`). The post-level `edit_post` check is the caller's.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function can_edit( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, (array) \FLBuilderModel::get_post_types(), true ) ) {
			return false;
		}
		return class_exists( '\FLBuilderUserAccess' ) && \FLBuilderUserAccess::current_user_can( 'builder_access' );
	}

	/**
	 * Beaver's own kses check for users without unfiltered_html (`FLBuilderModel::verify_settings()`, what its editor
	 * runs before saving node settings). `update_layout_data()` does not run it, so it is enforced here.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $layout  Nodes.
	 * @return string|null
	 */
	public function rejects( int $post_id, $layout ): ?string {
		foreach ( (array) $layout as $id => $node ) {
			if ( isset( $node->settings ) && true !== \FLBuilderModel::verify_settings( clone $node->settings ) ) {
				return sprintf( 'Node %s contains HTML that Beaver Builder only lets users with the unfiltered_html capability save.', (string) $id );
			}
		}
		return null;
	}

	/**
	 * Saves the layout as a draft, then publishes the layout (not the post).
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $layout  Nodes.
	 * @return array<string, string>
	 */
	public function save( int $post_id, $layout ): array {
		\FLBuilderModel::update_layout_data( $layout, 'draft', $post_id );
		\FLBuilderModel::set_post_id( $post_id );
		try {
			\FLBuilderModel::save_layout( false );
		} finally {
			\FLBuilderModel::reset_post_id();
		}
		return array();
	}

	/**
	 * Deletes the post's cached CSS and JS; Beaver renders them again on the next view.
	 *
	 * @param int $post_id Post ID.
	 */
	public function refresh( int $post_id ): void {
		\FLBuilderModel::delete_all_asset_cache( $post_id );
	}
}
