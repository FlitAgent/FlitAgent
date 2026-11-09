<?php
/**
 * The list_transients tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Settings;

use FlitAgent\Mcp\Tool;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Lists transients with expiry and short, redacted values (WP-CLI `transient list`).
 */
final class TransientList implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_transients';
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
			'List transients with FlitAgent',
			'lists cached transients stored in the database with name, expiry (ISO 8601) and a redacted 200-character value preview. Set network to true for site transients. Transients kept only in a persistent object cache are not listed.',
			array(
				'search'  => array(
					'type'        => 'string',
					'description' => 'Name pattern; * matches any text and ? one character.',
				),
				'network' => array( 'type' => 'boolean' ),
				'limit'   => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 50,
				),
			)
		);
	}

	/**
	 * Lists the transients.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		global $wpdb;

		Arguments::only( $arguments, array( 'search', 'network', 'limit' ), $this->name() );
		$search  = Arguments::string( $arguments, 'search', false );
		$network = Arguments::bool( $arguments, 'network', false );
		$limit   = Arguments::int( $arguments, 'limit', 50, 1, 100 );

		$prefix         = $network ? '_site_transient_' : '_transient_';
		$timeout_prefix = $prefix . 'timeout_';
		$pattern        = null === $search ? '%' : Values::like( $wpdb, $search );
		$use_sitemeta   = $network && is_multisite();

		if ( $use_sitemeta ) {
			$sql  = "SELECT meta_key AS option_name, meta_value AS option_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND ( meta_key LIKE %s OR meta_key LIKE %s ) ORDER BY meta_key LIMIT %d";
			$args = array( (int) $wpdb->siteid, $wpdb->esc_like( $prefix ) . $pattern, $wpdb->esc_like( $timeout_prefix ) . $pattern, $limit * 2 );
		} else {
			$sql  = "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name LIMIT %d";
			$args = array( $wpdb->esc_like( $prefix ) . $pattern, $wpdb->esc_like( $timeout_prefix ) . $pattern, $limit * 2 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- read-only listing, prepared here.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$values   = array();
		$timeouts = array();
		foreach ( $rows as $row ) {
			$key = (string) $row['option_name'];
			if ( 0 === strpos( $key, $timeout_prefix ) ) {
				$timeouts[ substr( $key, strlen( $timeout_prefix ) ) ] = $row['option_value'];
			} elseif ( 0 === strpos( $key, $prefix ) ) {
				$values[ substr( $key, strlen( $prefix ) ) ] = $row['option_value'];
			}
		}

		$transients = array();
		foreach ( array_slice( $values, 0, $limit, true ) as $name => $value ) {
			$name         = (string) $name;
			$preview      = Values::preview( $name, $value );
			$transients[] = array(
				'name'       => $name,
				'value'      => $preview['value'],
				'truncated'  => $preview['truncated'],
				'expires_at' => Values::iso( $timeouts[ $name ] ?? null ),
			);
		}

		return array(
			'transients' => $transients,
			'network'    => $network,
			'count'      => count( $transients ),
		);
	}
}
