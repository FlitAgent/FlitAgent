<?php
/**
 * The get_cron_status tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\System;

use FlitAgent\Mcp\Tool;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Reports WP-Cron health without spawning it (status-only alternative to WP-CLI `cron test`).
 */
final class CronStatus implements Tool {

	/**
	 * Constant reader.
	 *
	 * @var Constants
	 */
	private $constants;

	/**
	 * Constructor.
	 *
	 * @param callable|null $lookup Constant reader, for tests.
	 */
	public function __construct( ?callable $lookup = null ) {
		$this->constants = new Constants( $lookup );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_cron_status';
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
			'Get WP-Cron status with FlitAgent',
			'reports WP-Cron health: whether DISABLE_WP_CRON or ALTERNATE_WP_CRON is set, how many events are scheduled and overdue, and whether a cron run is in progress. It never runs or spawns cron.'
		);
	}

	/**
	 * Reports the status.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array(), $this->name() );

		$events  = Cron::events();
		$overdue = count(
			array_filter(
				$events,
				static function ( $event ) {
					return $event['overdue'];
				}
			)
		);

		$lock    = (float) get_transient( 'doing_cron' );
		$timeout = (int) $this->constants->get( 'WP_CRON_LOCK_TIMEOUT', 60 );

		return array(
			'disable_wp_cron'   => $this->constants->is_true( 'DISABLE_WP_CRON' ),
			'alternate_wp_cron' => $this->constants->is_true( 'ALTERNATE_WP_CRON' ),
			'total_events'      => count( $events ),
			'overdue_events'    => $overdue,
			'running'           => $lock > 0 && $lock + $timeout > microtime( true ),
		);
	}
}
