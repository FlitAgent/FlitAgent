<?php
/**
 * Cache engine detector shared by get_cache_info and the cache write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\System;

defined( 'ABSPATH' ) || exit;

/**
 * Detects which purgeable cache engines are active, by the function, class or constant each plugin defines, and
 * whether the cache drop-ins exist. Reads only: it never loads, calls or purges an engine.
 */
final class CacheEngines {

	/**
	 * A page cache on this server (purged first).
	 */
	const PAGE = 'page';

	/**
	 * A CDN in front of the site (purged after the page caches).
	 */
	const CDN = 'cdn';

	/**
	 * Generated page-builder CSS.
	 */
	const BUILDER = 'builder';

	/**
	 * Engine id => array( label, kind, probe type, probe name ), in purge order: origin page caches, then the CDN.
	 */
	const ENGINES = array(
		'litespeed'      => array( 'LiteSpeed Cache', self::PAGE, 'constant', 'LSCWP_V' ),
		'wp_rocket'      => array( 'WP Rocket', self::PAGE, 'function', 'rocket_clean_domain' ),
		'siteground'     => array( 'SiteGround Speed Optimizer', self::PAGE, 'function', 'sg_cachepress_purge_cache' ),
		'w3_total_cache' => array( 'W3 Total Cache', self::PAGE, 'function', 'w3tc_flush_all' ),
		'wp_super_cache' => array( 'WP Super Cache', self::PAGE, 'function', 'wp_cache_clear_cache' ),
		'breeze'         => array( 'Breeze', self::PAGE, 'constant', 'BREEZE_VERSION' ),
		'cloudflare'     => array( 'Cloudflare', self::CDN, 'class', 'CF\\WordPress\\Hooks' ),
		'elementor'      => array( 'Elementor CSS', self::BUILDER, 'class', 'Elementor\\Plugin' ),
		'beaver_builder' => array( 'Beaver Builder CSS and JS', self::BUILDER, 'class', 'FLBuilderModel' ),
		'divi'           => array( 'Divi static CSS', self::BUILDER, 'class', 'ET_Core_PageResource' ),
	);

	/**
	 * Receives a probe type (`function`, `class`, `constant`) and a name.
	 *
	 * @var callable
	 */
	private $probe;

	/**
	 * Receives a drop-in file name.
	 *
	 * @var callable
	 */
	private $dropin_exists;

	/**
	 * Constructor.
	 *
	 * @param callable|null $probe         Symbol check, for tests.
	 * @param callable|null $dropin_exists Drop-in check, for tests.
	 */
	public function __construct( ?callable $probe = null, ?callable $dropin_exists = null ) {
		$this->probe         = $probe ? $probe : array( self::class, 'symbol_exists' );
		$this->dropin_exists = $dropin_exists ? $dropin_exists : static function ( string $file ): bool {
			return defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/' . $file );
		};
	}

	/**
	 * Ids of the active engines of the given kinds, in purge order.
	 *
	 * @param string ...$kinds Kinds (PAGE, CDN, BUILDER).
	 * @return string[]
	 */
	public function detected( string ...$kinds ): array {
		$found = array();
		foreach ( self::ENGINES as $id => $engine ) {
			if ( in_array( $engine[1], $kinds, true ) && $this->exists( $engine[2], $engine[3] ) ) {
				$found[] = $id;
			}
		}
		return $found;
	}

	/**
	 * Whether a function, class or constant exists.
	 *
	 * @param string $type `function`, `class` or `constant`.
	 * @param string $name Name.
	 * @return bool
	 */
	public function exists( string $type, string $name ): bool {
		return (bool) call_user_func( $this->probe, $type, $name );
	}

	/**
	 * Whether a drop-in file exists in wp-content.
	 *
	 * @param string $file File name, e.g. `advanced-cache.php`.
	 * @return bool
	 */
	public function dropin( string $file ): bool {
		return (bool) call_user_func( $this->dropin_exists, $file );
	}

	/**
	 * Human label of an engine.
	 *
	 * @param string $id Engine id.
	 * @return string
	 */
	public static function label( string $id ): string {
		return isset( self::ENGINES[ $id ] ) ? self::ENGINES[ $id ][0] : $id;
	}

	/**
	 * Kind of an engine.
	 *
	 * @param string $id Engine id.
	 * @return string
	 */
	public static function kind( string $id ): string {
		return isset( self::ENGINES[ $id ] ) ? self::ENGINES[ $id ][1] : '';
	}

	/**
	 * The default probe. Never autoloads classes, so detection cannot load plugin code.
	 *
	 * @param string $type `function`, `class` or `constant`.
	 * @param string $name Name.
	 * @return bool
	 */
	public static function symbol_exists( string $type, string $name ): bool {
		switch ( $type ) {
			case 'function':
				return function_exists( $name );
			case 'class':
				return class_exists( $name, false );
			case 'constant':
				return defined( $name );
			default:
				return false;
		}
	}
}
