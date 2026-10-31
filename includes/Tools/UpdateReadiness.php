<?php
/**
 * Tool: get_update_readiness.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;
use FlitAgent\Tools\Triage\UpdateSnapshot;
use FlitAgent\Tools\Triage\Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Update readiness (#123): every pending update, whether this site can take it, a recommended order and the backup status.
 */
final class UpdateReadiness implements Tool {

	/**
	 * Returns the snapshot.
	 *
	 * @var callable
	 */
	private $snapshot;

	/**
	 * Constructor.
	 *
	 * @param callable|null $snapshot Returns the snapshot; UpdateSnapshot::read by default.
	 */
	public function __construct( ?callable $snapshot = null ) {
		$this->snapshot = $snapshot ? $snapshot : array( UpdateSnapshot::class, 'read' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_update_readiness';
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
			'Check FlitAgent update readiness',
			'checks every pending plugin, theme and WordPress update before anything is updated: installed and new version, major or minor jump, the WordPress and PHP versions it needs against this site, "tested up to", whether the site uses it, and whether it is ready or blocked (with the reason). Updates are listed in the recommended order: plugins and themes before WordPress, inactive before active, minor before major, blocked last. backup reports the active backup plugin and when it last backed up, with a warning when there is none, it cannot tell, or it is older than 24 hours; backups never block. A person applies the updates in wp-admin, in this order; FlitAgent only reads and changes nothing on the site. Uses cached update checks only and never contacts wordpress.org.'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $arguments Arguments (none).
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array(), $this->name() );

		return Updates::run( call_user_func( $this->snapshot ) );
	}
}
