<?php
/**
 * The list_options tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Settings;

use FlitAgent\Mcp\Tool;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Lists options with short, redacted values (WP-CLI `option list`).
 */
final class OptionList implements Tool {

	/**
	 * Autoload values WordPress treats as "not autoloaded" (6.6+ adds off and auto-off to legacy no).
	 */
	const NOT_AUTOLOADED = array( 'no', 'off', 'auto-off' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_options';
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
			'List options with FlitAgent',
			'lists WordPress options with name, autoload and a value preview (first 200 characters; arrays as redacted JSON). Transients are excluded unless include_transients is true. Values of secret-named options are redacted. Ordered by name.',
			array(
				'search'             => array(
					'type'        => 'string',
					'description' => 'Name pattern; * matches any text and ? one character, e.g. "woocommerce_*".',
				),
				'autoload'           => array(
					'type' => 'string',
					'enum' => array( 'on', 'off' ),
				),
				'include_transients' => array( 'type' => 'boolean' ),
				'limit'              => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 50,
				),
			)
		);
	}

	/**
	 * Lists the options.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		global $wpdb;

		Arguments::only( $arguments, array( 'search', 'autoload', 'include_transients', 'limit' ), $this->name() );
		$search     = Arguments::string( $arguments, 'search', false );
		$autoload   = Arguments::enum( $arguments, 'autoload', array( 'on', 'off' ) );
		$transients = Arguments::bool( $arguments, 'include_transients', false );
		$limit      = Arguments::int( $arguments, 'limit', 50, 1, 100 );

		$sql  = "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s";
		$args = array( null === $search ? '%' : Values::like( $wpdb, $search ) );
		if ( ! $transients ) {
			$sql .= " AND option_name NOT LIKE '\\_transient\\_%%' AND option_name NOT LIKE '\\_site\\_transient\\_%%'";
		}
		if ( null !== $autoload ) {
			$sql .= ( 'on' === $autoload ? ' AND autoload NOT IN ( %s, %s, %s )' : ' AND autoload IN ( %s, %s, %s )' );
			$args = array_merge( $args, self::NOT_AUTOLOADED );
		}
		$sql   .= ' ORDER BY option_name LIMIT %d';
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- read-only listing, prepared here.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$options = array();
		foreach ( $rows as $row ) {
			$preview   = Values::preview( (string) $row['option_name'], $row['option_value'] );
			$options[] = array(
				'name'      => (string) $row['option_name'],
				'value'     => $preview['value'],
				'truncated' => $preview['truncated'],
				'autoload'  => (string) $row['autoload'],
			);
		}

		return array(
			'options' => $options,
			'count'   => count( $options ),
			'limit'   => $limit,
		);
	}
}
