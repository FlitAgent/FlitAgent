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
 * Checked against Elementor 4.3.3, Beaver Builder Lite 2.11.0.6 and WPVibe 1.20.1's use of Bricks and Breakdance.
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
	 * Beaver Builder's loaded modules (`FLBuilderModel::$modules`, filled on `init`).
	 *
	 * @return array<string, object>
	 */
	public static function beaver(): array {
		return (array) \FLBuilderModel::$modules;
	}

	/**
	 * A Beaver Builder module's default settings (`FLBuilderModel::get_module_defaults()`).
	 *
	 * @param string $slug Module slug.
	 * @return mixed
	 */
	public static function beaver_defaults( string $slug ) {
		return \FLBuilderModel::get_module_defaults( $slug );
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
