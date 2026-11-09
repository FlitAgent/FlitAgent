<?php
/**
 * WooCommerce read tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Providers;

use FlitAgent\Tools\Provider;
use FlitAgent\Tools\Woo\OrderList;
use FlitAgent\Tools\Woo\ProductList;
use FlitAgent\Tools\Woo\StoreOverview;
use FlitAgent\Tools\Woo\WpSource;

defined( 'ABSPATH' ) || exit;

/**
 * The read-only WooCommerce tools (#124), registered only while WooCommerce is active. No WooCommerce writes in the MVP.
 */
final class WooReads implements Provider {

	/**
	 * Whether WooCommerce is active.
	 *
	 * @var bool
	 */
	private $active;

	/**
	 * Constructor.
	 *
	 * @param bool|null $active Whether WooCommerce is active; detected by default.
	 */
	public function __construct( ?bool $active = null ) {
		$this->active = null === $active ? class_exists( 'WooCommerce' ) : $active;
	}

	/**
	 * Read tools.
	 *
	 * @return \FlitAgent\Mcp\Tool[]
	 */
	public function reads(): array {
		if ( ! $this->active ) {
			return array();
		}
		$source = new WpSource();
		return array(
			new StoreOverview( $source ),
			new OrderList( $source ),
			new ProductList( $source ),
		);
	}

	/**
	 * No write tools.
	 *
	 * @return \FlitAgent\Writes\WriteTool[]
	 */
	public function writes(): array {
		return array();
	}
}
