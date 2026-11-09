<?php
/**
 * Write tool: set_user_capabilities.
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
 * `set_user_capabilities`: grants and/or removes capabilities of one user itself, on top of their roles
 * (WP_User::add_cap()/remove_cap()). Always destructive. Role names are refused both ways (WordPress treats a
 * capability named like a role as that role), as are capabilities the caller lacks and changes that leave no
 * administrator or demote the caller. The user's own capabilities are snapshotted; rollback restores them.
 */
final class SetUserCapabilities implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'set_user_capabilities';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: change a user\'s own capabilities',
			'Grants and/or removes capabilities of one user itself, on top of their role (remove only takes capabilities the user has of their own; to change what a role grants use set_role_capabilities, to change a role use set_user_role). Always needs an administrator\'s approval in wp-admin (destructive). Refused for role names, capabilities you do not have, demoting yourself, or leaving the site without an administrator. Roll back with rollback_change.',
			array(
				'user_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the user.',
				),
				'add'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Capabilities to grant the user.',
				),
				'remove'  => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Capabilities of the user itself to remove.',
				),
			),
			array( 'user_id' ),
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
	 * Assesses the change. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the lists are invalid, a removed capability comes from a role, or nothing would change.
	 */
	public function assess( array $args ): Assessment {
		list( $add, $remove ) = Changes::lists( $args );
		$user_id              = (int) $args['user_id'];
		$user                 = Site::target( $user_id, 'promote_user' );
		if ( $user instanceof Assessment ) {
			return $user;
		}
		$roles      = Site::roles();
		$role_names = array_values( array_intersect( $remove, array_keys( $roles ) ) );
		if ( array() !== $role_names ) {
			return Assessment::prohibited( 'role_as_capability', sprintf( '%s is a role, not a capability; WordPress would treat it as that role. Use set_user_role instead.', implode( ', ', $role_names ) ) );
		}
		$own = self::own( $user, $roles );
		foreach ( $remove as $cap ) {
			if ( ! array_key_exists( $cap, $own ) ) {
				throw new ToolError( sprintf( '%s is not a capability of user %d itself (it comes from their role, or they do not have it); change the role instead.', esc_textarea( $cap ), (int) $user_id ) );
			}
		}
		$wanted = array_diff_key( $own, array_flip( $remove ) );
		foreach ( $add as $cap ) {
			$wanted[ $cap ] = true;
		}
		return self::assessment( $user, $own, $wanted, 'Change' );
	}

	/**
	 * Changes the user's capabilities.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When anything changed since the assessment.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$fresh = $this->assess( $args );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $assessment->preconditions() ) {
			throw new ToolError( 'The user, the roles, the administrators or your account changed while the change was being applied; nothing was changed.' );
		}
		$user_id              = (int) $args['user_id'];
		$user                 = get_userdata( $user_id );
		$own                  = self::own( $user, Site::roles() );
		list( $add, $remove ) = Changes::lists( $args );
		$wanted               = array_diff_key( $own, array_flip( $remove ) );
		foreach ( $add as $cap ) {
			$wanted[ $cap ] = true;
		}
		self::write( $user, $own, $wanted );

		$after = get_userdata( $user_id );
		return new Outcome(
			array(
				'user_id' => $user_id,
				'added'   => array_values( array_diff( Privilege::granted( $wanted ), Privilege::granted( $own ) ) ),
				'removed' => $remove,
			),
			Rollback::make( Rollback::SNAPSHOT, 'user', $user_id, array(), array( 'capabilities_json' => Canonical::encode( $own ) ) ),
			array( 'fingerprint' => $after ? Site::user_fingerprint( $after ) : '' )
		);
	}

	/**
	 * Reads the user's capabilities back.
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
		$own                  = self::own( $user, Site::roles() );
		list( $add, $remove ) = Changes::lists( $args );
		foreach ( $add as $cap ) {
			if ( empty( $own[ $cap ] ) ) {
				return $outcome->with_verification_failure( sprintf( 'The user does not have %s after the change.', $cap ) );
			}
		}
		foreach ( $remove as $cap ) {
			if ( array_key_exists( $cap, $own ) ) {
				return $outcome->with_verification_failure( sprintf( 'The user still has %s after the change.', $cap ) );
			}
		}
		return $outcome;
	}

	/**
	 * Assesses restoring the user's own capabilities. Read-only.
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
		$restored = array_diff_key( self::snapshot( $rollback ), $roles );
		return self::assessment( $user, self::own( $user, $roles ), $restored, 'Restore' );
	}

	/**
	 * Restores the user's own capabilities, if nothing changed since the rollback was assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When anything changed.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$fresh = $this->assess_rollback( $rollback );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $expected_state ) {
			throw new ToolError( 'The user, the roles, the administrators or your account changed after the rollback was assessed; nothing was restored.' );
		}
		$user_id  = (int) $rollback['target']['id'];
		$user     = get_userdata( $user_id );
		$roles    = Site::roles();
		$restored = array_diff_key( self::snapshot( $rollback ), $roles );
		self::write( $user, self::own( $user, $roles ), $restored );

		$after   = get_userdata( $user_id );
		$outcome = new Outcome( array( 'user_id' => $user_id ), Rollback::none() );
		return $after && Changes::same( self::own( $after, Site::roles() ), $restored ) ? $outcome : $outcome->with_warning( 'restored_differently', 'The user has different capabilities than the snapshot.' );
	}

	/**
	 * The assessment of giving a user exactly `$wanted` as capabilities of their own, with every rail.
	 *
	 * @param object              $user User.
	 * @param array<string, bool> $own  Their own capabilities now.
	 * @param array<string, bool> $wanted  Their own capabilities after.
	 * @param string              $verb `Change` or `Restore`.
	 * @return Assessment
	 * @throws ToolError When nothing would change.
	 */
	private static function assessment( $user, array $own, array $wanted, string $verb ): Assessment {
		$user_id = (int) $user->ID;
		if ( Changes::same( $own, $wanted ) ) {
			throw new ToolError( sprintf( 'User %d already has those capabilities; nothing to change.', (int) $user_id ) );
		}
		$roles  = Site::roles();
		$caps   = Site::caps_of( $user );
		$gained = array_values( array_diff( Privilege::granted( $wanted ), Privilege::granted( $own ) ) );
		$lost   = array_values( array_diff( Privilege::granted( $own ), Privilege::granted( $wanted ) ) );

		$caps_after = array_diff_key( $caps, $own ) + $wanted;
		$refusal    = Site::grant_refusal( $gained, $roles );
		if ( null === $refusal ) {
			$refusal = Site::lockout( array( $user_id => $caps_after ) ) ?? Site::outrank_refusal( $user );
		}
		if ( null !== $refusal ) {
			return $refusal;
		}

		$admin_before = Privilege::admin_caps( Privilege::effective( $caps, $roles ) );
		$admin_after  = Privilege::admin_caps( Privilege::effective( $caps_after, $roles ) );
		return Assessment::destructive(
			sprintf( '%s the capabilities of user %d "%s" itself: add %s; remove %s.', $verb, $user_id, $user->user_login, Site::listing( $gained ), Site::listing( $lost ) ),
			array(
				'user_id'    => $user_id,
				'user_login' => (string) $user->user_login,
				'before'     => array(
					'own_capabilities'           => Site::listing( Privilege::granted( $own ) ),
					'administrator_capabilities' => Site::listing( $admin_before ),
				),
				'after'      => array(
					'own_capabilities'           => Site::listing( Privilege::granted( $wanted ) ),
					'administrator_capabilities' => Site::listing( $admin_after ),
				),
			),
			array(
				'fingerprint'    => Site::user_fingerprint( $user ),
				'roles'          => Canonical::hash( $roles ),
				'administrators' => Canonical::hash( Site::administrator_ids() ),
				'caller'         => Site::user_fingerprint( get_userdata( Site::caller_id() ) ),
			)
		);
	}

	/**
	 * Makes the user's own capabilities exactly `$wanted`.
	 *
	 * @param object              $user User.
	 * @param array<string, bool> $own  Their own capabilities now.
	 * @param array<string, bool> $wanted  Their own capabilities after.
	 */
	private static function write( $user, array $own, array $wanted ): void {
		foreach ( $wanted as $cap => $grant ) {
			if ( ! array_key_exists( $cap, $own ) || $own[ $cap ] !== $grant ) {
				$user->add_cap( $cap, $grant );
			}
		}
		foreach ( array_keys( array_diff_key( $own, $wanted ) ) as $cap ) {
			$user->remove_cap( $cap );
		}
	}

	/**
	 * The user's own capabilities as name => bool.
	 *
	 * @param object                              $user  User.
	 * @param array<string, array<string, mixed>> $roles Roles.
	 * @return array<string, bool>
	 */
	private static function own( $user, array $roles ): array {
		$own = array();
		foreach ( Site::own_caps( Site::caps_of( $user ), $roles ) as $cap => $grant ) {
			$own[ (string) $cap ] = (bool) $grant;
		}
		return $own;
	}

	/**
	 * Own capabilities from a rollback reference.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return array<string, bool>
	 */
	private static function snapshot( array $rollback ): array {
		return Site::caps_from_json( isset( $rollback['before']['capabilities_json'] ) ? $rollback['before']['capabilities_json'] : '' );
	}
}
