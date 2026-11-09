<?php
/**
 * Where the WooCommerce tools read from.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only access to store data, as plain arrays. WpSource reads WooCommerce; tests use a fake.
 */
interface Source {

	/**
	 * Store settings and order counts since a time.
	 *
	 * @param int $since Unix time; orders created from then on are counted.
	 * @return array{currency: string, country: string, version: string, hpos: bool, counts: array<string, int>, revenue: float, revenue_partial: bool}
	 */
	public function overview( int $since ): array;

	/**
	 * A page of orders, newest first, with the customer's real name and email (the tool masks them).
	 *
	 * @param array{status: ?string, after: ?string, before: ?string, page: int, per_page: int} $query Filters.
	 * @return array{total: int, orders: array<int, array<string, mixed>>}
	 */
	public function orders( array $query ): array;

	/**
	 * A page of products, newest first.
	 *
	 * @param array{type: ?string, status: ?string, stock_status: ?string, search: ?string, page: int, per_page: int} $query Filters.
	 * @return array{total: int, products: array<int, array<string, mixed>>}
	 */
	public function products( array $query ): array;

	/**
	 * The facts health triage needs.
	 *
	 * @param int $now Unix time.
	 * @return array{db_update_pending: bool, failed_7d: int, on_hold_7d: int, out_of_stock_total: int, out_of_stock_names: string[], outdated_templates: array<int, array{file: string, version: string, core_version: string}>}
	 */
	public function triage( int $now ): array;
}
