<?php
/**
 * Serializes Elementor controls and atomic prop schemas.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Turns Elementor's registered controls (classic widgets) and prop types (atomic v4 elements and styles) into plain
 * lists. Ported from WPVibe's `/elementor/schema` and `/elementor/style-schema`.
 */
final class ElementorSchema {

	/**
	 * Control types that only shape the editor panel and hold no value.
	 */
	const UI_ONLY_TYPES = array( 'section', 'tab', 'tabs', 'heading', 'notice', 'raw_html', 'deprecated_notice', 'alert', 'divider' );

	/**
	 * Whether an element is atomic (v4): it declares a static props schema.
	 *
	 * @param object $element Widget or element.
	 * @return bool
	 */
	public static function is_atomic( $element ): bool {
		return method_exists( $element, 'get_props_schema' );
	}

	/**
	 * Whether a widget comes from Elementor Pro.
	 *
	 * @param object $element Widget or element.
	 * @return bool
	 */
	public static function is_pro( $element ): bool {
		return 0 === strpos( get_class( $element ), 'ElementorPro\\' );
	}

	/**
	 * A classic widget's value controls.
	 *
	 * @param array<mixed>                                         $controls `get_controls()`.
	 * @param array{mode: string, names: string[], prefix: string} $filter   Filter from Args::filter().
	 * @return array<int, array<string, mixed>>
	 */
	public static function controls( array $controls, array $filter ): array {
		$out = array();
		foreach ( $controls as $control ) {
			if ( ! is_array( $control ) || empty( $control['name'] ) || empty( $control['type'] ) || ! is_string( $control['name'] ) ) {
				continue;
			}
			if ( in_array( $control['type'], self::UI_ONLY_TYPES, true ) || ! Args::matches( $control['name'], $filter ) ) {
				continue;
			}

			$entry = array(
				'name' => $control['name'],
				'type' => (string) $control['type'],
			);

			if ( 'discovery' !== $filter['mode'] ) {
				if ( isset( $control['default'] ) && '' !== $control['default'] && array() !== $control['default'] ) {
					$entry['default'] = self::safe_default( $control['name'], $control['default'] );
				}
				if ( ! empty( $control['responsive'] ) ) {
					$entry['responsive'] = true;
				}
				if ( ! empty( $control['condition'] ) ) {
					$entry['condition'] = $control['condition'];
				}
				if ( ! empty( $control['conditions'] ) ) {
					$entry['conditions'] = $control['conditions'];
				}
				if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
					$entry['options'] = self::keys( $control['options'] );
				}
				if ( ! empty( $control['groupPrefix'] ) ) {
					$entry['group_prefix'] = $control['groupPrefix'];
				} elseif ( ! empty( $control['group_prefix'] ) ) {
					$entry['group_prefix'] = $control['group_prefix'];
				}
			}//end if

			$out[] = $entry;
		}//end foreach
		return $out;
	}

	/**
	 * Atomic props (element settings or style props).
	 *
	 * @param array<string, object>                                $schema Prop types keyed by name.
	 * @param array{mode: string, names: string[], prefix: string} $filter Filter from Args::filter().
	 * @return array<int, array<string, mixed>>
	 */
	public static function props( array $schema, array $filter ): array {
		$out = array();
		foreach ( $schema as $name => $prop ) {
			$name = (string) $name;
			if ( ! is_object( $prop ) || ! Args::matches( $name, $filter ) ) {
				continue;
			}

			$entry = array(
				'name' => $name,
				'type' => self::key( $prop ),
			);

			// Unions wrap the real prop types (e.g. string|dynamic): list the members, describe the first.
			if ( method_exists( $prop, 'get_prop_types' ) ) {
				$members        = (array) $prop->get_prop_types();
				$entry['types'] = array_map( 'strval', array_keys( $members ) );
				$primary        = reset( $members );
				if ( is_object( $primary ) ) {
					$prop = $primary;
				}
			}

			if ( 'discovery' !== $filter['mode'] ) {
				$entry = array_merge( $entry, self::prop_details( $name, $prop ) );
			}

			$out[] = $entry;
		}//end foreach
		return $out;
	}

	/**
	 * Details of one prop type from its JSON form.
	 *
	 * @param string $name Prop name.
	 * @param object $prop Prop type.
	 * @return array<string, mixed>
	 */
	private static function prop_details( string $name, $prop ): array {
		$entry      = array();
		$serialized = $prop instanceof \JsonSerializable ? (array) $prop->jsonSerialize() : array();

		if ( isset( $serialized['kind'] ) ) {
			$entry['kind'] = $serialized['kind'];
		}
		if ( isset( $serialized['default'] ) ) {
			$entry['default'] = self::safe_default( $name, $serialized['default'] );
		}
		$settings = isset( $serialized['settings'] ) ? (array) $serialized['settings'] : array();
		if ( ! empty( $settings['enum'] ) ) {
			$entry['options'] = array_values( (array) $settings['enum'] );
		}
		if ( ! empty( $settings['required'] ) ) {
			$entry['required'] = true;
		}
		$meta = isset( $serialized['meta'] ) ? (array) $serialized['meta'] : array();
		if ( ! empty( $meta['description'] ) ) {
			$entry['description'] = $meta['description'];
		}
		if ( method_exists( $prop, 'get_shape' ) ) {
			$shape = array();
			foreach ( (array) $prop->get_shape() as $key => $sub ) {
				$shape[ $key ] = self::key( $sub );
			}
			$entry['shape'] = $shape;
		}
		return $entry;
	}

	/**
	 * A prop type's key, e.g. "string".
	 *
	 * @param mixed $prop Prop type.
	 * @return string|null
	 */
	private static function key( $prop ): ?string {
		return is_object( $prop ) && method_exists( $prop, 'get_key' ) ? (string) $prop::get_key() : null;
	}

	/**
	 * A default value, masked when the control's own name looks secret (e.g. an API key field).
	 *
	 * @param string $name  Control or prop name.
	 * @param mixed  $value Default.
	 * @return mixed
	 */
	private static function safe_default( string $name, $value ) {
		if ( Redactor::is_secret_key( $name ) && ( is_array( $value ) || is_object( $value ) || ( is_string( $value ) && '' !== $value ) ) ) {
			return Redactor::MASK;
		}
		return $value;
	}

	/**
	 * Option keys, without empty ones.
	 *
	 * @param array<mixed> $options Options keyed by value.
	 * @return string[]
	 */
	private static function keys( array $options ): array {
		$keys = array();
		foreach ( array_keys( $options ) as $key ) {
			if ( '' !== (string) $key ) {
				$keys[] = (string) $key;
			}
		}
		return $keys;
	}
}
