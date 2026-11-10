<?php
/**
 * Tool: list_orders.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Woo;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;
use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Orders, newest first, with customer names and emails masked (#124).
 */
final class OrderList implements Tool {

	/**
	 * Order statuses, without WooCommerce's "wc-" prefix.
	 */
	const STATUSES = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft' );

	/**
	 * Store data.
	 *
	 * @var Source
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param Source $source Store data.
	 */
	public function __construct( Source $source ) {
		$this->source = $source;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_orders';
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
			'List FlitAgent WooCommerce orders',
			'lists WooCommerce orders, newest first: id, number, status, date created, total, currency, line_items (the number of order lines) and item_quantity (the total quantity across them), payment method and the customer\'s user id. Customer names and emails are always redacted. Filter by status and by creation date (after, before as YYYY-MM-DD); paginate with page and per_page (default 20, at most 100). total is the number of matching orders. Read-only.',
			array(
				'status'   => array(
					'type' => 'string',
					'enum' => self::STATUSES,
				),
				'after'    => array(
					'type'        => 'string',
					'description' => 'Orders created on or after this date (YYYY-MM-DD).',
				),
				'before'   => array(
					'type'        => 'string',
					'description' => 'Orders created on or before this date (YYYY-MM-DD).',
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $arguments Filters.
	 * @throws ToolError On invalid arguments.
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array( 'status', 'after', 'before', 'page', 'per_page' ), $this->name() );
		$query = array(
			'status'   => Arguments::enum( $arguments, 'status', self::STATUSES ),
			'after'    => self::date( $arguments, 'after' ),
			'before'   => self::date( $arguments, 'before' ),
			'page'     => Arguments::int( $arguments, 'page', 1, 1, 10000 ),
			'per_page' => Arguments::int( $arguments, 'per_page', 20, 1, 100 ),
		);
		$data  = $this->source->orders( $query );

		$orders = array();
		foreach ( $data['orders'] as $order ) {
			$order['customer'] = array(
				'user_id' => (int) $order['customer_id'],
				'name'    => '' === $order['customer_name'] ? '' : Redactor::MASK,
				'email'   => '' === $order['customer_email'] ? '' : Redactor::MASK,
			);
			unset( $order['customer_id'], $order['customer_name'], $order['customer_email'] );
			$orders[] = $order;
		}

		return array(
			'total'    => $data['total'],
			'page'     => $query['page'],
			'per_page' => $query['per_page'],
			'has_more' => $query['page'] * $query['per_page'] < $data['total'],
			'orders'   => $orders,
		);
	}

	/**
	 * A YYYY-MM-DD date argument.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param string               $name      Argument name.
	 * @return string|null
	 * @throws ToolError When not a real date in that format.
	 */
	private static function date( array $arguments, string $name ): ?string {
		$value = Arguments::string( $arguments, $name, false );
		if ( null === $value ) {
			return null;
		}
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			throw new ToolError( sprintf( '%s must be a date as YYYY-MM-DD.', esc_textarea( $name ) ) );
		}
		return $value;
	}
}
