<?php
/**
 * Write tool: delete_user_meta.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Users;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `delete_user_meta`: deletes every value of one user meta key. Same protected keys as set_user_meta. Reversible: the
 * values are snapshotted and restored by rollback.
 */
final class DeleteUserMeta implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_user_meta';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete user meta',
			'Deletes every value of one user meta key of one user. Always needs an administrator\'s approval in wp-admin. Keys for roles, capabilities, user levels, sessions, application passwords, two-factor data, email addresses, user-table fields and secret-looking names are refused. Roll back with rollback_change to restore the values.',
			Meta::properties(),
			array( 'user_id', 'key' ),
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
		return array( Risk::REVERSIBLE );
	}

	/**
	 * Never auto-approved.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the deletion. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the key is not set.
	 */
	public function assess( array $args ): Assessment {
		$user_id = (int) $args['user_id'];
		$key     = (string) $args['key'];
		$user    = Meta::target( $user_id, $key );
		if ( $user instanceof Assessment ) {
			return $user;
		}
		$current = Meta::values( $user_id, $key );
		if ( array() === $current ) {
			throw new ToolError( sprintf( 'User meta %s of user %d is not set; nothing to delete.', esc_textarea( $key ), (int) $user_id ) );
		}
		$refusal = Meta::unrestorable( $current, $key );
		if ( null !== $refusal ) {
			return $refusal;
		}

		return Assessment::reversible(
			sprintf( 'Delete user meta %s of user %d "%s" (%s).', $key, $user_id, $user->user_login, Summary::count( count( $current ), 'value', 'values' ) ),
			array(
				'user_id' => $user_id,
				'key'     => $key,
				'before'  => array( 'value' => Meta::show_all( $current ) ),
				'after'   => array( 'value' => '(deleted)' ),
			),
			array( 'fingerprint' => Meta::fingerprint( $user_id, $key ) ),
			false
		);
	}

	/**
	 * Deletes the key.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the key changed since the assessment or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$fresh = $this->assess( $args );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $assessment->preconditions() ) {
			throw new ToolError( 'The user meta changed while the deletion was being applied; nothing was deleted.' );
		}
		$user_id = (int) $args['user_id'];
		$key     = (string) $args['key'];
		$before  = Meta::values( $user_id, $key );
		if ( ! delete_user_meta( $user_id, $key ) ) {
			throw new ToolError( sprintf( 'WordPress could not delete user meta %s.', esc_textarea( $key ) ) );
		}

		return new Outcome(
			array(
				'user_id'        => $user_id,
				'key'            => $key,
				'values_deleted' => count( $before ),
			),
			Meta::rollback_reference( $user_id, $key, $before ),
			array( 'fingerprint' => Meta::fingerprint( $user_id, $key ) )
		);
	}

	/**
	 * Checks the key is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return array() === Meta::values( (int) $args['user_id'], (string) $args['key'] ) ? $outcome : $outcome->with_verification_failure( 'The user meta still has values after the deletion.' );
	}

	/**
	 * Assesses restoring the deleted values. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		return Meta::assess_restore( $rollback );
	}

	/**
	 * Restores the deleted values.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		return Meta::restore( $rollback, $expected_state );
	}
}
