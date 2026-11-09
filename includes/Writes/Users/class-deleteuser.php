<?php
/**
 * Write tool: delete_user.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Users;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `delete_user`: deletes a user and reassigns their posts (and links) to another user, through wp_delete_user().
 * Always destructive, with no rollback. Refuses the caller and the last administrator. The preview shows how many
 * posts of each type move.
 */
final class DeleteUser implements WriteTool {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_user';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete a user',
			'Deletes one user permanently and gives all their posts to the reassign user (posts are never deleted). Always needs an administrator\'s approval in wp-admin and cannot be rolled back; the approval screen shows their post counts by type. Refused for your own account and for the last administrator.',
			array(
				'user_id'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the user to delete.',
				),
				'reassign' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the existing user who receives the deleted user\'s posts.',
				),
			),
			array( 'user_id', 'reassign' ),
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
	 * @throws ToolError When the reassign user is the same or missing.
	 */
	public function assess( array $args ): Assessment {
		$user_id  = (int) $args['user_id'];
		$reassign = (int) $args['reassign'];
		$user     = Site::target( $user_id, 'delete_user' );
		if ( $user instanceof Assessment ) {
			return $user;
		}
		if ( $reassign === $user_id ) {
			throw new ToolError( 'reassign must be another user than the one being deleted.' );
		}
		$heir = get_userdata( $reassign );
		if ( ! $heir ) {
			throw new ToolError( sprintf( 'The reassign user %d was not found.', (int) $reassign ) );
		}
		if ( Site::caller_id() === $user_id ) {
			return Assessment::prohibited( 'caller', 'You cannot delete your own account.' );
		}
		$refusal = Site::lockout( array(), null, array( $user_id ) ) ?? Site::outrank_refusal( $user );
		if ( null !== $refusal ) {
			return $refusal;
		}

		$counts = Site::post_counts( $user_id );
		$roles  = Privilege::roles_of( Site::caps_of( $user ), Site::roles() );
		return Assessment::destructive(
			sprintf( 'Delete user %d "%s" (%s, role %s) permanently and give their %d posts to user %d "%s". This cannot be rolled back.', $user_id, $user->user_login, $user->user_email, Site::listing( $roles ), array_sum( $counts ), $reassign, $heir->user_login ),
			array(
				'user_id'     => $user_id,
				'before'      => array(
					'user_login' => (string) $user->user_login,
					'email'      => (string) $user->user_email,
					'roles'      => Site::listing( $roles ),
				),
				'after'       => array(
					'user_login' => '(deleted)',
					'posts'      => sprintf( 'reassigned to user %d "%s"', $reassign, $heir->user_login ),
				),
				'post_counts' => array_map( 'strval', $counts ),
			),
			array(
				'fingerprint'    => Site::user_fingerprint( $user ),
				'reassign'       => Site::user_fingerprint( $heir ),
				'posts'          => $counts,
				'administrators' => Canonical::hash( Site::administrator_ids() ),
			)
		);
	}

	/**
	 * Deletes the user.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When anything changed since the assessment or WordPress could not delete the user.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$fresh = $this->assess( $args );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $assessment->preconditions() ) {
			throw new ToolError( 'The user, their posts or the administrators changed while the deletion was being applied; nothing was deleted.' );
		}
		$user_id  = (int) $args['user_id'];
		$reassign = (int) $args['reassign'];
		$counts   = Site::post_counts( $user_id );
		Site::load_admin_api();
		if ( ! wp_delete_user( $user_id, $reassign ) ) {
			throw new ToolError( sprintf( 'WordPress could not delete user %d.', (int) $user_id ) );
		}

		return new Outcome(
			array(
				'deleted_user_id' => $user_id,
				'reassigned_to'   => $reassign,
				'posts_moved'     => array_sum( $counts ),
			),
			Rollback::none()
		);
	}

	/**
	 * Checks the user is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return get_userdata( (int) $args['user_id'] ) ? $outcome->with_verification_failure( 'The user still exists after the deletion.' ) : $outcome;
	}
}
