<?php
/**
 * Where WordPress will put the widgets after a theme switch.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

use FlitAgent\Writes\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * After switch_theme(), core maps the widgets to the new theme's widget areas only on the next request
 * (check_theme_switched() → _wp_sidebars_changed() → retrieve_widgets()), once the theme registered its areas. This
 * predicts that mapping in the switch request when the new theme's areas are known without loading it: a block
 * theme gets the classic areas core stored as its `wp_classic_sidebars` theme mod, and a theme that was active
 * before has its areas in its kept `sidebars_widgets` theme mod. It runs core's retrieve_widgets( 'customize' ) as a
 * dry run with those areas standing in for the registered ones, and restores both globals. Otherwise it only says
 * where the widgets are now.
 */
final class WidgetForecast {

	const INACTIVE = 'wp_inactive_widgets';

	/**
	 * The area of each widget now (widget id => area id).
	 *
	 * @return array<string, string>
	 */
	public static function placement(): array {
		return self::places( self::stored() );
	}

	/**
	 * Adds a warning about the widgets WordPress will move on the next request. Call right after switch_theme().
	 *
	 * @param Outcome               $outcome   Outcome of the switch.
	 * @param array<string, string> $before    Widget areas to compare with (widget id => area id).
	 * @param string                $reference How to name those areas in the warning, e.g. ' before the switch'.
	 * @return Outcome
	 */
	public static function warn( Outcome $outcome, array $before, string $reference = '' ): Outcome {
		$areas = self::next_areas();
		if ( null === $areas ) {
			$held = self::held();
			if ( array() === $held ) {
				return $outcome;
			}
			return $outcome->with_warning(
				'widgets_unpredicted',
				sprintf(
					'%s registers its widget areas only when it loads, so WordPress maps the widgets to them on the next request and FlitAgent cannot predict where they go. Widgets now: %s. Check them in Appearance > Widgets.',
					wp_strip_all_tags( (string) wp_get_theme()->get( 'Name' ) ),
					implode( '; ', $held )
				)
			);
		}
		$after = self::places( self::mapped( $areas ) );
		$moves = array();
		foreach ( $before as $id => $area ) {
			if ( isset( $after[ $id ] ) && $after[ $id ] !== $area ) {
				$moves[] = sprintf( '%s: %s%s → %s', $id, $area, $reference, $after[ $id ] );
			}
		}
		if ( array() === $moves ) {
			return $outcome;
		}
		return $outcome->with_warning(
			'widgets_moved',
			sprintf( 'WordPress maps the widgets to the active theme\'s widget areas on the next request; predicted from the areas the theme had: %s. Check them in Appearance > Widgets.', implode( '; ', $moves ) )
		);
	}

	/**
	 * Widget area ids the active theme will register on the next request, or null when unknown.
	 *
	 * @return string[]|null
	 */
	private static function next_areas(): ?array {
		if ( wp_get_theme()->is_block_theme() ) {
			$classic = get_theme_mod( 'wp_classic_sidebars' );
			return is_array( $classic ) ? array_map( 'strval', array_keys( $classic ) ) : null;
		}
		$kept = get_theme_mod( 'sidebars_widgets' );
		if ( ! is_array( $kept ) || ! isset( $kept['data'] ) || ! is_array( $kept['data'] ) ) {
			return null;
		}
		$areas = array();
		foreach ( array_keys( $kept['data'] ) as $id ) {
			$id = (string) $id;
			if ( self::INACTIVE !== $id && 'array_version' !== $id && 0 !== strpos( $id, 'orphaned_widgets' ) ) {
				$areas[] = $id;
			}
		}
		return $areas;
	}

	/**
	 * Core's mapping of the stored widget areas to the given areas, as a dry run.
	 *
	 * @param string[] $areas Area ids to register.
	 * @return array<string, mixed>
	 */
	private static function mapped( array $areas ): array {
		$saved_widgets = isset( $GLOBALS['sidebars_widgets'] ) ? $GLOBALS['sidebars_widgets'] : null;
		$had_areas     = array_key_exists( 'wp_registered_sidebars', $GLOBALS );
		$saved_areas   = $had_areas ? $GLOBALS['wp_registered_sidebars'] : null;
		$registered    = array();
		foreach ( $areas as $id ) {
			$registered[ $id ] = array(
				'id'   => $id,
				'name' => $id,
			);
		}
		$stored = self::stored();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- retrieve_widgets() maps to these areas; restored below.
		$GLOBALS['wp_registered_sidebars'] = $registered;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- retrieve_widgets() maps this global; restored below.
		$GLOBALS['sidebars_widgets'] = $stored;
		try {
			$mapped = retrieve_widgets( 'customize' );
		} finally {
			if ( $had_areas ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the global the dry run changed.
				$GLOBALS['wp_registered_sidebars'] = $saved_areas;
			} else {
				unset( $GLOBALS['wp_registered_sidebars'] );
			}
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the global the dry run changed.
			$GLOBALS['sidebars_widgets'] = $saved_widgets;
		}
		return is_array( $mapped ) ? $mapped : array();
	}

	/**
	 * The stored widget areas, read fresh from the `sidebars_widgets` option as Widgets::stored() reads them (core's
	 * wp_get_sidebars_widgets is private and caches per request); the global $sidebars_widgets is left alone.
	 *
	 * @return array<string, mixed>
	 */
	private static function stored(): array {
		$areas = get_option( 'sidebars_widgets', array() );
		$areas = is_array( $areas ) ? $areas : array();
		unset( $areas['array_version'] );
		return $areas;
	}

	/**
	 * Widget id => area id of stored widget areas.
	 *
	 * @param mixed $areas Areas.
	 * @return array<string, string>
	 */
	private static function places( $areas ): array {
		$places = array();
		foreach ( (array) $areas as $area => $widgets ) {
			if ( ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $widget ) {
				$places[ (string) $widget ] = (string) $area;
			}
		}
		return $places;
	}

	/**
	 * "area: ids" for each widget area that holds widgets now.
	 *
	 * @return string[]
	 */
	private static function held(): array {
		$held = array();
		foreach ( self::stored() as $area => $widgets ) {
			if ( self::INACTIVE !== $area && is_array( $widgets ) && array() !== $widgets ) {
				$held[] = sprintf( '%s: %s', $area, implode( ', ', array_map( 'strval', $widgets ) ) );
			}
		}
		return $held;
	}
}
