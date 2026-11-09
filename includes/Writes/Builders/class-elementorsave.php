<?php
/**
 * Shared Elementor layout handling.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

use FlitAgent\Mcp\ToolError;
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
	 * Constructor.
	 *
	 * @param Engine|null $engine Pipeline; Elementor's own by default.
	 */
	public function __construct( ?Engine $engine = null ) {
		parent::__construct( $engine ?? new ElementorEngine() );
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
			'description' => 'The full element tree replacing the current one, in the shape get_elementor_page returns: elements with id, elType (container, section, column, widget or an atomic type), widgetType for widgets, settings and nested elements.',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta_keys(): array {
		return array( '_elementor_data', '_elementor_edit_mode', '_elementor_template_type', '_elementor_version' );
	}

	/**
	 * Validates the element tree.
	 *
	 * @param mixed $raw Elements argument.
	 * @return array<int, mixed>
	 * @throws ToolError When an element is malformed.
	 */
	protected function normalize( $raw ) {
		if ( ! is_array( $raw ) || ( array() !== $raw && array_keys( $raw ) !== range( 0, count( $raw ) - 1 ) ) ) {
			throw new ToolError( 'elements must be a list of Elementor elements.' );
		}
		self::check_elements( $raw, 'elements' );
		return $raw;
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
	 * Checks a list of elements recursively.
	 *
	 * @param array<mixed> $elements Elements.
	 * @param string       $path     Path for messages.
	 * @throws ToolError When an element is malformed.
	 */
	private static function check_elements( array $elements, string $path ): void {
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
				self::check_elements( $element['elements'], $at . '.elements' );
			}
		}//end foreach
	}
}
