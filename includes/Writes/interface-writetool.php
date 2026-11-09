<?php
/**
 * Write tool contract.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * A typed write. It is never an MCP tool itself: only Gateway runs it, after the kill switch, the access gate, argument
 * validation, idempotency, assessment and approval. Register it through a Tools\Provider.
 */
interface WriteTool {

	/**
	 * Unique tool name, e.g. `update_post`.
	 */
	public function name(): string;

	/**
	 * MCP definition built with Definition::write(): a closed input schema (with `idempotency_key` and `dry_run`, never a
	 * tier, approval, force or confirm field) and write annotations.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array;

	/**
	 * Every tier assess() can return apart from prohibited (Risk constants). An assessment outside this list is treated
	 * as destructive. Drives `destructiveHint`.
	 *
	 * @return string[]
	 */
	public function tiers(): array;

	/**
	 * Whether assess() can ever return an auto-approvable assessment. Only such tools are offered auto-approve in
	 * Settings, and policy is ignored for the others.
	 */
	public function may_auto_approve(): bool;

	/**
	 * Read-only: validates the arguments, checks the object-level capability, loads the current state and derives the
	 * tier. Must not write anything. Throw Mcp\ToolError for invalid arguments (no receipt); return
	 * Assessment::prohibited() for a refusal (refused receipt).
	 *
	 * @param array<string, mixed> $args Arguments without `idempotency_key` and `dry_run`.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment;

	/**
	 * Performs the write. Called only right after assess(), with that assessment. Returns the rollback reference and the
	 * post-write fingerprint. Throw Mcp\ToolError with a safe message to fail.
	 *
	 * @param array<string, mixed> $args       Arguments without `idempotency_key` and `dry_run`.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 */
	public function apply( array $args, Assessment $assessment ): Outcome;

	/**
	 * Reads the state back and flags mismatches: Outcome::with_warning() when the stored value differs (e.g. a filter
	 * changed it), Outcome::with_verification_failure() when the write cannot be confirmed at all.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome of apply().
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome;
}
