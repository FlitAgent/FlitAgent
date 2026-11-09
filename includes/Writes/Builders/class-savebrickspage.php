<?php
/**
 * Write tool: save_bricks_page.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces a post's Bricks content area: the flat element list in `_bricks_page_content_2` (or
 * `BRICKS_DB_PAGE_CONTENT`), with the editor mode.
 */
final class SaveBricksPage extends SaveLayout {

	/**
	 * Elements that run PHP or output raw markup.
	 */
	const CODE_ELEMENTS = array( 'code', 'svg' );

	/**
	 * Setting keys of Bricks' query editor (PHP).
	 */
	const CODE_SETTINGS = array( 'queryEditor', 'useQueryEditor' );

	/**
	 * Constructor.
	 *
	 * @param Engine|null $engine Pipeline; Bricks' own by default.
	 */
	public function __construct( ?Engine $engine = null ) {
		parent::__construct( $engine ?? new BricksEngine() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'save_bricks_page';
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
	protected function title(): string {
		return 'FlitAgent: save a Bricks page';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function describe(): string {
		return 'Replaces the Bricks elements of one post\'s content area the way Bricks saves them: its element security check, the content meta, editor mode "bricks", then a post update so Bricks regenerates the page CSS. Elements must be registered Bricks element names.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function property(): string {
		return 'elements';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function property_schema(): array {
		return array(
			'type'        => 'array',
			'minItems'    => 1,
			'items'       => array( 'type' => 'object' ),
			'description' => 'The full flat element list replacing the current one, in the shape get_bricks_page returns: each element with a unique string id, name, parent (0 at the root), children (ids) and settings.',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta_keys(): array {
		return array( BricksEngine::content_key(), BricksEngine::mode_key() );
	}

	/**
	 * Validates the element list: unique ids, names, known parents and children. Fills `parent` (0) and `settings`
	 * (empty) the way Bricks writes them.
	 *
	 * @param mixed $raw Elements argument.
	 * @return array<int, array<string, mixed>>
	 * @throws ToolError When an element is malformed.
	 */
	protected function normalize( $raw ) {
		if ( ! is_array( $raw ) || array() === $raw || array_keys( $raw ) !== range( 0, count( $raw ) - 1 ) ) {
			throw new ToolError( 'elements must be a non-empty list of Bricks elements.' );
		}
		$ids = array();
		foreach ( $raw as $index => $element ) {
			if ( ! is_array( $element ) || ! isset( $element['id'] ) || ! is_string( $element['id'] ) || '' === $element['id'] ) {
				throw new ToolError( sprintf( 'elements[%d] must be an object with a string id.', (int) $index ) );
			}
			if ( isset( $ids[ $element['id'] ] ) ) {
				throw new ToolError( sprintf( 'Duplicate element id "%s".', esc_textarea( $element['id'] ) ) );
			}
			if ( ! isset( $element['name'] ) || ! is_string( $element['name'] ) || '' === $element['name'] ) {
				throw new ToolError( sprintf( 'Element "%s" needs a name (the Bricks element slug).', esc_textarea( $element['id'] ) ) );
			}
			$ids[ $element['id'] ] = true;
		}
		foreach ( $raw as $index => $element ) {
			$parent = $element['parent'] ?? 0;
			if ( 0 !== $parent && '0' !== $parent && '' !== $parent && ! ( is_string( $parent ) && isset( $ids[ $parent ] ) ) ) {
				throw new ToolError( sprintf( 'Element "%s" has parent "%s", which is not in elements.', esc_textarea( $element['id'] ), is_scalar( $parent ) ? esc_textarea( (string) $parent ) : '?' ) );
			}
			$children = $element['children'] ?? array();
			if ( ! is_array( $children ) ) {
				throw new ToolError( sprintf( 'Element "%s" has a children value that is not a list.', esc_textarea( $element['id'] ) ) );
			}
			foreach ( $children as $child ) {
				if ( ! is_string( $child ) || ! isset( $ids[ $child ] ) ) {
					throw new ToolError( sprintf( 'Element "%s" lists a child that is not in elements.', esc_textarea( $element['id'] ) ) );
				}
			}
			$raw[ $index ]['parent']   = $parent;
			$raw[ $index ]['settings'] = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		}
		return $raw;
	}

	/**
	 * The stored layout, or null.
	 *
	 * @param array<string, mixed> $values Meta values.
	 * @return mixed
	 */
	protected function layout_from( array $values ) {
		$elements = $values[ BricksEngine::content_key() ] ?? null;
		return is_array( $elements ) && array() !== $elements ? array_values( $elements ) : null;
	}

	/**
	 * Code and SVG elements, query-editor settings and `{echo:...}` dynamic tags.
	 *
	 * @param mixed $layout Elements.
	 * @return string[]
	 */
	protected function code_elements( $layout ): array {
		$found = array();
		foreach ( (array) $layout as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$id = (string) ( $element['id'] ?? '?' );
			if ( isset( $element['name'] ) && in_array( $element['name'], self::CODE_ELEMENTS, true ) ) {
				$found[] = sprintf( '%s element %s', $element['name'], $id );
			}
			$settings = $element['settings'] ?? array();
			if ( self::has_key( $settings, self::CODE_SETTINGS ) ) {
				$found[] = sprintf( 'query editor in %s', $id );
			}
			if ( self::has_echo( $settings ) ) {
				$found[] = sprintf( '{echo:} tag in %s', $id );
			}
		}
		return $found;
	}

	/**
	 * Whether any of the keys appears at any depth.
	 *
	 * @param mixed    $value Value.
	 * @param string[] $keys  Keys.
	 * @return bool
	 */
	private static function has_key( $value, array $keys ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $key => $item ) {
			if ( in_array( (string) $key, $keys, true ) || self::has_key( $item, $keys ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a string at any depth holds an `{echo:...}` tag (Bricks runs the named PHP function).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function has_echo( $value ): bool {
		if ( is_string( $value ) ) {
			return false !== stripos( $value, '{echo:' );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::has_echo( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
