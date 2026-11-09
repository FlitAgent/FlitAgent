<?php
/**
 * Bricks' save composition.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Bricks has no PHP save API; this replicates the composition WPVibe 1.20.1 verified against Bricks
 * (`includes/class-wpvibe-bricks.php` `write_layout()`): load element controls, run Bricks' element security check on
 * slashed input, write the content meta, verify it landed, set editor mode `bricks`, then update the post so Bricks'
 * own save_post hook generates the CSS. The order is load-bearing; the CSS is generated once per request.
 */
final class BricksEngine implements Engine {

	/**
	 * Meta key of the content area: `BRICKS_DB_PAGE_CONTENT` when defined.
	 */
	public static function content_key(): string {
		return self::key( 'BRICKS_DB_PAGE_CONTENT', '_bricks_page_content_2' );
	}

	/**
	 * Meta key of the editor mode: `BRICKS_DB_EDITOR_MODE` when defined.
	 */
	public static function mode_key(): string {
		return self::key( 'BRICKS_DB_EDITOR_MODE', '_bricks_editor_mode' );
	}

	/**
	 * Bricks' builder access (`Bricks\Capabilities::current_user_can_use_builder()`); fails closed without it, because
	 * Bricks' meta filter would otherwise silently drop the write.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function can_edit( int $post_id ): bool {
		$check = array( '\Bricks\Capabilities', 'current_user_can_use_builder' );
		return is_callable( $check ) && (bool) call_user_func( $check, $post_id );
	}

	/**
	 * Bricks' security check runs in save() and refuses there.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $layout  Elements.
	 * @return string|null
	 */
	public function rejects( int $post_id, $layout ): ?string {
		return null;
	}

	/**
	 * Saves the elements.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $layout  Elements.
	 * @return array<string, string>
	 * @throws ToolError When Bricks' security check or meta filter refuses the elements.
	 */
	public function save( int $post_id, $layout ): array {
		self::load_elements();

		$slashed = wp_slash( $layout );
		$check   = array( '\Bricks\Helpers', 'security_check_elements_before_save' );
		if ( is_callable( $check ) ) {
			$checked = call_user_func( $check, $slashed, $post_id, 'content' );
			if ( ! is_array( $checked ) || count( $checked ) !== count( $slashed ) ) {
				throw new ToolError( 'Bricks\' element security check rejected this save for the current user (code, SVG or query-editor settings, or {echo:...} tags).' );
			}
			$slashed = $checked;
		}

		update_post_meta( $post_id, self::content_key(), $slashed );
		$stored = get_post_meta( $post_id, self::content_key(), true );
		if ( ! is_array( $stored ) || array() === $stored ) {
			throw new ToolError( 'Bricks blocked the layout write (no data was stored); the current user may lack builder access for this post type.' );
		}

		update_post_meta( $post_id, self::mode_key(), 'bricks' );
		wp_update_post( array( 'ID' => $post_id ) );
		return array();
	}

	/**
	 * Regenerates the CSS through Bricks' save_post hook.
	 *
	 * @param int $post_id Post ID.
	 */
	public function refresh( int $post_id ): void {
		self::load_elements();
		wp_update_post( array( 'ID' => $post_id ) );
	}

	/**
	 * Loads element controls (registered on `wp`, which does not run in REST), so CSS generation sees every style.
	 */
	private static function load_elements(): void {
		$load = array( '\Bricks\Elements', 'load_elements' );
		if ( is_callable( $load ) ) {
			call_user_func( $load );
		}
	}

	/**
	 * A Bricks meta key: its constant when defined, else the documented default.
	 *
	 * @param string $constant Constant name.
	 * @param string $fallback Default key.
	 * @return string
	 */
	private static function key( string $constant, string $fallback ): string {
		return defined( $constant ) && is_string( constant( $constant ) ) ? constant( $constant ) : $fallback;
	}
}
