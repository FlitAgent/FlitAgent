<?php
/**
 * The get_cache_info tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\System;

use FlitAgent\Mcp\Tool;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Reports object and page cache setup (WP-CLI `cache type`).
 */
final class CacheInfo implements Tool {

	/**
	 * Constant reader.
	 *
	 * @var Constants
	 */
	private $constants;

	/**
	 * Cache engine and drop-in detector (shared with the cache write tools).
	 *
	 * @var CacheEngines
	 */
	private $engines;

	/**
	 * Constructor.
	 *
	 * @param callable|null     $lookup        Constant reader, for tests.
	 * @param callable|null     $dropin_exists Receives a drop-in file name, for tests.
	 * @param CacheEngines|null $engines       Detector, for tests; built with $dropin_exists when omitted.
	 */
	public function __construct( ?callable $lookup = null, ?callable $dropin_exists = null, ?CacheEngines $engines = null ) {
		$this->constants = new Constants( $lookup );
		$this->engines   = $engines ? $engines : new CacheEngines( null, $dropin_exists );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_cache_info';
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		return Definition::read_only(
			$this->name(),
			'Get cache info with FlitAgent',
			'reports caching setup: whether a persistent object cache is in use, whether the object-cache.php and advanced-cache.php drop-ins exist, the WP_CACHE constant (page caching), and page_cache_engines: the active page cache and CDN plugins FlitAgent can purge (litespeed, wp_rocket, siteground, w3_total_cache, wp_super_cache, breeze, cloudflare).'
		);
	}

	/**
	 * Reports the cache setup.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array(), $this->name() );

		return array(
			'persistent_object_cache' => (bool) wp_using_ext_object_cache(),
			'object_cache_dropin'     => $this->engines->dropin( 'object-cache.php' ),
			'advanced_cache_dropin'   => $this->engines->dropin( 'advanced-cache.php' ),
			'wp_cache'                => $this->constants->is_true( 'WP_CACHE' ),
			'page_cache_engines'      => $this->engines->detected( CacheEngines::PAGE, CacheEngines::CDN ),
		);
	}
}
