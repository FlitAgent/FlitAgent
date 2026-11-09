<?php
/**
 * Elementor's save pipeline.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Saves through Elementor's Documents manager, as Elementor's editor does: `Document::save()` writes `_elementor_data`
 * (kses-filtered for users without unfiltered_html), the plain-text post_content, template type and version, and
 * clears the element cache and the post CSS; the CSS is then regenerated. Checked against Elementor 4.3.3
 * (`core/base/document.php`, `core/documents-manager.php`, `core/files/css/post.php`).
 */
final class ElementorEngine implements Engine {

	/**
	 * Elementor's own rule: `Document::is_editable_by_current_user()` (post type supported by Elementor, `edit_post`,
	 * role not excluded, not the posts page or the shop page).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function can_edit( int $post_id ): bool {
		$document = self::document( $post_id );
		return $document && $document->is_editable_by_current_user();
	}

	/**
	 * Elementor filters HTML itself inside Document::save(), so nothing is rejected up front.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $layout  Elements.
	 * @return string|null
	 */
	public function rejects( int $post_id, $layout ): ?string {
		return null;
	}

	/**
	 * Saves the elements and regenerates the post CSS.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $layout  Elements.
	 * @return array<string, string>
	 * @throws ToolError When Elementor refuses the save.
	 */
	public function save( int $post_id, $layout ): array {
		$document = self::document( $post_id );
		if ( ! $document ) {
			throw new ToolError( 'Elementor could not load the document.' );
		}
		if ( ! $document->is_built_with_elementor() ) {
			$document->set_is_built_with_elementor( true );
		}
		if ( false === $document->save( array( 'elements' => $layout ) ) ) {
			throw new ToolError( 'Elementor refused to save the document for the current user.' );
		}

		try {
			$this->refresh_css( $post_id );
		} catch ( \Throwable $e ) {
			return array( 'css_regen_failed' => 'The layout was saved, but Elementor could not regenerate its CSS; it is rebuilt on the next page view or editor save.' );
		}
		return array();
	}

	/**
	 * Clears Elementor's element cache and regenerates the post CSS. When the restored post is no longer built with
	 * Elementor, its CSS file and asset list are deleted instead, so no stale Elementor output is left behind.
	 *
	 * @param int $post_id Post ID.
	 */
	public function refresh( int $post_id ): void {
		delete_post_meta( $post_id, '_elementor_element_cache' );
		$document = self::document( $post_id );
		if ( $document && ! $document->is_built_with_elementor() ) {
			delete_post_meta( $post_id, '_elementor_page_assets' );
			\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
			return;
		}
		$this->refresh_css( $post_id );
	}

	/**
	 * Regenerates the post's CSS file.
	 *
	 * @param int $post_id Post ID.
	 */
	private function refresh_css( int $post_id ): void {
		\Elementor\Core\Files\CSS\Post::create( $post_id )->update();
	}

	/**
	 * The post's Elementor document, fresh (not from Elementor's cache).
	 *
	 * @param int $post_id Post ID.
	 * @return \Elementor\Core\Base\Document|false
	 */
	private static function document( int $post_id ) {
		return \Elementor\Plugin::$instance->documents->get( $post_id, false );
	}
}
