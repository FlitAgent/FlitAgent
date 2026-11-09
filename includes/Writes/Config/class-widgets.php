<?php
/**
 * Shared reads and checks for the widget write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;

defined( 'ABSPATH' ) || exit;

/**
 * Reads widgets and widget areas directly, not through REST: core's widgets and sidebars controllers run
 * retrieve_widgets() even on GET, which rewrites `sidebars_widgets` (and moves widgets of areas the active theme does
 * not register). Writes go through those controllers only while no such remap is pending, so their housekeeping
 * changes nothing else.
 */
final class Widgets {

	/**
	 * Inactive widgets "area".
	 */
	const INACTIVE = 'wp_inactive_widgets';

	/**
	 * A widget: id, id_base, and `instance.raw` when its type shows its settings in REST. Null when missing.
	 *
	 * @param string $id Widget id, e.g. block-2.
	 * @return array<string, mixed>|null
	 */
	public static function widget( string $id ): ?array {
		$parsed = wp_parse_widget_id( $id );
		if ( empty( $parsed['number'] ) ) {
			return null;
		}
		$instances = get_option( 'widget_' . $parsed['id_base'] );
		$factory   = isset( $GLOBALS['wp_widget_factory'] ) ? $GLOBALS['wp_widget_factory'] : null;
		$object    = $factory ? $factory->get_widget_object( $parsed['id_base'] ) : null;
		if ( ! is_array( $instances ) || ! isset( $instances[ $parsed['number'] ] ) || ! $object ) {
			return null;
		}
		$widget = array(
			'id'       => $id,
			'id_base'  => (string) $parsed['id_base'],
			'instance' => array(),
		);
		if ( ! empty( $object->widget_options['show_instance_in_rest'] ) ) {
			$widget['instance']['raw'] = (array) $instances[ $parsed['number'] ];
		}
		return $widget;
	}

	/**
	 * The stored widget areas (the `sidebars_widgets` option without its `array_version` key), read fresh. Core's
	 * wp_get_sidebars_widgets is private, caches the areas per request outside wp-admin (so a read after a write in the
	 * same MCP request would be stale) and applies a display filter; these tools change what is stored, so they read it.
	 *
	 * @return array<string, mixed>
	 */
	public static function stored(): array {
		$areas = get_option( 'sidebars_widgets', array() );
		$areas = is_array( $areas ) ? $areas : array();
		unset( $areas['array_version'] );
		return $areas;
	}

	/**
	 * Widget ids of the registered widget areas and of the inactive widgets.
	 *
	 * @return array<string, string[]>
	 */
	public static function sidebars(): array {
		$stored   = self::stored();
		$sidebars = array();
		foreach ( array_keys( self::registered() ) as $id ) {
			$sidebars[ $id ] = isset( $stored[ $id ] ) ? array_values( array_map( 'strval', (array) $stored[ $id ] ) ) : array();
		}
		$sidebars[ self::INACTIVE ] = isset( $stored[ self::INACTIVE ] ) ? array_values( array_map( 'strval', (array) $stored[ self::INACTIVE ] ) ) : array();
		return $sidebars;
	}

	/**
	 * Why core would remap widgets on the next widget change, or null: an area the active theme does not register
	 * still holds widgets, or core's mapping (which also puts back the widgets a theme had when it was last active)
	 * would change a registered area.
	 *
	 * @return string|null
	 */
	public static function pending_remap(): ?string {
		$registered = self::registered();
		foreach ( self::stored() as $id => $widgets ) {
			if ( 'array_version' === $id || self::INACTIVE === $id || isset( $registered[ $id ] ) || empty( $widgets ) ) {
				continue;
			}
			return sprintf( 'Widget area %s holds widgets but is not registered by the active theme; WordPress would move them on the next widget change. Review them in Appearance > Widgets first.', $id );
		}
		$remap = self::remap( self::stored() );
		if ( null !== $remap ) {
			return sprintf( 'Widgets wait to be mapped to the active theme\'s widget areas: WordPress would change widget area %s on the next widget change (after a theme switch, and to put back widgets the theme had when it was last active). Open Appearance > Widgets once so it finishes, then try again.', $remap['area'] );
		}
		return null;
	}

	/**
	 * Refusal for a write that WordPress would change again on the next widget change, or null: core's mapping, run
	 * on the widget areas as they would be after the write, would change a registered area (for example put a widget
	 * the theme had when it was last active back into an area the write empties).
	 *
	 * @param array<string, mixed> $after  Stored widget areas after the write (as Widgets::stored() returns them).
	 * @param string               $advice What to do instead.
	 * @param string[]             $gone   Widgets the write deletes.
	 * @return Assessment|null
	 */
	public static function undone_after( array $after, string $advice, array $gone = array() ): ?Assessment {
		$remap = self::remap( $after, $gone );
		if ( null === $remap ) {
			return null;
		}
		return Assessment::prohibited(
			'widgets_remapped',
			sprintf(
				'WordPress would undo this on the next widget change: it would put back %s in widget area %s, as the theme had it when it was last active. %s',
				array() === $remap['added'] ? 'widgets' : implode( ', ', $remap['added'] ),
				$remap['area'],
				$advice
			)
		);
	}

	/**
	 * Stored widget areas after core's sidebars controller sets an area's list: the listed widgets leave other areas,
	 * and widgets left out of the area go to the inactive widgets.
	 *
	 * @param string   $sidebar    Area id.
	 * @param string[] $widget_ids Its new widget ids.
	 * @return array<string, mixed>
	 */
	public static function after_put( string $sidebar, array $widget_ids ): array {
		$areas = self::stored();
		foreach ( $areas as $id => $widgets ) {
			if ( ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $i => $widget ) {
				if ( $id !== $sidebar && in_array( $widget, $widget_ids, true ) ) {
					unset( $areas[ $id ][ $i ] );
				}
				if ( $id === $sidebar && ! in_array( $widget, $widget_ids, true ) ) {
					$areas[ self::INACTIVE ][] = $widget;
				}
			}
		}
		$areas[ $sidebar ] = array_values( $widget_ids );
		return $areas;
	}

	/**
	 * Stored widget areas without a deleted widget.
	 *
	 * @param string $id Widget id.
	 * @return array<string, mixed>
	 */
	public static function after_delete( string $id ): array {
		$areas = self::stored();
		foreach ( $areas as $area => $widgets ) {
			if ( is_array( $widgets ) ) {
				$areas[ $area ] = array_values( array_diff( array_map( 'strval', $widgets ), array( $id ) ) );
			}
		}
		return $areas;
	}

	/**
	 * The first registered area whose widgets core's mapping of the given areas would change, and the widgets it
	 * would add there; or null. Core keeps the `sidebars_widgets` theme mod of a theme that was active before for
	 * good, so its presence alone says nothing: this runs retrieve_widgets() as a dry run ('customize' skips the
	 * database write) and restores the global it sets. Widgets core drops only because their type is no longer
	 * registered, and widgets being deleted, do not count.
	 *
	 * @param array<string, mixed> $areas Stored widget areas to map.
	 * @param string[]             $gone  Widgets that will no longer exist.
	 * @return array{area: string, added: string[]}|null
	 */
	private static function remap( array $areas, array $gone = array() ): ?array {
		$saved = isset( $GLOBALS['sidebars_widgets'] ) ? $GLOBALS['sidebars_widgets'] : null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- retrieve_widgets() maps this global; restored below.
		$GLOBALS['sidebars_widgets'] = $areas;
		try {
			$mapped = retrieve_widgets( 'customize' );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the global the dry run changed.
			$GLOBALS['sidebars_widgets'] = $saved;
		}

		$known = isset( $GLOBALS['wp_registered_widgets'] ) && is_array( $GLOBALS['wp_registered_widgets'] ) ? $GLOBALS['wp_registered_widgets'] : null;
		foreach ( array_keys( self::registered() ) as $id ) {
			$before = self::listed( isset( $areas[ $id ] ) ? $areas[ $id ] : array(), $known, $gone );
			$after  = self::listed( is_array( $mapped ) && isset( $mapped[ $id ] ) ? $mapped[ $id ] : array(), $known, $gone );
			if ( $before !== $after ) {
				return array(
					'area'  => (string) $id,
					'added' => array_values( array_diff( $after, $before ) ),
				);
			}
		}
		return null;
	}

	/**
	 * Widget ids of an area as a list, without widgets of unregistered types (when the registry is known) and without
	 * the given widgets.
	 *
	 * @param mixed                     $widgets Area widgets.
	 * @param array<string, mixed>|null $known   Registered widgets by id, or null.
	 * @param string[]                  $gone    Widgets to leave out.
	 * @return string[]
	 */
	private static function listed( $widgets, ?array $known, array $gone = array() ): array {
		$ids = array_map( 'strval', array_values( (array) $widgets ) );
		return array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $known, $gone ) {
					return ( null === $known || isset( $known[ $id ] ) ) && ! in_array( $id, $gone, true );
				}
			)
		);
	}

	/**
	 * The raw instance, or null when the widget's type does not show it in REST.
	 *
	 * @param array<string, mixed> $widget Widget.
	 * @return array<mixed>|null
	 */
	public static function raw( array $widget ): ?array {
		return isset( $widget['instance'] ) && is_array( $widget['instance'] ) && array_key_exists( 'raw', $widget['instance'] ) ? (array) $widget['instance']['raw'] : null;
	}

	/**
	 * The widget, or a refusal: no capability, a pending remap, missing (same message as not allowed), or (when the
	 * settings are used) no raw instance.
	 *
	 * @param string $id            Widget id.
	 * @param bool   $settings_used Whether the tool reads or writes the widget's settings.
	 * @return array<string, mixed>|Assessment
	 */
	public static function editable( string $id, bool $settings_used = true ) {
		$refusal = self::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$widget = self::widget( $id );
		if ( null === $widget ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Widget %s was not found or you cannot edit it.', $id ) );
		}
		if ( $settings_used && null === self::raw( $widget ) ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'The settings of widget %s cannot be read and restored through the REST API (its widget type does not show them), so FlitAgent does not change it.', $id ) );
		}
		return $widget;
	}

	/**
	 * Refusal for any widget write: no capability or a pending remap. Null when allowed.
	 *
	 * @return Assessment|null
	 */
	public static function refusal(): ?Assessment {
		$refusal = Menus::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$remap = self::pending_remap();
		return null === $remap ? null : Assessment::prohibited( 'widgets_unmapped', $remap );
	}

	/**
	 * Fingerprint of a widget's settings and of every widget area's list.
	 *
	 * @param string $id Widget id.
	 * @return string
	 */
	public static function fingerprint( string $id ): string {
		$widget = self::widget( $id );
		return Canonical::hash( array( $id, null === $widget ? null : self::raw( $widget ), self::sidebars() ) );
	}

	/**
	 * Where a widget is: area and 1-based position.
	 *
	 * @param array<string, string[]> $sidebars Areas.
	 * @param string                  $id       Widget id.
	 * @return array{sidebar: string, position: int}
	 */
	public static function place( array $sidebars, string $id ): array {
		foreach ( $sidebars as $sidebar => $widgets ) {
			$index = array_search( $id, $widgets, true );
			if ( false !== $index ) {
				return array(
					'sidebar'  => $sidebar,
					'position' => $index + 1,
				);
			}
		}
		return array(
			'sidebar'  => self::INACTIVE,
			'position' => 0,
		);
	}

	/**
	 * An instance as JSON for previews.
	 *
	 * @param array<mixed>|null $instance Instance.
	 * @return string
	 */
	public static function display( ?array $instance ): string {
		return null === $instance ? '(none)' : Canonical::json( $instance );
	}

	/**
	 * Registered widget areas.
	 *
	 * @return array<string, mixed>
	 */
	private static function registered(): array {
		return isset( $GLOBALS['wp_registered_sidebars'] ) && is_array( $GLOBALS['wp_registered_sidebars'] ) ? $GLOBALS['wp_registered_sidebars'] : array();
	}
}
