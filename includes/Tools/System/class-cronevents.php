<?php
/**
 * The list_cron_events tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\System;

use FlitAgent\Mcp\Tool;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Lists scheduled WP-Cron events (WP-CLI `cron event list`).
 */
final class CronEvents implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_cron_events';
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
			'List cron events with FlitAgent',
			'lists scheduled WP-Cron events, soonest first: hook, next run (ISO 8601), schedule and interval in seconds (null for one-off events), whether it is overdue, and its arguments with secret-looking fields redacted. total is the count before limit.',
			array(
				'search' => array(
					'type'        => 'string',
					'description' => 'Hook pattern; * matches any text and ? one character, e.g. "wp_*".',
				),
				'limit'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 500,
					'default' => 100,
				),
			)
		);
	}

	/**
	 * Lists the events.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array( 'search', 'limit' ), $this->name() );
		$search = Arguments::string( $arguments, 'search', false );
		$limit  = Arguments::int( $arguments, 'limit', 100, 1, 500 );

		$events = Cron::events();
		if ( null !== $search ) {
			$regex  = '/^' . str_replace( array( '\*', '\?' ), array( '.*', '.' ), preg_quote( $search, '/' ) ) . '$/i';
			$events = array_values(
				array_filter(
					$events,
					static function ( $event ) use ( $regex ) {
						return 1 === preg_match( $regex, $event['hook'] );
					}
				)
			);
		}

		return array(
			'events' => array_slice( $events, 0, $limit ),
			'total'  => count( $events ),
		);
	}
}
