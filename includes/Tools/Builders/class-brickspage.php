<?php
/**
 * Tool: get_bricks_page.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a post's Bricks elements from `_bricks_page_content_2` (or `BRICKS_DB_PAGE_CONTENT`).
 *
 * Read directly, as WPVibe does: `Bricks\Helpers::get_bricks_data()` may return an active template's elements instead
 * of the post's own. Bricks stores a PHP array; WordPress's own meta API decodes it, FlitAgent never unserializes.
 */
final class BricksPage extends PageTool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_bricks_page';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function builder(): string {
		return 'Bricks';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function describe(): string {
		return 'Returns the flat element list (id, name, parent, children, settings) of the post content area and the Bricks editor mode.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function layout_schema(): array {
		return array(
			'editor_mode' => array( 'type' => array( 'string', 'null' ) ),
			'elements'    => array( 'type' => 'array' ),
		);
	}

	/**
	 * Reads the layout fields, or null when the post has no layout for this builder.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $extra   Validated extra arguments.
	 * @return array<string, mixed>|null
	 */
	protected function layout( int $post_id, array $extra ): ?array {
		$elements = self::meta( $post_id, self::key( 'BRICKS_DB_PAGE_CONTENT', '_bricks_page_content_2' ) );
		if ( ! is_array( $elements ) || array() === $elements ) {
			return null;
		}

		return array(
			'editor_mode' => self::meta_string( $post_id, self::key( 'BRICKS_DB_EDITOR_MODE', '_bricks_editor_mode' ) ),
			'elements'    => array_values( $elements ),
		);
	}

	/**
	 * Bricks' meta key: its constant when defined, else the documented default.
	 *
	 * @param string $constant Constant name.
	 * @param string $fallback Default key.
	 * @return string
	 */
	private static function key( string $constant, string $fallback ): string {
		return defined( $constant ) && is_string( constant( $constant ) ) ? constant( $constant ) : $fallback;
	}
}
