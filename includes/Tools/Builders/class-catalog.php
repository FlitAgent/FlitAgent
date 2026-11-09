<?php
/**
 * Which page-builder tools this site gets.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Mcp\Tool;

defined( 'ABSPATH' ) || exit;

/**
 * Detects active page builders by their own code and returns their tools. Tools for inactive builders are not
 * registered at all, so they never appear in `tools/list`.
 */
final class Catalog {

	/**
	 * Builder => what proves it is loaded: [ 'class' | 'function', name ].
	 */
	const MARKERS = array(
		'elementor'  => array( 'class', 'Elementor\\Plugin' ),
		'bricks'     => array( 'class', 'Bricks\\Database' ),
		'breakdance' => array( 'function', 'Breakdance\\Data\\get_meta' ),
		'beaver'     => array( 'class', 'FLBuilderModel' ),
	);

	/**
	 * Active builders, in MARKERS order.
	 *
	 * @param callable|null $is_active Decides per builder; defaults to is_loaded(). For tests.
	 * @return string[]
	 */
	public static function active( ?callable $is_active = null ): array {
		$is_active = $is_active ?? array( self::class, 'is_loaded' );
		$active    = array();
		foreach ( array_keys( self::MARKERS ) as $builder ) {
			if ( $is_active( $builder ) ) {
				$active[] = $builder;
			}
		}
		return $active;
	}

	/**
	 * Whether a builder's marker class or function is loaded.
	 *
	 * @param string $builder Builder key.
	 * @return bool
	 */
	public static function is_loaded( string $builder ): bool {
		list( $kind, $name ) = self::MARKERS[ $builder ];
		return 'class' === $kind ? class_exists( $name ) : function_exists( $name );
	}

	/**
	 * Tools for the active builders.
	 *
	 * @param callable|null $is_active Decides per builder; defaults to is_loaded(). For tests.
	 * @return Tool[]
	 */
	public static function tools( ?callable $is_active = null ): array {
		$tools = array();
		foreach ( self::active( $is_active ) as $builder ) {
			$tools = array_merge( $tools, self::for_builder( $builder ) );
		}
		return $tools;
	}

	/**
	 * One builder's tools.
	 *
	 * @param string $builder Builder key.
	 * @return Tool[]
	 */
	private static function for_builder( string $builder ): array {
		switch ( $builder ) {
			case 'elementor':
				return array( new ElementorWidgets(), new ElementorWidgetSchema(), new ElementorStyleSchema(), new ElementorPage() );
			case 'bricks':
				return array( new BricksElements(), new BricksPage() );
			case 'breakdance':
				return array( new BreakdanceElements(), new BreakdancePage() );
			default:
				return array( new BeaverModules(), new BeaverModuleSchema(), new BeaverPage() );
		}
	}
}
