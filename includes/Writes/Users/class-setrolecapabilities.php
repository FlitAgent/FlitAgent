<?php
/**
 * Write tool: set_role_capabilities.
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
 * `set_role_capabilities`: adds and/or removes capabilities of a role (WP_Role::add_cap()/remove_cap()). Always
 * destructive: it changes every user with the role. Refuses removing core capabilities from `administrator`, granting
 * role names or capabilities the caller lacks, and changes that leave no administrator or demote the caller. The
 * role's capabilities are snapshotted; rollback restores them under the same rails.
 */
final class SetRoleCapabilities implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'set_role_capabilities';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: change a role\'s capabilities',
			'Adds and/or removes capabilities of one role, for every user with that role. Always needs an administrator\'s approval in wp-admin (destructive). Refused when it removes core capabilities from the administrator role, grants a role name or a capability you do not have, demotes you, or leaves the site without an administrator. Roll back with rollback_change.',
			array(
				'role'   => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_-]{1,64}$',
					'description' => 'Role slug.',
				),
				'add'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Capabilities to grant.',
				),
				'remove' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Capabilities to remove.',
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
	 * Assesses the change. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When nothing is given, a capability is in both lists, or nothing would change.
	 */
	public function assess( array $args ): Assessment {
		$refusal = Site::role_manager_refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		list( $add, $remove ) = Changes::lists( $args );
		$slug                 = (string) $args['role'];
		$current              = Site::role_caps( Site::roles(), $slug );
		if ( null === $current ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Role %s was not found.', $slug ) );
		}
		$wanted = array_diff_key( $current, array_flip( $remove ) );
		foreach ( $add as $cap ) {
			$wanted[ $cap ] = true;
		}
		return self::assessment( $slug, $current, $wanted, 'Change' );
	}

	/**
	 * Changes the role.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When anything changed since the assessment.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$fresh = $this->assess( $args );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $assessment->preconditions() ) {
			throw new ToolError( 'The role, the administrators or your account changed while the change was being applied; nothing was changed.' );
		}
		$slug                 = (string) $args['role'];
		$current              = Site::role_caps( Site::roles(), $slug );
		list( $add, $remove ) = Changes::lists( $args );
		$wanted               = array_diff_key( $current, array_flip( $remove ) );
		foreach ( $add as $cap ) {
			$wanted[ $cap ] = true;
		}
		self::write( $slug, $current, $wanted );

		return new Outcome(
			array(
				'role'    => $slug,
				'added'   => array_values( array_diff( Privilege::granted( $wanted ), Privilege::granted( $current ) ) ),
				'removed' => array_values( array_diff( Privilege::granted( $current ), Privilege::granted( $wanted ) ) ),
			),
			Rollback::make( Rollback::SNAPSHOT, 'role', $slug, array(), array( 'capabilities_json' => Canonical::encode( $current ) ) ),
			array( 'fingerprint' => Site::role_fingerprint( $slug ) )
		);
	}

	/**
	 * Reads the role back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$caps = Site::role_caps( Site::roles(), (string) $args['role'] );
		if ( null === $caps ) {
			return $outcome->with_verification_failure( 'The role could not be read back.' );
		}
		list( $add, $remove ) = Changes::lists( $args );
		foreach ( $add as $cap ) {
			if ( empty( $caps[ $cap ] ) ) {
				return $outcome->with_verification_failure( sprintf( 'The role does not grant %s after the change.', $cap ) );
			}
		}
		foreach ( $remove as $cap ) {
			if ( isset( $caps[ $cap ] ) ) {
				return $outcome->with_verification_failure( sprintf( 'The role still has %s after the change.', $cap ) );
			}
		}
		return $outcome;
	}

	/**
	 * Assesses restoring the role's capabilities. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$refusal = Site::role_manager_refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$slug    = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		$current = Site::role_caps( Site::roles(), $slug );
		if ( null === $current ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Role %s was not found.', $slug ) );
		}
		return self::assessment( $slug, $current, self::snapshot( $rollback ), 'Restore' );
	}

	/**
	 * Restores the role's capabilities, if nothing changed since the rollback was assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When anything changed.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$fresh = $this->assess_rollback( $rollback );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $expected_state ) {
			throw new ToolError( 'The role, the administrators or your account changed after the rollback was assessed; nothing was restored.' );
		}
		$slug     = (string) $rollback['target']['id'];
		$restored = self::snapshot( $rollback );
		self::write( $slug, Site::role_caps( Site::roles(), $slug ), $restored );

		$outcome = new Outcome( array( 'role' => $slug ), Rollback::none() );
		return Changes::same( Site::role_caps( Site::roles(), $slug ), $restored ) ? $outcome : $outcome->with_warning( 'restored_differently', 'The role grants different capabilities than the snapshot.' );
	}

	/**
	 * The assessment of giving a role exactly `$wanted`, with every rail.
	 *
	 * @param string              $slug    Role slug.
	 * @param array<string, bool> $current Capabilities now.
	 * @param array<string, bool> $wanted     Capabilities after.
	 * @param string              $verb    `Change` or `Restore`.
	 * @return Assessment
	 * @throws ToolError When nothing would change.
	 */
	private static function assessment( string $slug, array $current, array $wanted, string $verb ): Assessment {
		if ( Changes::same( $current, $wanted ) ) {
			throw new ToolError( sprintf( 'Role %s already has those capabilities; nothing to change.', esc_textarea( $slug ) ) );
		}
		$roles  = Site::roles();
		$gained = array_values( array_diff( Privilege::granted( $wanted ), Privilege::granted( $current ) ) );
		$lost   = array_values( array_diff( Privilege::granted( $current ), Privilege::granted( $wanted ) ) );

		$core = array_values( array_intersect( $lost, Privilege::CORE_ADMINISTRATOR_CAPS ) );
		if ( 'administrator' === $slug && array() !== $core ) {
			return Assessment::prohibited( 'core_capability', sprintf( 'FlitAgent never removes core capabilities from the administrator role (%s).', implode( ', ', $core ) ) );
		}
		$refusal = Site::grant_refusal( $gained, $roles );
		if ( null === $refusal ) {
			$after                          = $roles;
			$after[ $slug ]['capabilities'] = $wanted;
			$refusal                        = Site::lockout( array(), $after );
		}
		if ( null !== $refusal ) {
			return $refusal;
		}

		$summary = sprintf( '%s the capabilities of role %s for every user with it: add %s; remove %s.', $verb, $slug, Site::listing( $gained ), Site::listing( $lost ) );
		$admin   = array_values( array_merge( Privilege::admin_caps( array_fill_keys( $gained, true ) ), Privilege::admin_caps( array_fill_keys( $lost, true ) ) ) );
		if ( array() !== $admin ) {
			$summary .= sprintf( ' Administrator-level capabilities involved: %s.', implode( ', ', $admin ) );
		}

		return Assessment::destructive(
			$summary,
			array(
				'role'    => $slug,
				'added'   => Site::listing( $gained ),
				'removed' => Site::listing( $lost ),
				'before'  => array( 'capabilities' => Site::listing( Privilege::granted( $current ) ) ),
				'after'   => array( 'capabilities' => Site::listing( Privilege::granted( $wanted ) ) ),
			),
			array(
				'fingerprint'    => Site::role_fingerprint( $slug ),
				'administrators' => Canonical::hash( Site::administrator_ids() ),
				'caller'         => Site::user_fingerprint( get_userdata( Site::caller_id() ) ),
			)
		);
	}

	/**
	 * Makes the role grant exactly `$wanted`.
	 *
	 * @param string              $slug    Role slug.
	 * @param array<string, bool> $current Capabilities now.
	 * @param array<string, bool> $wanted     Capabilities after.
	 * @throws ToolError When the role disappeared.
	 */
	private static function write( string $slug, array $current, array $wanted ): void {
		$role = get_role( $slug );
		if ( ! $role ) {
			throw new ToolError( sprintf( 'Role %s disappeared; nothing was changed.', esc_textarea( $slug ) ) );
		}
		foreach ( $wanted as $cap => $grant ) {
			if ( ! array_key_exists( $cap, $current ) || $current[ $cap ] !== $grant ) {
				$role->add_cap( $cap, $grant );
			}
		}
		foreach ( array_keys( array_diff_key( $current, $wanted ) ) as $cap ) {
			$role->remove_cap( $cap );
		}
	}

	/**
	 * Capabilities from a rollback reference.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return array<string, bool>
	 */
	private static function snapshot( array $rollback ): array {
		return Site::caps_from_json( isset( $rollback['before']['capabilities_json'] ) ? $rollback['before']['capabilities_json'] : '' );
	}
}
