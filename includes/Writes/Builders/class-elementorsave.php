<?php
/**
 * Shared Elementor layout handling.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Tools\Builders\LiveSources;
use FlitAgent\Tools\Builders\Output;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor element trees: `_elementor_data` JSON, saved through `Document::save()`.
 */
abstract class ElementorSave extends SaveLayout {

	/**
	 * Widgets that output raw HTML or script.
	 */
	const CODE_WIDGETS = array( 'html' );

	/**
	 * Returns the registered widget and element type names, or null when Elementor's registry is not available.
	 *
	 * @var callable
	 */
	private $types;

	/**
	 * Constructor.
	 *
	 * @param Engine|null   $engine Pipeline; Elementor's own by default.
	 * @param callable|null $types  Returns types like LiveSources::elementor_types(); the live registry by default.
	 */
	public function __construct( ?Engine $engine = null, ?callable $types = null ) {
		parent::__construct( $engine ?? new ElementorEngine() );
		$this->types = $types ?? array( LiveSources::class, 'elementor_types' );
	}

	/**
	 * Whether a widget may sit at the top level of the tree. Pages need containers or sections there.
	 */
	protected function top_level_widgets_allowed(): bool {
		return false;
	}

	/**
	 * The tree as Elementor's document save stores it (Element_Base::get_raw_data()): every element has settings and
	 * elements, containers, sections and columns have isInner (false by default), widgets have none, and isLocked is
	 * kept only when set. JSON's {} and [] already decode alike.
	 *
	 * @param mixed $layout Layout.
	 * @return mixed
	 */
	protected function comparable( $layout ) {
		if ( ! is_array( $layout ) ) {
			return $layout;
		}
		foreach ( $layout as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$element += array(
				'settings' => array(),
				'elements' => array(),
			);
			if ( isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
				unset( $element['isInner'] );
			} else {
				$element += array( 'isInner' => false );
			}
			if ( empty( $element['isLocked'] ) ) {
				unset( $element['isLocked'] );
			}
			$element['elements'] = $this->comparable( $element['elements'] );
			$layout[ $index ]    = $element;
		}
		return $layout;
	}

	/**
	 * Elementor edits only the post types that support it; otherwise its own access rules refused.
	 *
	 * @param object $post Post.
	 * @return Assessment
	 */
	protected function access_refusal( $post ): Assessment {
		if ( ! post_type_supports( (string) $post->post_type, 'elementor' ) ) {
			return Assessment::prohibited( 'builder_access', sprintf( 'Elementor does not support the post type "%s" on this site, so it cannot edit the layout of post %d.', (string) $post->post_type, (int) $post->ID ) );
		}
		return parent::access_refusal( $post );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function builder(): string {
		return 'Elementor';
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
			'items'       => array( 'type' => 'object' ),
			'description' => 'The full element tree replacing the current one, in the shape get_elementor_page returns: elements with id, elType (container, section, column, widget or an atomic type), widgetType for widgets, settings and nested elements. Widget and element types must be registered on this site (list_elementor_widgets); anything else is refused, because Elementor would drop it. An empty list clears the layout.',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta_keys(): array {
		return array( '_elementor_data', '_elementor_edit_mode', '_elementor_template_type', '_elementor_version' );
	}

	/**
	 * Validates the element tree. Refuses widgets at the top level of a page (a page's top level is containers or
	 * sections) and, where Elementor's registry is loaded, widget and element types it has not registered, which
	 * Elementor would silently drop.
	 *
	 * @param mixed $raw Elements argument.
	 * @return array<int, mixed>
	 * @throws ToolError When an element is malformed or would be dropped.
	 */
	protected function normalize( $raw ) {
		if ( ! is_array( $raw ) || ( array() !== $raw && array_keys( $raw ) !== range( 0, count( $raw ) - 1 ) ) ) {
			throw new ToolError( 'elements must be a list of Elementor elements.' );
		}
		$types  = call_user_func( $this->types );
		$nested = is_array( $types ) && isset( $types['nested'] ) && is_array( $types['nested'] ) ? $types['nested'] : null;
		self::check_elements( $raw, 'elements', $nested );
		if ( ! $this->top_level_widgets_allowed() ) {
			foreach ( $raw as $index => $element ) {
				if ( 'widget' === $element['elType'] ) {
					throw new ToolError( sprintf( 'elements[%d] is a widget at the top level of the page. A page\'s top level must be containers or sections, so it is refused: put the widget inside a container or section.', (int) $index ) );
				}
			}
		}
		if ( is_array( $types ) ) {
			self::check_registered( $raw, 'elements', $types );
		}
		return $raw;
	}

	/**
	 * A save that Elementor stored with no elements, though elements were sent and the post had some before.
	 *
	 * @param mixed $sent    Normalized layout.
	 * @param mixed $before  Layout before the save.
	 * @param mixed $stored  Layout read back after the save.
	 * @param int   $post_id Post ID.
	 * @return string|null
	 */
	protected function emptied( $sent, $before, $stored, int $post_id ): ?string {
		if ( array() === $sent || ! is_array( $before ) || array() === $before || ( is_array( $stored ) && array() !== $stored ) ) {
			return null;
		}
		return sprintf(
			'Elementor dropped every element of the new layout, which would have left post %d with no elements. The previous layout was restored and nothing was changed. Check that every widget and element type is registered (list_elementor_widgets)%s.',
			$post_id,
			$this->top_level_widgets_allowed() ? '' : ' and that the top-level elements are containers or sections'
		);
	}

	/**
	 * The stored layout, or null.
	 *
	 * @param array<string, mixed> $values Meta values.
	 * @return mixed
	 */
	protected function layout_from( array $values ) {
		return Output::json( $values['_elementor_data'] ?? null );
	}

	/**
	 * Code widgets at any depth.
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
			if ( isset( $element['widgetType'] ) && in_array( $element['widgetType'], self::CODE_WIDGETS, true ) ) {
				$found[] = sprintf( '%s widget %s', $element['widgetType'], (string) ( $element['id'] ?? '?' ) );
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = array_merge( $found, $this->code_elements( $element['elements'] ) );
			}
		}
		return $found;
	}

	/**
	 * Checks every element's type against Elementor's registry, at any depth: `Elements_Manager::create_element_instance()`
	 * returns nothing for a type it does not have, and `Document::save()` then leaves the element out.
	 *
	 * @param array<mixed>                             $elements Elements (already shape-checked).
	 * @param string                                   $path     Path for messages.
	 * @param array{widgets?: mixed, elements?: mixed} $types    Registered type names.
	 * @throws ToolError When a type is not registered.
	 */
	private static function check_registered( array $elements, string $path, array $types ): void {
		$widgets = isset( $types['widgets'] ) && is_array( $types['widgets'] ) ? $types['widgets'] : array();
		$kinds   = isset( $types['elements'] ) && is_array( $types['elements'] ) ? $types['elements'] : array();
		foreach ( $elements as $index => $element ) {
			$at = sprintf( '%s[%s]', $path, $index );
			if ( 'widget' === $element['elType'] ) {
				if ( ! in_array( $element['widgetType'], $widgets, true ) ) {
					throw new ToolError( sprintf( '%s is a widget of type "%s", which is not registered on this site, so Elementor would drop it. Use a widget type from list_elementor_widgets.', esc_textarea( $at ), esc_textarea( $element['widgetType'] ) ) );
				}
			} elseif ( ! in_array( $element['elType'], $kinds, true ) ) {
				throw new ToolError( sprintf( '%s has elType "%s", which Elementor does not have on this site, so Elementor would drop it. Use one of: %s, or widget with a widgetType.', esc_textarea( $at ), esc_textarea( $element['elType'] ), esc_textarea( implode( ', ', $kinds ) ) ) );
			}
			if ( isset( $element['elements'] ) ) {
				self::check_registered( $element['elements'], $at . '.elements', $types );
			}
		}
	}

	/**
	 * Whether a widget type is one of Elementor's nested widgets (`Widget_Nested_Base`), which hold containers. Without
	 * the registry, by name: Elementor's are nested-tabs and nested-accordion; Elementor Pro's are nested-carousel,
	 * mega-menu and off-canvas (Widget_Nested_Base_Pro).
	 *
	 * @param string        $type   Widget type.
	 * @param string[]|null $nested Nested widget types from the registry, or null.
	 * @return bool
	 */
	private static function is_nested( string $type, ?array $nested ): bool {
		return null === $nested ? 0 === strpos( $type, 'nested-' ) || in_array( $type, array( 'mega-menu', 'off-canvas' ), true ) : in_array( $type, $nested, true );
	}

	/**
	 * Checks a list of elements recursively. A widget's elements must be empty, except a nested widget's (Tabs,
	 * Accordion; Elementor Pro's Carousel, Mega Menu and Off-Canvas), whose children must be containers.
	 *
	 * @param array<mixed>  $elements Elements.
	 * @param string        $path     Path for messages.
	 * @param string[]|null $nested   Nested widget types from Elementor's registry, or null when it is not loaded.
	 * @throws ToolError When an element is malformed.
	 */
	private static function check_elements( array $elements, string $path, ?array $nested ): void {
		foreach ( $elements as $index => $element ) {
			$at = sprintf( '%s[%s]', $path, $index );
			if ( ! is_array( $element ) || array() === $element || array_keys( $element ) === range( 0, count( $element ) - 1 ) ) {
				throw new ToolError( sprintf( '%s must be an object.', esc_textarea( $at ) ) );
			}
			foreach ( array( 'id', 'elType' ) as $key ) {
				if ( ! isset( $element[ $key ] ) || ! is_string( $element[ $key ] ) || '' === $element[ $key ] ) {
					throw new ToolError( sprintf( '%s needs a string %s.', esc_textarea( $at ), esc_textarea( $key ) ) );
				}
			}
			if ( 'widget' === $element['elType'] && ( ! isset( $element['widgetType'] ) || ! is_string( $element['widgetType'] ) || '' === $element['widgetType'] ) ) {
				throw new ToolError( sprintf( '%s is a widget and needs a string widgetType.', esc_textarea( $at ) ) );
			}
			if ( isset( $element['settings'] ) && ! is_array( $element['settings'] ) ) {
				throw new ToolError( sprintf( '%s.settings must be an object.', esc_textarea( $at ) ) );
			}
			if ( isset( $element['elements'] ) ) {
				if ( ! is_array( $element['elements'] ) ) {
					throw new ToolError( sprintf( '%s.elements must be a list.', esc_textarea( $at ) ) );
				}
				if ( 'widget' === $element['elType'] && array() !== $element['elements'] && ! self::is_nested( $element['widgetType'], $nested ) ) {
					// Elementor fatals on save when a plain widget has children (Elements_Manager: get_default_args() on array).
					throw new ToolError( sprintf( '%s is a widget, and a widget cannot contain other elements. Remove its elements, or move them out of the widget into a container, section or column.', esc_textarea( $at ) ) );
				}
				self::check_elements( $element['elements'], $at . '.elements', $nested );
				if ( 'widget' === $element['elType'] ) {
					foreach ( $element['elements'] as $child_index => $child ) {
						if ( 'container' !== $child['elType'] ) {
							throw new ToolError( sprintf( '%s.elements[%s] is %s inside the nested widget %s, whose children must be containers. Put it inside one of its containers.', esc_textarea( $at ), esc_textarea( (string) $child_index ), 'widget' === $child['elType'] ? 'a widget' : 'an element of type ' . esc_textarea( $child['elType'] ), esc_textarea( $element['widgetType'] ) ) );
						}
					}
				}
			}
		}//end foreach
	}
}
