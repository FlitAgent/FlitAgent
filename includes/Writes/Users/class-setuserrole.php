<?php
/**
 * Write tool: set_user_role.
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
 * `set_user_role`: replaces a user's roles with one role (WP_User::set_role()), keeping capabilities of their own.
 * Destructive when an old or the new role is administrator-equivalent, or the target is the caller; otherwise
 * reversible. Refuses roles the caller may not assign or that grant capabilities the caller lacks, demoting the
 * caller, and leaving the site without an administrator. Rollback restores the previous roles under the same rails.
 */
final class SetUserRole implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'set_user_role';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: set a user\'s role',
			'Replaces a user\'s role(s) with one role, like changing the role in Users > Edit. Always needs an administrator\'s approval in wp-admin; it is a destructive change when either role has administrator-level capabilities or the user is you. Refused when it would demote you, leave the site without an administrator, or grant capabilities you do not have. Roll back with rollback_change.',
			array(
				'user_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the user.',
				),
				'role'    => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_-]{1,64}$',
					'description' => 'Role slug, e.g. editor (see get_roles).',
				),
			),
			array( 'user_id', 'role' ),
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
	 * Never auto-approved.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the role change. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$user_id = (int) $args['user_id'];
		$user    = Site::target( $user_id, 'promote_user' );
		if ( $user instanceof Assessment ) {
			return $user;
		}
		$role  = (string) $args['role'];
		$roles = Site::roles();
		if ( ! isset( $roles[ $role ] ) || ! Site::is_editable_role( $role ) ) {
			return Assessment::prohibited( 'role_not_assignable', sprintf( 'Role %s does not exist or you cannot assign it.', $role ) );
		}
		return self::assessment( $user, array( $role ), 'Change' );
	}

	/**
	 * Sets the role.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the user, roles or administrators changed since the assessment.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$fresh = $this->assess( $args );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $assessment->preconditions() ) {
			throw new ToolError( 'The user, the role or the administrators changed while the role was being set; nothing was changed.' );
		}
		$user_id = (int) $args['user_id'];
		$user    = get_userdata( $user_id );
		$before  = Privilege::roles_of( Site::caps_of( $user ), Site::roles() );
		$user->set_role( (string) $args['role'] );

		$after = get_userdata( $user_id );
		return new Outcome(
			array(
				'user_id'      => $user_id,
				'roles_before' => $before,
				'roles_after'  => $after ? Privilege::roles_of( Site::caps_of( $after ), Site::roles() ) : array(),
			),
			Rollback::make( Rollback::SNAPSHOT, 'user', $user_id, array(), array( 'roles' => $before ) ),
			array( 'fingerprint' => $after ? Site::user_fingerprint( $after ) : '' )
		);
	}

	/**
	 * Reads the roles back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$user = get_userdata( (int) $args['user_id'] );
		if ( ! $user ) {
			return $outcome->with_verification_failure( 'The user could not be read back.' );
		}
		if ( array( (string) $args['role'] ) !== Privilege::roles_of( Site::caps_of( $user ), Site::roles() ) ) {
			return $outcome->with_verification_failure( 'The user does not have exactly the requested role after the change.' );
		}
		return $outcome;
	}

	/**
	 * Assesses restoring the previous roles. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$user_id = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$user    = Site::target( $user_id, 'promote_user' );
		if ( $user instanceof Assessment ) {
			return $user;
		}
		$roles    = Site::roles();
		$restored = self::snapshot( $rollback );
		foreach ( $restored as $role ) {
			if ( ! isset( $roles[ $role ] ) || ! Site::is_editable_role( $role ) ) {
				return Assessment::prohibited( 'role_not_assignable', sprintf( 'Role %s no longer exists or you cannot assign it, so the previous roles cannot be restored.', $role ) );
			}
		}
		return self::assessment( $user, $restored, 'Restore' );
	}

	/**
	 * Restores the previous roles, if nothing changed since the rollback was assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the user, roles or administrators changed.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$fresh = $this->assess_rollback( $rollback );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $expected_state ) {
			throw new ToolError( 'The user, the roles or the administrators changed after the rollback was assessed; nothing was restored.' );
		}
		$user_id  = (int) $rollback['target']['id'];
		$restored = self::snapshot( $rollback );
		$user     = get_userdata( $user_id );
		$user->set_role( array() === $restored ? '' : $restored[0] );
		foreach ( array_slice( $restored, 1 ) as $role ) {
			$user->add_role( $role );
		}

		$after   = get_userdata( $user_id );
		$now     = $after ? Privilege::roles_of( Site::caps_of( $after ), Site::roles() ) : array();
		$outcome = new Outcome(
			array(
				'user_id'        => $user_id,
				'roles_restored' => $now,
			),
			Rollback::none()
		);
		sort( $now );
		$expected = $restored;
		sort( $expected );
		return $now === $expected ? $outcome : $outcome->with_verification_failure( 'The user does not have the previous roles after the rollback.' );
	}

	/**
	 * The assessment of giving a user exactly `$new_roles`, with every rail.
	 *
	 * @param object   $user      User.
	 * @param string[] $new_roles Roles after the change.
	 * @param string   $verb      `Change` or `Restore`.
	 * @return Assessment
	 * @throws ToolError When the user already has exactly those roles.
	 */
	private static function assessment( $user, array $new_roles, string $verb ): Assessment {
		$user_id   = (int) $user->ID;
		$roles     = Site::roles();
		$caps      = Site::caps_of( $user );
		$old_roles = Privilege::roles_of( $caps, $roles );
		$sorted    = array( $old_roles, $new_roles );
		sort( $sorted[0] );
		sort( $sorted[1] );
		if ( $sorted[0] === $sorted[1] ) {
			throw new ToolError( sprintf( 'User %d already has the role %s; nothing to change.', (int) $user_id, esc_textarea( Site::listing( $new_roles ) ) ) );
		}

		$granted = array();
		foreach ( $new_roles as $role ) {
			$granted = array_merge( $granted, Privilege::granted( (array) $roles[ $role ]['capabilities'] ) );
		}
		$refusal = Site::grant_refusal( array_values( array_unique( $granted ) ), $roles );
		if ( null !== $refusal ) {
			return $refusal;
		}

		$caps_after = Site::own_caps( $caps, $roles ) + array_fill_keys( $new_roles, true );
		$refusal    = Site::lockout( array( $user_id => $caps_after ) ) ?? Site::outrank_refusal( $user );
		if ( null !== $refusal ) {
			return $refusal;
		}

		$admin_before  = Privilege::admin_caps( Privilege::effective( $caps, $roles ) );
		$admin_after   = Privilege::admin_caps( Privilege::effective( $caps_after, $roles ) );
		$summary       = sprintf( '%s the role of user %d "%s" from %s to %s.', $verb, $user_id, $user->user_login, Site::listing( $old_roles ), Site::listing( $new_roles ) );
		$preview       = array(
			'user_id'    => $user_id,
			'user_login' => (string) $user->user_login,
			'before'     => array(
				'roles'                      => Site::listing( $old_roles ),
				'administrator_capabilities' => Site::listing( $admin_before ),
			),
			'after'      => array(
				'roles'                      => Site::listing( $new_roles ),
				'administrator_capabilities' => Site::listing( $admin_after ),
			),
		);
		$preconditions = array(
			'fingerprint'    => Site::user_fingerprint( $user ),
			'roles'          => Canonical::hash( $roles ),
			'administrators' => Canonical::hash( Site::administrator_ids() ),
		);

		if ( Site::caller_id() === $user_id || self::admin_equivalent_role( array_merge( $old_roles, $new_roles ), $roles ) ) {
			return Assessment::destructive( $summary . ' This involves administrator-level capabilities or your own account.', $preview, $preconditions );
		}
		return Assessment::reversible( $summary, $preview, $preconditions, false );
	}

	/**
	 * Whether any of the roles is administrator-equivalent.
	 *
	 * @param string[]                            $slugs Role slugs.
	 * @param array<string, array<string, mixed>> $roles Roles.
	 * @return bool
	 */
	private static function admin_equivalent_role( array $slugs, array $roles ): bool {
		foreach ( $slugs as $slug ) {
			if ( isset( $roles[ $slug ] ) && array() !== Privilege::admin_caps( (array) $roles[ $slug ]['capabilities'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Previous role slugs from a rollback reference.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return string[]
	 */
	private static function snapshot( array $rollback ): array {
		$roles = isset( $rollback['before']['roles'] ) && is_array( $rollback['before']['roles'] ) ? $rollback['before']['roles'] : array();
		return array_values( array_filter( array_map( 'strval', $roles ), 'strlen' ) );
	}
}
