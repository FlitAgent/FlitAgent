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
 * Health triage (#118): what is wrong with the site, how serious it is, and which FlitAgent tool fixes it.
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
			'checks the site for problems and returns findings, most serious first: plugin, theme and WordPress updates, inactive plugins and unused themes, cron tasks more than an hour late, stuck maintenance mode, PHP errors shown to visitors, and critical issues from the last saved Site Health check, and on WooCommerce stores a pending database update, failed and on-hold orders from the last 7 days, outdated template overrides and out-of-stock products. Each finding has an id, severity (critical, warning, info), title, detail and fix: the FlitAgent tool and arguments that fix it (add an idempotency_key; the fix still needs approval like any write), or null when only a person can fix it. To fix several at once, pass the fixes as items to submit_plan so an administrator approves them together. summary counts findings per severity. Uses only data WordPress already cached and never contacts wordpress.org; run it first when asked to check, maintain or fix a site.'
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
