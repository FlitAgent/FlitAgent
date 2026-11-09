<?php
/**
 * Write tool: create_role.
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
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `create_role`: adds a role (add_role()) granting capabilities the caller holds. Always destructive. Refuses role
 * names as capabilities, capabilities the caller lacks, and a slug named like a capability (users holding that
 * capability would be treated as having the role). Rollback removes the role while no user has it.
 */
final class CreateRole implements WriteTool, Reversible {

	/**
	 * Characters a role name may not contain: WordPress prints role names unescaped in some screens.
	 */
	const UNSAFE_NAME = '/[<>&"\'\\\\\x00-\x1F\x7F]/';

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'create_role';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: create a role',
			'Adds a user role that grants the listed capabilities (each one you hold yourself). Always needs an administrator\'s approval in wp-admin (destructive). Roll back with rollback_change to remove the role while no user has it.',
			array(
				'role'         => array(
					'type'        => 'string',
					'pattern'     => '^[a-z0-9_-]{1,64}$',
					'description' => 'New role slug: lower-case letters, digits, _ and -.',
				),
				'name'         => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 100,
					'description' => 'Display name.',
				),
				'capabilities' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Capabilities the role grants, e.g. ["read", "edit_posts"].',
				),
			),
			array( 'role', 'name', 'capabilities' ),
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
	 * Assesses the new role. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the role exists, or the name or a capability is invalid.
	 */
	public function assess( array $args ): Assessment {
		$refusal = Site::role_manager_refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$slug = (string) $args['role'];
		$name = (string) $args['name'];
		$caps = Site::cap_names( $args['capabilities'], 'capabilities' );
		if ( 1 === preg_match( self::UNSAFE_NAME, $name ) || trim( $name ) !== $name ) {
			throw new ToolError( 'name may not contain < > & " \' \\ or control characters, or start or end with a space.' );
		}
		$roles = Site::roles();
		if ( isset( $roles[ $slug ] ) ) {
			throw new ToolError( sprintf( 'Role %s already exists; use set_role_capabilities to change it.', esc_textarea( $slug ) ) );
		}
		if ( in_array( $slug, self::known_caps( $roles ), true ) ) {
			return Assessment::prohibited( 'reserved_name', sprintf( 'A role named %s would share its name with a capability, and WordPress would treat users holding that capability as having the role. Choose another slug.', $slug ) );
		}
		$refusal = Site::grant_refusal( $caps, $roles );
		if ( null !== $refusal ) {
			return $refusal;
		}

		sort( $caps, SORT_STRING );
		$admin_caps = Privilege::admin_caps( array_fill_keys( $caps, true ) );
		$summary    = sprintf( 'Create role %s "%s" granting %s.', $slug, $name, Site::listing( $caps ) );
		if ( array() !== $admin_caps ) {
			$summary .= sprintf( ' It has administrator-level capabilities: %s.', implode( ', ', $admin_caps ) );
		}

		return Assessment::destructive(
			$summary,
			array(
				'role'   => $slug,
				'before' => array(),
				'after'  => array(
					'name'                       => $name,
					'capabilities'               => Site::listing( $caps ),
					'administrator_capabilities' => Site::listing( $admin_caps ),
				),
			),
			array(
				'role'   => Site::role_fingerprint( $slug ),
				'caller' => Site::user_fingerprint( get_userdata( Site::caller_id() ) ),
			)
		);
	}

	/**
	 * Adds the role.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When anything changed since the assessment or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$fresh = $this->assess( $args );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $assessment->preconditions() ) {
			throw new ToolError( 'The roles or your account changed while the role was being created; nothing was changed.' );
		}
		$slug = (string) $args['role'];
		$caps = Site::cap_names( $args['capabilities'], 'capabilities' );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- the null return marks failure; wpcom_vip_add_role() exists only on VIP and returns nothing.
		if ( null === add_role( $slug, (string) $args['name'], array_fill_keys( $caps, true ) ) ) {
			throw new ToolError( sprintf( 'WordPress could not add role %s.', esc_textarea( $slug ) ) );
		}

		return new Outcome(
			array(
				'role'         => $slug,
				'capabilities' => $caps,
			),
			Rollback::make( Rollback::SNAPSHOT, 'role', $slug, array( 'created' => true ) ),
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
		$expected = Site::cap_names( $args['capabilities'], 'capabilities' );
		sort( $expected, SORT_STRING );
		return Privilege::granted( $caps ) === $expected ? $outcome : $outcome->with_warning( 'stored_differently', 'The role grants different capabilities than requested (a plugin may have changed it).' );
	}

	/**
	 * Assesses removing the created role. Read-only.
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
		$roles = Site::roles();
		$caps  = Site::role_caps( $roles, $slug );
		if ( null === $caps ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Role %s was not found.', $slug ) );
		}
		$refusal = Site::role_removal_refusal( $slug );
		if ( null !== $refusal ) {
			return $refusal;
		}

		return Assessment::destructive(
			sprintf( 'Remove role %s, created by this change. No user has it.', $slug ),
			array(
				'role'     => $slug,
				'current'  => array(
					'name'         => (string) $roles[ $slug ]['name'],
					'capabilities' => Site::listing( Privilege::granted( $caps ) ),
				),
				'restored' => array( 'name' => '(deleted)' ),
			),
			array( 'fingerprint' => Site::role_fingerprint( $slug ) )
		);
	}

	/**
	 * Removes the role, if nothing changed since the rollback was assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the role changed.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$fresh = $this->assess_rollback( $rollback );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $expected_state ) {
			throw new ToolError( 'The role changed after the rollback was assessed; nothing was removed.' );
		}
		$slug = (string) $rollback['target']['id'];
		remove_role( $slug );

		$outcome = new Outcome( array( 'removed_role' => $slug ), Rollback::none() );
		return null === Site::role_caps( Site::roles(), $slug ) ? $outcome : $outcome->with_verification_failure( 'The role still exists after the rollback.' );
	}

	/**
	 * Capability names a new role may not take: every capability any role grants or denies, core's administrator
	 * capabilities, the administrator-equivalent ones, and core's special ones.
	 *
	 * @param array<string, array<string, mixed>> $roles Roles.
	 * @return string[]
	 */
	private static function known_caps( array $roles ): array {
		$known = array_merge( Privilege::CORE_ADMINISTRATOR_CAPS, Privilege::ADMIN_CAPS, array( 'exist', 'do_not_allow' ) );
		foreach ( $roles as $role ) {
			$known = array_merge( $known, array_map( 'strval', array_keys( (array) $role['capabilities'] ) ) );
		}
		return $known;
	}
}
