<?php
/**
 * Tool: get_health_triage.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;
use FlitAgent\Tools\Triage\SiteSnapshot;
use FlitAgent\Tools\Triage\Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Health triage (#118): what is wrong with the site, how serious it is, and what to do about it in wp-admin.
 */
final class HealthTriage implements Tool {

	/**
	 * Returns the snapshot to triage.
	 *
	 * @var callable
	 */
	private $snapshot;

	/**
	 * Constructor.
	 *
	 * @param callable|null $snapshot Returns the snapshot; SiteSnapshot::read by default.
	 */
	public function __construct( ?callable $snapshot = null ) {
		$this->snapshot = $snapshot ? $snapshot : array( SiteSnapshot::class, 'read' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_health_triage';
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
			'Triage FlitAgent site health',
			'checks the site for problems and returns findings, most serious first: plugin, theme and WordPress updates, inactive plugins and unused themes, cron tasks more than an hour late, stuck maintenance mode, PHP errors shown to visitors, and critical issues from the last saved Site Health check. Each finding has an id, severity (critical, warning, info), title and detail; the detail says in plain words what a person should do in wp-admin. FlitAgent only reads: it changes nothing on the site. summary counts findings per severity. Uses only data WordPress already cached and never contacts wordpress.org; run it first when asked to check or review a site.'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $arguments Arguments (none).
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array(), $this->name() );

		return Triage::run( call_user_func( $this->snapshot ) );
	}
}
