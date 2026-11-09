<?php
/**
 * Tool: get_store_overview.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Woo;

use FlitAgent\Mcp\Tool;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Store settings and the last 30 days of orders (#124).
 */
final class StoreOverview implements Tool {

	/**
	 * Days the order counts cover.
	 */
	const DAYS = 30;

	/**
	 * Store data.
	 *
	 * @var Source
	 */
	private $source;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param Source        $source Store data.
	 * @param callable|null $now    Returns the current Unix time; time() by default.
	 */
	public function __construct( Source $source, ?callable $now = null ) {
		$this->source = $source;
		$this->now    = $now ? $now : 'time';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_store_overview';
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
			'Get FlitAgent WooCommerce store overview',
			'returns the WooCommerce store\'s currency, base country, WooCommerce version, whether orders use high-performance order storage (hpos), and for the last 30 days the number of orders in each status and the revenue (processing and completed orders). Read-only.'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $arguments None.
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array(), $this->name() );
		$data = $this->source->overview( (int) call_user_func( $this->now ) - self::DAYS * 86400 );

		$note = 'Sum of processing and completed orders created in the last 30 days.';
		if ( $data['revenue_partial'] ) {
			$note .= ' The store has more orders than FlitAgent sums, so this covers only the most recent ones.';
		}

		return array(
			'currency'            => $data['currency'],
			'base_country'        => $data['country'],
			'woocommerce_version' => $data['version'],
			'hpos'                => $data['hpos'],
			'last_30_days'        => array(
				'orders_by_status' => $data['counts'],
				'revenue'          => $data['revenue'],
				'revenue_note'     => $note,
			),
		);
	}
}
