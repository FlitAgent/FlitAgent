<?php
/**
 * Store data from WooCommerce.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Reads store data through WooCommerce's own query functions, so it works with both order storage modes (posts and
 * HPOS). Changes nothing and makes no HTTP requests. Call only when WooCommerce is active.
 */
final class WpSource implements Source {

	/**
	 * Most orders summed for revenue; beyond this the revenue is marked partial.
	 */
	const REVENUE_MAX_ORDERS = 1000;

	/**
	 * Out-of-stock product names listed in triage.
	 */
	const OUT_OF_STOCK_NAMES = 5;

	/**
	 * Bytes read from a template to find its @version line, as WooCommerce does.
	 */
	const VERSION_BYTES = 8192;

	/**
	 * Store settings and order counts since a time.
	 *
	 * @param int $since Unix time.
	 * @return array<string, mixed>
	 */
	public function overview( int $since ): array {
		$counts = array();
		foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
			$count = $this->count( $status, '>=' . $since );
			if ( $count > 0 ) {
				$counts[ substr( $status, 3 ) ] = $count;
			}
		}

		$paid    = wc_get_orders(
			array(
				'status'       => array( 'wc-processing', 'wc-completed' ),
				'date_created' => '>=' . $since,
				'limit'        => self::REVENUE_MAX_ORDERS,
				'paginate'     => true,
			)
		);
		$revenue = 0.0;
		foreach ( $paid->orders as $order ) {
			$revenue += (float) $order->get_total();
		}

		return array(
			'currency'        => get_woocommerce_currency(),
			'country'         => WC()->countries->get_base_country(),
			'version'         => (string) WC()->version,
			'hpos'            => self::hpos(),
			'counts'          => $counts,
			'revenue'         => round( $revenue, 2 ),
			'revenue_partial' => $paid->total > self::REVENUE_MAX_ORDERS,
		);
	}

	/**
	 * A page of orders.
	 *
	 * @param array<string, mixed> $query Filters (see Source::orders()).
	 * @return array<string, mixed>
	 */
	public function orders( array $query ): array {
		$args = array(
			'limit'    => $query['per_page'],
			'page'     => $query['page'],
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);
		if ( null !== $query['status'] ) {
			$args['status'] = 'wc-' . $query['status'];
		}
		$range = self::date_range( $query['after'], $query['before'] );
		if ( null !== $range ) {
			$args['date_created'] = $range;
		}
		$result = wc_get_orders( $args );

		$orders = array();
		foreach ( $result->orders as $order ) {
			$created  = $order->get_date_created();
			$orders[] = array(
				'id'             => $order->get_id(),
				'number'         => (string) $order->get_order_number(),
				'status'         => $order->get_status(),
				'date_created'   => $created ? $created->date( DATE_ATOM ) : '',
				'total'          => (string) $order->get_total(),
				'currency'       => $order->get_currency(),
				'items'          => $order->get_item_count(),
				'payment_method' => $order->get_payment_method_title(),
				'customer_id'    => $order->get_customer_id(),
				'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'customer_email' => $order->get_billing_email(),
			);
		}

		return array(
			'total'  => (int) $result->total,
			'orders' => $orders,
		);
	}

	/**
	 * A page of products.
	 *
	 * @param array<string, mixed> $query Filters (see Source::products()).
	 * @return array<string, mixed>
	 */
	public function products( array $query ): array {
		$args = array(
			'status'   => null === $query['status'] ? array( 'publish', 'draft', 'pending', 'private' ) : $query['status'],
			'limit'    => $query['per_page'],
			'page'     => $query['page'],
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);
		if ( null !== $query['type'] ) {
			$args['type'] = $query['type'];
		}
		if ( null !== $query['stock_status'] ) {
			$args['stock_status'] = $query['stock_status'];
		}
		if ( null !== $query['search'] ) {
			$args['s'] = $query['search'];
		}
		$result = wc_get_products( $args );

		$products = array();
		foreach ( $result->products as $product ) {
			$products[] = array(
				'id'             => $product->get_id(),
				'name'           => $product->get_name(),
				'sku'            => $product->get_sku(),
				'type'           => $product->get_type(),
				'status'         => $product->get_status(),
				'price'          => (string) $product->get_price(),
				'regular_price'  => (string) $product->get_regular_price(),
				'sale_price'     => (string) $product->get_sale_price(),
				'stock_status'   => $product->get_stock_status(),
				'manage_stock'   => $product->get_manage_stock(),
				'stock_quantity' => $product->get_stock_quantity(),
			);
		}

		return array(
			'total'    => (int) $result->total,
			'products' => $products,
		);
	}

	/**
	 * The facts health triage needs.
	 *
	 * @param int $now Unix time.
	 * @return array<string, mixed>
	 */
	public function triage( int $now ): array {
		$week = '>=' . ( $now - 7 * 86400 );

		$out_of_stock = wc_get_products(
			array(
				'status'       => 'publish',
				'stock_status' => 'outofstock',
				'limit'        => self::OUT_OF_STOCK_NAMES,
				'paginate'     => true,
			)
		);
		$names        = array();
		foreach ( $out_of_stock->products as $product ) {
			$names[] = $product->get_name();
		}

		return array(
			'db_update_pending'  => class_exists( '\WC_Install' ) && \WC_Install::needs_db_update(),
			'failed_7d'          => $this->count( 'wc-failed', $week ),
			'on_hold_7d'         => $this->count( 'wc-on-hold', $week ),
			'out_of_stock_total' => (int) $out_of_stock->total,
			'out_of_stock_names' => $names,
			'outdated_templates' => self::outdated_templates(),
		);
	}

	/**
	 * Orders with a status created in a date range.
	 *
	 * @param string $status Status with the "wc-" prefix.
	 * @param string $range  A date_created query such as ">=1700000000".
	 * @return int
	 */
	private function count( string $status, string $range ): int {
		$result = wc_get_orders(
			array(
				'status'       => $status,
				'date_created' => $range,
				'limit'        => 1,
				'return'       => 'ids',
				'paginate'     => true,
			)
		);
		return (int) $result->total;
	}

	/**
	 * A date_created query from optional YYYY-MM-DD bounds (both inclusive).
	 *
	 * @param string|null $after  First day.
	 * @param string|null $before Last day.
	 * @return string|null
	 */
	private static function date_range( ?string $after, ?string $before ): ?string {
		if ( null !== $after && null !== $before ) {
			return $after . '...' . $before;
		}
		if ( null !== $after ) {
			return '>=' . $after;
		}
		if ( null !== $before ) {
			return '<=' . $before;
		}
		return null;
	}

	/**
	 * Whether orders are stored in WooCommerce's own tables (HPOS).
	 *
	 * @return bool
	 */
	private static function hpos(): bool {
		$util = '\Automattic\WooCommerce\Utilities\OrderUtil';
		return class_exists( $util ) && $util::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Theme template overrides older than WooCommerce's own copy, found the way WooCommerce's status report finds them:
	 * a file under woocommerce/ in the child or parent theme whose @version is missing or lower.
	 *
	 * @return array<int, array{file: string, version: string, core_version: string}>
	 */
	private static function outdated_templates(): array {
		$core     = trailingslashit( WC()->plugin_path() ) . 'templates/';
		$outdated = array();
		$seen     = array();
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $theme ) {
			$folder = trailingslashit( $theme ) . 'woocommerce/';
			if ( ! is_dir( $folder ) ) {
				continue;
			}
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $folder, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				$relative = substr( $file->getPathname(), strlen( $folder ) );
				// The child theme's copy wins, so a parent's copy of the same template is not used.
				if ( ! $file->isFile() || isset( $seen[ $relative ] ) || ! is_file( $core . $relative ) ) {
					continue;
				}
				$seen[ $relative ] = true;
				$core_version      = self::file_version( $core . $relative );
				$version           = self::file_version( $file->getPathname() );
				if ( '' !== $core_version && ( '' === $version || version_compare( $version, $core_version, '<' ) ) ) {
					$outdated[] = array(
						'file'         => str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file->getPathname() ) ),
						'version'      => '' === $version ? 'no version' : $version,
						'core_version' => $core_version,
					);
				}
			}
		}//end foreach
		return $outdated;
	}

	/**
	 * The @version a template declares near its top, or an empty string.
	 *
	 * @param string $path Local file.
	 * @return string
	 */
	private static function file_version( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local theme or WooCommerce template.
		$head = (string) file_get_contents( $path, false, null, 0, self::VERSION_BYTES );
		return 1 === preg_match( '/^[ \t\/*#@]*@version\s+(\S+)/mi', $head, $match ) ? $match[1] : '';
	}
}
