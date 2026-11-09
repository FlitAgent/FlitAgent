<?php
/**
 * Write tool: delete_expired_transients.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * `delete_expired_transients`: core's `delete_expired_transients()`, which removes only transients whose expiry has
 * passed. With a persistent object cache, transients live in the cache, which expires them itself.
 */
final class DeleteExpiredTransients extends RegenTool {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_expired_transients';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete expired transients',
			'Deletes transients whose expiry time has passed from the database (core delete_expired_transients). Unexpired transients are kept. With a persistent object cache the cache expires transients itself and this does nothing. Applied at once if an administrator enabled auto-approve for it; otherwise returns approval_required. Nothing to roll back: expired data is regenerated when needed.',
			array(),
			array(),
			$this->tiers(),
			false
		);
	}

	/**
	 * Assesses the cleanup. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$refusal = self::refuse_without_manage_options();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$persistent = (bool) wp_using_ext_object_cache();
		return Assessment::reversible(
			$persistent ? 'Delete expired transients (a persistent object cache is in use and expires them itself, so this likely does nothing).' : 'Delete expired transients from the database.',
			array( 'persistent_object_cache' => $persistent ),
			array(),
			true
		);
	}

	/**
	 * Deletes expired transients.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		delete_expired_transients( false );
		return new Outcome(
			array(
				'deleted_expired'         => true,
				'persistent_object_cache' => (bool) wp_using_ext_object_cache(),
			),
			self::regen( 'transient', 'expired' ),
			self::no_fingerprint()
		);
	}
}
