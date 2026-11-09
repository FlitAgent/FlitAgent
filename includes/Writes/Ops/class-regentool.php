<?php
/**
 * Base of the operations writes that clear derived data.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * A write that clears data WordPress or a plugin regenerates on demand (caches, rewrite rules, transients). It is
 * reversible in the `regen` sense: nothing needs restoring, so the receipt records rollback type `regen` and
 * `rollback_change` explains that instead of pretending to undo. Needs `manage_options`.
 */
abstract class RegenTool implements WriteTool, Reversible {

	/**
	 * Why a regen write has nothing to roll back.
	 */
	const NOTHING_TO_RESTORE = 'This write cleared data that WordPress or the plugin regenerates on demand, so there is nothing to roll back.';

	/**
	 * Tiers.
	 *
	 * @return string[]
	 */
	public function tiers(): array {
		return array( Risk::REVERSIBLE );
	}

	/**
	 * May auto-approve.
	 */
	public function may_auto_approve(): bool {
		return true;
	}

	/**
	 * Nothing is read back by default.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return $outcome;
	}

	/**
	 * Refused: the data regenerates.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		return Assessment::prohibited( 'regenerates', self::NOTHING_TO_RESTORE );
	}

	/**
	 * Never reached: assess_rollback() refuses.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions.
	 * @throws ToolError Always; it never returns an Outcome.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		throw new ToolError( esc_textarea( self::NOTHING_TO_RESTORE ) );
	}

	/**
	 * A refusal when the caller lacks `manage_options`, else null.
	 *
	 * @return Assessment|null
	 */
	protected static function refuse_without_manage_options(): ?Assessment {
		return current_user_can( 'manage_options' ) ? null : Assessment::prohibited( 'forbidden', 'This operation needs the manage_options capability.' );
	}

	/**
	 * The regen rollback reference.
	 *
	 * @param string $kind What was cleared, e.g. `cache`.
	 * @param string $id   Which one, e.g. `object`.
	 * @return array<string, mixed>
	 */
	protected static function regen( string $kind, string $id ): array {
		return Rollback::make( Rollback::REGEN, $kind, $id );
	}

	/**
	 * Post-write fingerprint of a regen write: there is no state to compare.
	 *
	 * @return array<string, string>
	 */
	protected static function no_fingerprint(): array {
		return array( 'fingerprint' => '' );
	}
}
