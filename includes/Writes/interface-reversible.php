<?php
/**
 * Rollback contract for write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * A write tool whose writes can be undone. Rollbacks run through Gateway as the `rollback_change` write.
 */
interface Reversible {

	/**
	 * Read-only: assesses undoing a write. Checks the object-level capability and that the rollback is still possible,
	 * and puts the target's current fingerprint in `preconditions['fingerprint']`, computed exactly like the
	 * `fingerprint` in the write's Outcome::after(). Gateway compares the two: if they differ (or the key is missing),
	 * the rollback is destructive and needs approval. The preview should show `current` and `restored` state.
	 *
	 * @param array<string, mixed> $rollback Rollback reference from the receipt (Rollback shape plus `after`).
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment;

	/**
	 * Restores the state before the write. Must re-check `$expected_state['fingerprint']` against the current state
	 * immediately before writing and throw Mcp\ToolError without writing if it changed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference from the receipt.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome;
}
