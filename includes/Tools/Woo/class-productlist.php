<?php
/**
 * Tool: list_products.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Woo;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Products, newest first (#124).
 */
final class ProductList implements Tool {

	/**
	 * Product types.
	 */
	const TYPES = array( 'simple', 'variable', 'grouped', 'external' );

	/**
	 * Product statuses.
	 */
	const STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Stock statuses.
	 */
	const STOCK_STATUSES = array( 'instock', 'outofstock', 'onbackorder' );

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
		return 'list_products';
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
			'List FlitAgent WooCommerce products',
			'lists WooCommerce products, newest first: id, name, SKU, type, status, price, regular and sale price, stock status, whether stock is managed, and stock quantity. Filter by type, status, stock_status and a search term; paginate with page and per_page (default 20, at most 100). total is the number of matching products. Read-only.',
			array(
				'type'         => array(
					'type' => 'string',
					'enum' => self::TYPES,
				),
				'status'       => array(
					'type' => 'string',
					'enum' => self::STATUSES,
				),
				'stock_status' => array(
					'type' => 'string',
					'enum' => self::STOCK_STATUSES,
				),
				'search'       => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'page'         => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'per_page'     => array(
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
		Arguments::only( $arguments, array( 'type', 'status', 'stock_status', 'search', 'page', 'per_page' ), $this->name() );
		$search = Arguments::string( $arguments, 'search', false );
		if ( null !== $search && strlen( $search ) > 100 ) {
			throw new ToolError( 'search must be at most 100 characters.' );
		}
		$query = array(
			'type'         => Arguments::enum( $arguments, 'type', self::TYPES ),
			'status'       => Arguments::enum( $arguments, 'status', self::STATUSES ),
			'stock_status' => Arguments::enum( $arguments, 'stock_status', self::STOCK_STATUSES ),
			'search'       => $search,
			'page'         => Arguments::int( $arguments, 'page', 1, 1, 10000 ),
			'per_page'     => Arguments::int( $arguments, 'per_page', 20, 1, 100 ),
		);
		$data  = $this->source->products( $query );

		return array(
			'total'    => $data['total'],
			'page'     => $query['page'],
			'per_page' => $query['per_page'],
			'has_more' => $query['page'] * $query['per_page'] < $data['total'],
			'products' => $data['products'],
		);
	}
}
