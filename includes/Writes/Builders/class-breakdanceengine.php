<?php
/**
 * Breakdance's save composition.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Breakdance has no public save API; this replicates the composition WPVibe 1.20.1 uses (`includes/class-wpvibe-breakdance.php`
 * `write_tree()`, mirroring `Breakdance\Data\save_document` without the metas it must not clobber): the tree through
 * Breakdance's own meta encoder, a post update, its CSS cache generation, then `breakdance_after_save_document`.
 */
final class BreakdanceEngine implements Engine {

	/**
	 * Meta key of the tree envelope: `_breakdance_data`, or with the Oxygen-mode prefix.
	 */
	public static function data_key(): string {
		$bdox = '\Breakdance\BreakdanceOxygen\Strings\__bdox';
		if ( function_exists( $bdox ) ) {
			$prefix = $bdox( '_meta_prefix' );
			if ( is_string( $prefix ) && '' !== $prefix ) {
				return $prefix . 'data';
			}
		}
		return '_breakdance_data';
	}

	/**
	 * Breakdance's own builder permission (`Breakdance\Permissions\hasMinimumPermission( 'edit' )`); fails closed
	 * without it, because Breakdance elements can run PHP during cache generation.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function can_edit( int $post_id ): bool {
		$check = '\Breakdance\Permissions\hasMinimumPermission';
		return function_exists( $check ) && (bool) $check( 'edit' );
	}

	/**
	 * Nothing beyond the builder permission.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $layout  Tree.
	 * @return string|null
	 */
	public function rejects( int $post_id, $layout ): ?string {
		return null;
	}

	/**
	 * Saves the tree and regenerates the CSS cache.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $layout  Tree.
	 * @return array<string, string>
	 * @throws ToolError When Breakdance's functions are missing.
	 */
	public function save( int $post_id, $layout ): array {
		if ( ! function_exists( '\Breakdance\Data\set_meta' ) ) {
			throw new ToolError( 'This Breakdance version has no Breakdance\Data\set_meta().' );
		}
		\Breakdance\Data\set_meta( $post_id, self::data_key(), array( 'tree_json_string' => wp_json_encode( $layout ) ) );
		wp_update_post( array( 'ID' => $post_id ) );
		return $this->generate( $post_id );
	}

	/**
	 * Regenerates the CSS cache.
	 *
	 * @param int $post_id Post ID.
	 */
	public function refresh( int $post_id ): void {
		$this->generate( $post_id );
	}

	/**
	 * Breakdance's cache generation and its after-save action.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string>
	 */
	private function generate( int $post_id ): array {
		$warnings = array();
		if ( function_exists( '\Breakdance\Render\generateCacheForPost' ) ) {
			if ( array() === \Breakdance\Render\generateCacheForPost( $post_id ) ) {
				$warnings['css_empty'] = 'Breakdance generated no CSS for this layout; it may have no renderable elements.';
			}
		} else {
			$warnings['css_regen_failed'] = 'This Breakdance version has no Breakdance\Render\generateCacheForPost(); open and save the page in Breakdance.';
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breakdance's own hook, fired as its save does.
		do_action( 'breakdance_after_save_document', $post_id );
		return $warnings;
	}
}
