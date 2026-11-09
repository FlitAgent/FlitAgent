<?php
/**
 * Write tool: create_user.
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
 * `create_user`: creates an account with a role the caller may assign. It never takes a password: WordPress gets a
 * random one that is never returned or stored by FlitAgent, and core emails the user a link to set their own. Reversible
 * for a role without administrator-equivalent capabilities, destructive for one with any. Rollback deletes the user,
 * only while they own no posts.
 */
final class CreateUser implements WriteTool, Reversible {

	/**
	 * Optional name arguments (same names as wp_insert_user() fields).
	 */
	const NAMES = array( 'first_name', 'last_name', 'display_name' );

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'create_user';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: create a user',
			'Creates a WordPress user with a role you may assign. There is no password argument: WordPress emails the new user a link to set their own password. Always needs an administrator\'s approval in wp-admin; a role with administrator-level capabilities (manage_options, edit_users, plugin or theme management, unfiltered_html, ...) is a destructive change. Roll back with rollback_change to delete the user while they own no posts.',
			array(
				'user_login'   => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 60,
					'description' => 'Login name: letters, numbers, spaces and . - _ @. Cannot be changed later.',
				),
				'email'        => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => 'Email address; the set-password link is sent here.',
				),
				'role'         => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_-]{1,64}$',
					'description' => 'Role slug, e.g. editor (see get_roles).',
				),
				'first_name'   => array(
					'type'      => 'string',
					'maxLength' => 250,
				),
				'last_name'    => array(
					'type'      => 'string',
					'maxLength' => 250,
				),
				'display_name' => array(
					'type'      => 'string',
					'maxLength' => 250,
				),
			),
			array( 'user_login', 'email', 'role' ),
			$this->tiers(),
			true
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
	 * Assesses the new user. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the login or email is invalid or taken.
	 */
	public function assess( array $args ): Assessment {
		if ( ! current_user_can( 'create_users' ) ) {
			return Assessment::prohibited( 'forbidden', 'You cannot create users.' );
		}

		$login = (string) $args['user_login'];
		if ( ! validate_username( $login ) || sanitize_user( $login, true ) !== $login || trim( $login ) !== $login ) {
			throw new ToolError( 'user_login may only contain letters, numbers, spaces and . - _ @, without leading or trailing spaces.' );
		}
		if ( username_exists( $login ) ) {
			throw new ToolError( sprintf( 'The login "%s" is already taken.', esc_textarea( $login ) ) );
		}
		$email = (string) $args['email'];
		if ( is_email( $email ) !== $email ) {
			throw new ToolError( sprintf( '"%s" is not a valid email address.', esc_textarea( $email ) ) );
		}
		if ( email_exists( $email ) ) {
			throw new ToolError( 'That email address is already used by another account.' );
		}

		$role  = (string) $args['role'];
		$roles = Site::roles();
		if ( ! isset( $roles[ $role ] ) || ! Site::is_editable_role( $role ) ) {
			return Assessment::prohibited( 'role_not_assignable', sprintf( 'Role %s does not exist or you cannot assign it.', $role ) );
		}
		$role_caps = isset( $roles[ $role ]['capabilities'] ) ? (array) $roles[ $role ]['capabilities'] : array();
		$refusal   = Site::grant_refusal( Privilege::granted( $role_caps ), $roles );
		if ( null !== $refusal ) {
			return $refusal;
		}

		$admin_caps = Privilege::admin_caps( $role_caps );
		$after      = array(
			'user_login' => $login,
			'email'      => $email,
			'role'       => $role,
		);
		foreach ( self::NAMES as $name ) {
			if ( isset( $args[ $name ] ) ) {
				$after[ $name ] = (string) $args[ $name ];
			}
		}
		if ( array() !== $admin_caps ) {
			$after['administrator_capabilities'] = implode( ', ', $admin_caps );
		}

		$summary       = sprintf( 'Create user "%s" <%s> with role %s; WordPress emails them a link to set their password.', $login, $email, $role );
		$preview       = array(
			'before' => array(),
			'after'  => $after,
		);
		$preconditions = array(
			'role'   => Site::role_fingerprint( $role ),
			'caller' => Site::user_fingerprint( get_userdata( Site::caller_id() ) ),
		);

		if ( array() !== $admin_caps ) {
			return Assessment::destructive( $summary . sprintf( ' The role has administrator-level capabilities: %s.', implode( ', ', $admin_caps ) ), $preview, $preconditions );
		}
		return Assessment::reversible( $summary, $preview, $preconditions, false );
	}

	/**
	 * Creates the user with a random password, then sends core's set-password email.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the site changed since the assessment or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$fresh = $this->assess( $args );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $assessment->preconditions() ) {
			throw new ToolError( 'The role or your account changed while the user was being created; nothing was changed.' );
		}

		$userdata = array(
			'user_login' => (string) $args['user_login'],
			'user_email' => (string) $args['email'],
			'role'       => (string) $args['role'],
			'user_pass'  => wp_generate_password( 32, true, true ),
		);
		foreach ( self::NAMES as $name ) {
			if ( isset( $args[ $name ] ) ) {
				$userdata[ $name ] = (string) $args[ $name ];
			}
		}

		$user_id = wp_insert_user( wp_slash( $userdata ) );
		if ( is_wp_error( $user_id ) || ! $user_id ) {
			throw new ToolError( sprintf( 'WordPress could not create the user (%s).', is_wp_error( $user_id ) ? esc_textarea( $user_id->get_error_code() ) : 'unknown' ) );
		}
		$user_id = (int) $user_id;

		// wp_new_user_notification() returns nothing; wp_mail() reports a failed send through this action.
		$sent     = true;
		$listener = function () use ( &$sent ) {
			$sent = false;
		};
		add_action( 'wp_mail_failed', $listener );
		wp_new_user_notification( $user_id, null, 'user' );
		remove_action( 'wp_mail_failed', $listener );

		$user    = get_userdata( $user_id );
		$outcome = new Outcome(
			array(
				'user_id'                 => $user_id,
				'user_login'              => (string) $args['user_login'],
				'role'                    => (string) $args['role'],
				'set_password_email_sent' => $sent,
			),
			Rollback::make( Rollback::SNAPSHOT, 'user', $user_id, array( 'user_login' => (string) $args['user_login'] ) ),
			array( 'fingerprint' => $user ? Site::user_fingerprint( $user ) : '' )
		);

		return $sent ? $outcome : $outcome->with_warning( 'email_not_sent', 'The user was created, but WordPress could not send the set-password email. Send it again with send_password_reset once mail works.' );
	}

	/**
	 * Reads the user back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$user = get_userdata( (int) $outcome->result()['user_id'] );
		if ( ! $user ) {
			return $outcome->with_verification_failure( 'The new user could not be read back.' );
		}
		$roles = Privilege::roles_of( Site::caps_of( $user ), Site::roles() );
		if ( (string) $user->user_login !== (string) $args['user_login'] || (string) $user->user_email !== (string) $args['email'] || array( (string) $args['role'] ) !== $roles ) {
			return $outcome->with_warning( 'stored_differently', 'WordPress stored the login, email or role differently from the request. Read the user to see what was saved.' );
		}
		return $outcome;
	}

	/**
	 * Assesses deleting the created user. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$user_id = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$user    = Site::target( $user_id, 'delete_user' );
		if ( $user instanceof Assessment ) {
			return $user;
		}
		if ( ! isset( $rollback['refs']['user_login'] ) || (string) $user->user_login !== (string) $rollback['refs']['user_login'] ) {
			return Assessment::prohibited( 'not_found', sprintf( 'User %d is no longer the user this change created.', $user_id ) );
		}
		if ( Site::caller_id() === $user_id ) {
			return Assessment::prohibited( 'caller', 'You cannot delete your own account.' );
		}
		$posts = array_sum( Site::post_counts( $user_id ) );
		if ( $posts > 0 ) {
			return Assessment::prohibited( 'owns_content', sprintf( 'User %d now owns %d posts, so rolling back would delete them. Use delete_user with a reassign target instead.', $user_id, $posts ) );
		}
		$refusal = Site::lockout( array(), null, array( $user_id ) ) ?? Site::outrank_refusal( $user );
		if ( null !== $refusal ) {
			return $refusal;
		}

		return Assessment::destructive(
			sprintf( 'Delete user %d "%s", created by this change. They own no posts.', $user_id, $user->user_login ),
			array(
				'current'  => array(
					'user_login' => (string) $user->user_login,
					'email'      => (string) $user->user_email,
					'roles'      => Site::listing( Privilege::roles_of( Site::caps_of( $user ), Site::roles() ) ),
				),
				'restored' => array(
					'user_login' => '(deleted)',
				),
			),
			array(
				'fingerprint'    => Site::user_fingerprint( $user ),
				'administrators' => Canonical::hash( Site::administrator_ids() ),
			)
		);
	}

	/**
	 * Deletes the created user, if nothing changed since the rollback was assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the user changed or WordPress could not delete them.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$fresh = $this->assess_rollback( $rollback );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $expected_state ) {
			throw new ToolError( 'The user changed after the rollback was assessed; nothing was deleted.' );
		}
		$user_id = (int) $rollback['target']['id'];
		Site::load_admin_api();
		if ( ! wp_delete_user( $user_id ) ) {
			throw new ToolError( sprintf( 'WordPress could not delete user %d.', (int) $user_id ) );
		}

		$outcome = new Outcome( array( 'deleted_user_id' => $user_id ), Rollback::none() );
		return get_userdata( $user_id ) ? $outcome->with_verification_failure( 'The user still exists after the rollback.' ) : $outcome;
	}
}
