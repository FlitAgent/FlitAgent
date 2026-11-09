<?php
/**
 * Write tool: send_password_reset.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Users;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `send_password_reset`: emails a user core's password reset link (retrieve_password()). The password itself is not
 * changed, and the key or link is never returned. Destructive: an email cannot be unsent, and earlier reset links stop
 * working.
 */
final class SendPasswordReset implements WriteTool {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'send_password_reset';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: send a password reset email',
			'Emails one user WordPress\'s password reset link, so they can choose a new password themselves. FlitAgent never sets, sees or returns passwords or reset links. Always needs an administrator\'s approval in wp-admin (destructive: the email cannot be unsent and earlier reset links stop working).',
			array(
				'user_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the user.',
				),
			),
			array( 'user_id' ),
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
		return array( Risk::DESTRUCTIVE );
	}

	/**
	 * Never auto-approved.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the reset email. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$user_id = (int) $args['user_id'];
		$user    = Site::target( $user_id, 'edit_user' );
		if ( $user instanceof Assessment ) {
			return $user;
		}
		$refusal = Site::outrank_refusal( $user );
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( '' === (string) $user->user_email ) {
			return Assessment::prohibited( 'no_email', sprintf( 'User %d has no email address to send a reset link to.', $user_id ) );
		}

		return Assessment::destructive(
			sprintf( 'Email user %d "%s" a password reset link at %s. Their password does not change until they use the link; earlier reset links stop working. The email cannot be unsent.', $user_id, $user->user_login, $user->user_email ),
			array(
				'user_id'    => $user_id,
				'user_login' => (string) $user->user_login,
				'email'      => (string) $user->user_email,
			),
			array( 'fingerprint' => Site::user_fingerprint( $user ) )
		);
	}

	/**
	 * Sends the email.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the user changed or WordPress could not send it.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$fresh = $this->assess( $args );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $assessment->preconditions() ) {
			throw new ToolError( 'The user changed before the email was sent; nothing was sent.' );
		}
		$user_id = (int) $args['user_id'];
		$user    = get_userdata( $user_id );
		$sent    = $user ? retrieve_password( wp_slash( (string) $user->user_login ) ) : false;
		if ( true !== $sent ) {
			throw new ToolError( sprintf( 'WordPress could not send the reset email (%s). Password resets may be turned off for this user or mail may be failing.', is_wp_error( $sent ) ? esc_textarea( $sent->get_error_code() ) : 'unknown' ) );
		}

		return new Outcome(
			array(
				'user_id'          => $user_id,
				'reset_email_sent' => true,
			),
			Rollback::none()
		);
	}

	/**
	 * Nothing to read back: the email left the site.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return $outcome;
	}
}
