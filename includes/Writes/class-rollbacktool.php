<?php
/**
 * Rollback as a gated write.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * `rollback_change`: undoes the write recorded in a receipt, through the same Gateway pipeline as any write (kill
 * switch, idempotency, approval, receipt).
 *
 * Tier: refused when the receipt is unknown, was not applied, records a rollback, has no rollback or was already rolled
 * back; reversible (auto-approvable as the original tool says) when the target still matches the fingerprint stored
 * after the write; destructive (approval) when the target changed since. Undoing a write another AI app made always
 * needs a person, whatever auto-approve allows. Built by Gateway, never registered directly.
 */
final class RollbackTool implements WriteTool {

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Finds a write tool by name: `function ( string $name ): ?WriteTool`.
	 *
	 * @var callable
	 */
	private $find_tool;

	/**
	 * The calling app's client id: `function (): string`. Null: no app check (a person's own tooling).
	 *
	 * @var callable|null
	 */
	private $caller;

	/**
	 * Constructor.
	 *
	 * @param Store         $store     Store.
	 * @param callable      $find_tool Finds a write tool by name.
	 * @param callable|null $caller    The calling app's client id.
	 */
	public function __construct( Store $store, callable $find_tool, ?callable $caller = null ) {
		$this->store     = $store;
		$this->find_tool = $find_tool;
		$this->caller    = $caller;
	}

	/**
	 * Tool name.
	 */
	public function name(): string {
		return Gateway::ROLLBACK_TOOL;
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			Gateway::ROLLBACK_TOOL,
			'FlitAgent: roll back a change',
			'Undoes one write recorded in an FlitAgent receipt (receipt_id from a write result or list_receipts), restoring the state before it. If the target changed after the write, the rollback needs an administrator\'s approval and returns approval_required with a preview of current versus restored state. A receipt can be rolled back once. Undoing a change another AI app made always returns approval_required.',
			array(
				'receipt_id' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 64,
					'description' => 'receipt_id of the write to undo.',
				),
			),
			array( 'receipt_id' ),
			$this->tiers(),
			false
		);
	}

	/**
	 * Tiers.
	 *
	 * @return string[]
	 */
	public function tiers(): array {
		return array( Risk::REVERSIBLE, Risk::DESTRUCTIVE );
	}

	/**
	 * May auto-approve (an undrifted rollback, when the original tool allows it and the policy enables it).
	 */
	public function may_auto_approve(): bool {
		return true;
	}

	/**
	 * Assesses the rollback.
	 *
	 * @param array<string, mixed> $args `receipt_id`.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$id      = (string) $args['receipt_id'];
		$receipt = $this->store->receipt( $id );
		if ( null === $receipt ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Receipt %s was not found.', $id ) );
		}
		if ( '' !== (string) $receipt['rolled_back_by'] ) {
			return Assessment::prohibited( 'already_rolled_back', sprintf( 'Receipt %s was already rolled back (receipt %s).', $id, $receipt['rolled_back_by'] ) );
		}
		if ( ! in_array( $receipt['status'], Gateway::APPLIED_STATUSES, true ) ) {
			return Assessment::prohibited( 'not_applied', sprintf( 'Receipt %s was not applied (status: %s), so there is nothing to roll back.', $id, $receipt['status'] ) );
		}

		$rollback = Canonical::decode( $receipt['rollback_json'] );
		$tool     = Gateway::ROLLBACK_TOOL === $receipt['tool'] ? null : call_user_func( $this->find_tool, (string) $receipt['tool'] );
		if ( ! Rollback::is_restorable( $rollback ) || ! $tool instanceof Reversible ) {
			return Assessment::prohibited( 'no_rollback', sprintf( 'Receipt %s has no rollback.', $id ) );
		}

		$assessment = $tool->assess_rollback( $rollback );
		if ( $assessment->is_refused() ) {
			return $assessment;
		}

		$after   = isset( $rollback['after']['fingerprint'] ) ? $rollback['after']['fingerprint'] : null;
		$current = isset( $assessment->preconditions()['fingerprint'] ) ? $assessment->preconditions()['fingerprint'] : null;
		if ( ! is_string( $after ) || ! is_string( $current ) || ! hash_equals( $after, $current ) ) {
			return $assessment->at_least( Risk::DESTRUCTIVE )->with_summary(
				sprintf( 'The target of receipt %s changed after that write; rolling back would overwrite the later changes. %s', $id, $assessment->summary() )
			);
		}

		// One app may undo another's write, but only with a person: auto-approve was chosen for each app's own changes.
		if ( null !== $this->caller && ! hash_equals( (string) $receipt['client_id'], (string) call_user_func( $this->caller ) ) ) {
			return $assessment->needing_a_person()->with_summary(
				sprintf( 'Roll back receipt %s (%s), made by another AI app (%s): %s', $id, $receipt['tool'], $receipt['client_name'], $assessment->summary() )
			);
		}

		return $assessment->with_summary( sprintf( 'Roll back receipt %s (%s): %s', $id, $receipt['tool'], $assessment->summary() ) );
	}

	/**
	 * Restores the state before the write.
	 *
	 * @param array<string, mixed> $args       `receipt_id`.
	 * @param Assessment           $assessment Fresh assessment.
	 * @return Outcome
	 * @throws \RuntimeException When the receipt or tool disappeared since assess().
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$receipt = $this->store->receipt( (string) $args['receipt_id'] );
		$tool    = null === $receipt ? null : call_user_func( $this->find_tool, (string) $receipt['tool'] );
		if ( ! $tool instanceof Reversible ) {
			throw new \RuntimeException( 'Receipt or tool disappeared.' );
		}

		$outcome = $tool->rollback( Canonical::decode( $receipt['rollback_json'] ), $assessment->preconditions() );

		$undo = new Outcome( array_merge( $outcome->result(), array( 'rolls_back' => $args['receipt_id'] ) ), Rollback::none() );

		// Keep what the tool reported about its own restore, so the caller and the receipt see it.
		foreach ( $outcome->warnings() as $warning ) {
			$undo = 'verification_failed' === $warning['code'] && $outcome->verification_failed()
				? $undo->with_verification_failure( $warning['message'] )
				: $undo->with_warning( $warning['code'], $warning['message'] );
		}

		return $undo;
	}

	/**
	 * The original tool's rollback() checks its own write; nothing more to read back here.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return $outcome;
	}
}
