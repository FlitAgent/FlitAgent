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

		// Names first, limited, without the timeout rows; then the timeout rows of exactly those names. One query over
		// both kinds sorted value rows before timeout rows and cut the timeouts off at small limits.
		if ( $use_sitemeta ) {
			$select = "SELECT meta_key AS option_name, meta_value AS option_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND ";
			$column = 'meta_key';
			$scope  = array( (int) $wpdb->siteid );
		} else {
			$select = "SELECT option_name, option_value FROM {$wpdb->options} WHERE ";
			$column = 'option_name';
			$scope  = array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- read-only listing, prepared here with the scope and three values.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "{$select}{$column} LIKE %s AND {$column} NOT LIKE %s ORDER BY {$column} LIMIT %d", array_merge( $scope, array( $wpdb->esc_like( $prefix ) . $pattern, $wpdb->esc_like( $timeout_prefix ) . '%', $limit ) ) ), ARRAY_A );

		$values = array();
		foreach ( $rows as $row ) {
			$values[ substr( (string) $row['option_name'], strlen( $prefix ) ) ] = $row['option_value'];
		}

		$timeouts = array();
		if ( array() !== $values ) {
			$names = array();
			foreach ( array_keys( $values ) as $name ) {
				$names[] = $timeout_prefix . $name;
			}
			$in = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- read-only, one placeholder per name; $column is a fixed column name.
			$found = (array) $wpdb->get_results( $wpdb->prepare( "{$select}{$column} IN ({$in})", array_merge( $scope, $names ) ), ARRAY_A );
			foreach ( $found as $row ) {
				$timeouts[ substr( (string) $row['option_name'], strlen( $timeout_prefix ) ) ] = $row['option_value'];
			}
		}

		$transients = array();
		foreach ( $values as $name => $value ) {
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
