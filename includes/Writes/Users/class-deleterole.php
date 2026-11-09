<?php
/**
 * Write tool: delete_role.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Users;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `delete_role`: removes a role (remove_role()). Always destructive. Refuses `administrator`, the default role and any
 * role still assigned to a user. The role's name and capabilities are snapshotted; rollback adds it back.
 */
final class DeleteRole implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_role';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete a role',
			'Removes a user role that no user has. Always needs an administrator\'s approval in wp-admin (destructive). The administrator role and the default role for new users are never deleted. Roll back with rollback_change to add the role back with the same capabilities.',
			array(
				'role' => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_-]{1,64}$',
					'description' => 'Role slug.',
				),
			),
			array( 'role' ),
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
		return array( Risk::DESTRUCTIVE );
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
	 */
	public function assess( array $args ): Assessment {
		$refusal = Site::role_manager_refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$slug  = (string) $args['role'];
		$roles = Site::roles();
		$caps  = Site::role_caps( $roles, $slug );
		if ( null === $caps ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Role %s was not found.', $slug ) );
		}
		$refusal = Site::role_removal_refusal( $slug );
		if ( null === $refusal ) {
			$after = $roles;
			unset( $after[ $slug ] );
			$refusal = Site::lockout( array(), $after );
		}
		if ( null !== $refusal ) {
			return $refusal;
		}

		return Assessment::destructive(
			sprintf( 'Delete role %s "%s", which no user has. It granted %s.', $slug, $roles[ $slug ]['name'], Site::listing( Privilege::granted( $caps ) ) ),
			array(
				'role'   => $slug,
				'before' => array(
					'name'         => (string) $roles[ $slug ]['name'],
					'capabilities' => Site::listing( Privilege::granted( $caps ) ),
				),
				'after'  => array(
					'name'         => '(deleted)',
					'capabilities' => '(none)',
				),
			),
			array( 'fingerprint' => Site::role_fingerprint( $slug ) )
		);
	}

	/**
	 * Removes the role.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the role changed or got users since the assessment.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$fresh = $this->assess( $args );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $assessment->preconditions() ) {
			throw new ToolError( 'The role changed or was assigned while the deletion was being applied; nothing was deleted.' );
		}
		$slug  = (string) $args['role'];
		$roles = Site::roles();
		remove_role( $slug );

		return new Outcome(
			array( 'deleted_role' => $slug ),
			Rollback::make(
				Rollback::SNAPSHOT,
				'role',
				$slug,
				array(),
				array(
					'name'              => (string) $roles[ $slug ]['name'],
					'capabilities_json' => Canonical::encode( Site::role_caps( $roles, $slug ) ),
				)
			),
			array( 'fingerprint' => Site::role_fingerprint( $slug ) )
		);
	}

	/**
	 * Checks the role is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return null === Site::role_caps( Site::roles(), (string) $args['role'] ) ? $outcome : $outcome->with_verification_failure( 'The role still exists after the deletion.' );
	}

	/**
	 * Assesses adding the role back. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$refusal = Site::role_manager_refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$slug  = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		$name  = isset( $rollback['before']['name'] ) ? (string) $rollback['before']['name'] : $slug;
		$caps  = Site::caps_from_json( isset( $rollback['before']['capabilities_json'] ) ? $rollback['before']['capabilities_json'] : '' );
		$roles = Site::roles();
		if ( isset( $roles[ $slug ] ) ) {
			return Assessment::prohibited( 'role_exists', sprintf( 'A role %s exists again, so the deleted one cannot be restored.', $slug ) );
		}
		$refusal = Site::grant_refusal( Privilege::granted( $caps ), $roles );
		if ( null !== $refusal ) {
			return $refusal;
		}

		return Assessment::destructive(
			sprintf( 'Add role %s "%s" back, granting %s.', $slug, $name, Site::listing( Privilege::granted( $caps ) ) ),
			array(
				'role'     => $slug,
				'current'  => array(
					'name'         => '(none)',
					'capabilities' => '(none)',
				),
				'restored' => array(
					'name'         => $name,
					'capabilities' => Site::listing( Privilege::granted( $caps ) ),
				),
			),
			array(
				'fingerprint' => Site::role_fingerprint( $slug ),
				'caller'      => Site::user_fingerprint( get_userdata( Site::caller_id() ) ),
			)
		);
	}

	/**
	 * Adds the role back, if nothing changed since the rollback was assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the roles changed or WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$fresh = $this->assess_rollback( $rollback );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $expected_state ) {
			throw new ToolError( 'The roles changed after the rollback was assessed; nothing was restored.' );
		}
		$slug = (string) $rollback['target']['id'];
		$caps = Site::caps_from_json( $rollback['before']['capabilities_json'] );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- the null return marks failure; wpcom_vip_add_role() exists only on VIP and returns nothing.
		if ( null === add_role( $slug, (string) $rollback['before']['name'], $caps ) ) {
			throw new ToolError( sprintf( 'WordPress could not add role %s back.', esc_textarea( $slug ) ) );
		}

		$outcome = new Outcome( array( 'restored_role' => $slug ), Rollback::none() );
		return Site::role_caps( Site::roles(), $slug ) === $caps ? $outcome : $outcome->with_warning( 'restored_differently', 'The restored role grants different capabilities than the snapshot.' );
	}
}
