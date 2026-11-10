<?php
/**
 * Reads the active builders' own registries.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that touches builder runtime APIs. Each tool takes one of these as its default source; unit tests pass
 * stand-ins. Every call here only reads an in-memory registry the builder has already loaded for this request.
 *
 * Checked against Elementor 4.3.3 and WPVibe 1.20.1's use of Bricks and Breakdance.
 */
final class LiveSources {

	/**
	 * Elementor's widget and element registries (`widgets_manager->get_widget_types()`, `elements_manager->get_element_types()`).
	 *
	 * @return array{version: string|null, widgets: array<string, object>, elements: array<string, object>}
	 */
	public static function elementor(): array {
		$plugin = \Elementor\Plugin::$instance;

		return array(
			'version'  => self::elementor_version(),
			'widgets'  => (array) $plugin->widgets_manager->get_widget_types(),
			'elements' => (array) $plugin->elements_manager->get_element_types(),
		);
	}

	/**
	 * Names of the widget and element types Elementor has registered (`widgets_manager->get_widget_types()`,
	 * `elements_manager->get_element_types()`), the ones `Elements_Manager::create_element_instance()` accepts, or null
	 * when Elementor and its managers are not loaded.
	 *
	 * `nested` lists the widgets that are Elementor's nested widgets (`Widget_Nested_Base`: Elementor's Tabs and
	 * Accordion; Elementor Pro's Carousel, Mega Menu and Off-Canvas), whose children are containers.
	 *
	 * @return array{widgets: string[], elements: string[], nested: string[]}|null
	 */
	public static function elementor_types(): ?array {
		if ( ! class_exists( '\Elementor\Plugin', false ) || ! isset( \Elementor\Plugin::$instance->widgets_manager, \Elementor\Plugin::$instance->elements_manager ) ) {
			return null;
		}
		$plugin  = \Elementor\Plugin::$instance;
		$widgets = (array) $plugin->widgets_manager->get_widget_types();
		$base    = '\Elementor\Modules\NestedElements\Base\Widget_Nested_Base';
		$nested  = array();
		foreach ( $widgets as $name => $widget ) {
			if ( class_exists( $base, false ) && $widget instanceof $base ) {
				$nested[] = (string) $name;
			}
		}

		return array(
			'widgets'  => array_map( 'strval', array_keys( $widgets ) ),
			'elements' => array_map( 'strval', array_keys( (array) $plugin->elements_manager->get_element_types() ) ),
			'nested'   => $nested,
		);
	}

	/**
	 * Elementor's atomic style schema (`Style_Schema::get()`), or null before Elementor 4.
	 *
	 * @return array{version: string|null, schema: array<string, object>|null}
	 */
	public static function elementor_style(): array {
		$class = '\Elementor\Modules\AtomicWidgets\Styles\Style_Schema';

		return array(
			'version' => self::elementor_version(),
			'schema'  => class_exists( $class ) ? (array) $class::get() : null,
		);
	}

	/**
	 * Bricks' element registry (`Bricks\Elements::$elements`): name => array with `class` and `label`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function bricks(): array {
		return class_exists( '\Bricks\Elements' ) ? (array) \Bricks\Elements::$elements : array();
	}

	/**
	 * Loaded Breakdance element classes: subclasses of `Breakdance\Elements\Element`.
	 *
	 * @return string[]
	 */
	public static function breakdance(): array {
		$classes = array();
		foreach ( get_declared_classes() as $class ) {
			if ( is_subclass_of( $class, '\Breakdance\Elements\Element' ) ) {
				$classes[] = $class;
			}
		}
		return $classes;
	}

	/**
	 * Whether Breakdance runs in free mode, or null when it does not say.
	 *
	 * @return bool|null
	 */
	public static function breakdance_free_mode(): ?bool {
		$function = '\Breakdance\Subscription\isFreeMode';
		return function_exists( $function ) ? (bool) $function() : null;
	}

	/**
	 * The running Elementor version.
	 *
	 * @return string|null
	 */
	private static function elementor_version(): ?string {
		return defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : null;
	}
}
