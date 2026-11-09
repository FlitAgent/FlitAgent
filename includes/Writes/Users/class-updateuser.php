<?php
/**
 * Write tool: update_user.
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
 * `update_user`: changes a user's display name, first/last name, nickname, description, URL or email. Never the
 * password, login, role or capabilities. Profile changes are reversible (snapshot); an email change is destructive,
 * because whoever controls the address can reset the password. Never auto-approved.
 */
final class UpdateUser implements WriteTool, Reversible {

	/**
	 * Argument => user field.
	 */
	const FIELDS = array(
		'display_name' => 'display_name',
		'first_name'   => 'first_name',
		'last_name'    => 'last_name',
		'nickname'     => 'nickname',
		'description'  => 'description',
		'url'          => 'user_url',
		'email'        => 'user_email',
	);

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'update_user';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: update a user profile',
			'Changes the display name, first or last name, nickname, biographical info, website URL and/or email address of one user. It cannot change passwords, logins, roles or capabilities (use send_password_reset, set_user_role, set_user_capabilities). Always needs an administrator\'s approval in wp-admin; an email change is a destructive change (the new address can reset the password, and WordPress notifies the old one). Roll back with rollback_change.',
			array(
				'user_id'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the user to change.',
				),
				'display_name' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 250,
				),
				'first_name'   => array(
					'type'      => 'string',
					'maxLength' => 250,
				),
				'last_name'    => array(
					'type'      => 'string',
					'maxLength' => 250,
				),
				'nickname'     => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 250,
				),
				'description'  => array(
					'type'        => 'string',
					'maxLength'   => 10000,
					'description' => 'Biographical info.',
				),
				'url'          => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'pattern'     => '^(https?://[^\s<>"\']+)?$',
					'description' => 'Website (http or https), or "" to clear it.',
				),
				'email'        => array(
					'type'      => 'string',
					'maxLength' => 100,
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
		return array( Risk::REVERSIBLE, Risk::DESTRUCTIVE );
	}

	/**
	 * Never auto-approved.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the update. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When nothing is given or would change, or the email is invalid or taken.
	 */
	public function assess( array $args ): Assessment {
		$changes = self::changes( $args );
		if ( array() === $changes ) {
			throw new ToolError( 'Give at least one of ' . esc_textarea( implode( ', ', array_keys( self::FIELDS ) ) ) . '.' );
		}
		$user_id = (int) $args['user_id'];
		$user    = Site::target( $user_id, 'edit_user' );
		if ( $user instanceof Assessment ) {
			return $user;
		}
		if ( isset( $changes['user_email'] ) ) {
			self::check_email( $changes['user_email'], $user_id );
		}

		return $this->assessment( $user, self::values( $user, array_keys( $changes ) ), $changes, 'Update' );
	}

	/**
	 * Updates the user.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the user changed since the assessment or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$fresh = $this->assess( $args );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $assessment->preconditions() ) {
			throw new ToolError( 'The user changed while the update was being applied; nothing was changed.' );
		}
		$user_id = (int) $args['user_id'];
		$changes = self::changes( $args );
		$before  = self::values( get_userdata( $user_id ), array_keys( $changes ) );
		self::write( $user_id, $changes );

		return new Outcome(
			array(
				'user_id' => $user_id,
				'changed' => array_keys( self::arguments( $changes ) ),
			),
			Rollback::make( Rollback::SNAPSHOT, 'user', $user_id, array( 'fields' => array_keys( $changes ) ), $before ),
			array( 'fingerprint' => self::fingerprint( $user_id ) )
		);
	}

	/**
	 * Reads the user back and compares the stored fields with the request.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$user = get_userdata( (int) $args['user_id'] );
		if ( ! $user ) {
			return $outcome->with_verification_failure( 'The user could not be read back after the update.' );
		}
		$differs = array();
		foreach ( self::changes( $args ) as $field => $value ) {
			if ( (string) $user->$field !== $value ) {
				$differs[] = (string) array_search( $field, self::FIELDS, true );
			}
		}
		if ( array() === $differs ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result(
			array(
				'stored_verbatim' => false,
				'differs'         => $differs,
			)
		)->with_warning( 'stored_differently', sprintf( 'WordPress stored the %s differently from the request (it sanitizes profile fields). Read the user to see what was saved.', implode( ', ', $differs ) ) );
	}

	/**
	 * Assesses restoring the fields from the snapshot. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$user_id = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$user    = Site::target( $user_id, 'edit_user' );
		if ( $user instanceof Assessment ) {
			return $user;
		}
		$restored = self::snapshot( $rollback );
		if ( array() === $restored ) {
			return Assessment::prohibited( 'no_rollback', 'This change has nothing to restore.' );
		}
		if ( isset( $restored['user_email'] ) ) {
			$owner = email_exists( $restored['user_email'] );
			if ( $owner && (int) $owner !== $user_id ) {
				return Assessment::prohibited( 'email_taken', 'The previous email address now belongs to another account, so it cannot be restored.' );
			}
		}
		return $this->assessment( $user, self::values( $user, array_keys( $restored ) ), $restored, 'Restore' );
	}

	/**
	 * Restores the fields, if the user is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the user changed or WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$fresh = $this->assess_rollback( $rollback );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $expected_state ) {
			throw new ToolError( 'The user changed after the rollback was assessed; nothing was restored.' );
		}
		$user_id  = (int) $rollback['target']['id'];
		$restored = self::snapshot( $rollback );
		self::write( $user_id, $restored );

		$outcome = new Outcome(
			array(
				'user_id'  => $user_id,
				'restored' => array_keys( self::arguments( $restored ) ),
			),
			Rollback::none()
		);
		$after   = self::values( get_userdata( $user_id ), array_keys( $restored ) );
		return $after === $restored ? $outcome : $outcome->with_warning( 'restored_differently', 'WordPress stored the restored fields differently from the snapshot.' );
	}

	/**
	 * The assessment of setting `$after` on a user: destructive when the email changes.
	 *
	 * @param object                $user   User.
	 * @param array<string, string> $before Current values by field.
	 * @param array<string, string> $after  New values by field.
	 * @param string                $verb   `Update` or `Restore`.
	 * @return Assessment
	 * @throws ToolError When nothing would change.
	 */
	private function assessment( $user, array $before, array $after, string $verb ): Assessment {
		if ( $before === $after ) {
			throw new ToolError( sprintf( 'User %d already has those values; nothing to change.', (int) $user->ID ) );
		}
		$summary       = sprintf( '%s the %s of user %d "%s".', $verb, implode( ', ', array_keys( self::arguments( $after ) ) ), $user->ID, $user->user_login );
		$preview       = array(
			'user_id'    => (int) $user->ID,
			'user_login' => (string) $user->user_login,
			'before'     => self::arguments( $before ),
			'after'      => self::arguments( $after ),
		);
		$preconditions = array( 'fingerprint' => Site::user_fingerprint( $user ) );

		if ( isset( $after['user_email'] ) && $after['user_email'] !== $before['user_email'] ) {
			$refusal = Site::outrank_refusal( $user );
			if ( null !== $refusal ) {
				return $refusal;
			}
			$summary .= sprintf( ' The email changes from %s to %s: password resets and notifications will go to the new address, and WordPress emails the old one about the change.', $before['user_email'], $after['user_email'] );
			return Assessment::destructive( $summary, $preview, $preconditions );
		}
		return Assessment::reversible( $summary, $preview, $preconditions, false );
	}

	/**
	 * Refuses an invalid email or one used by another account.
	 *
	 * @param string $email   Email.
	 * @param int    $user_id User being changed.
	 * @throws ToolError When invalid or taken.
	 */
	private static function check_email( string $email, int $user_id ): void {
		if ( is_email( $email ) !== $email ) {
			throw new ToolError( sprintf( '"%s" is not a valid email address.', esc_textarea( $email ) ) );
		}
		$owner = email_exists( $email );
		if ( $owner && (int) $owner !== $user_id ) {
			throw new ToolError( 'That email address is already used by another account.' );
		}
	}

	/**
	 * Writes fields through wp_update_user() (which expects slashed data).
	 *
	 * @param int                   $user_id User ID.
	 * @param array<string, string> $fields  Field => value.
	 * @throws ToolError When WordPress refused.
	 */
	private static function write( int $user_id, array $fields ): void {
		$updated = wp_update_user( wp_slash( array( 'ID' => $user_id ) + $fields ) );
		if ( is_wp_error( $updated ) || ! $updated ) {
			throw new ToolError( sprintf( 'WordPress could not update user %d (%s).', (int) $user_id, is_wp_error( $updated ) ? esc_textarea( $updated->get_error_code() ) : 'unknown' ) );
		}
	}

	/**
	 * Requested fields: user field => value, in FIELDS order.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, string>
	 */
	private static function changes( array $args ): array {
		$changes = array();
		foreach ( self::FIELDS as $arg => $field ) {
			if ( array_key_exists( $arg, $args ) ) {
				$changes[ $field ] = (string) $args[ $arg ];
			}
		}
		return $changes;
	}

	/**
	 * Snapshot fields from a rollback reference (only FIELDS are ever restored).
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return array<string, string>
	 */
	private static function snapshot( array $rollback ): array {
		$before   = isset( $rollback['before'] ) && is_array( $rollback['before'] ) ? $rollback['before'] : array();
		$restored = array();
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $before ) && is_scalar( $before[ $field ] ) ) {
				$restored[ $field ] = (string) $before[ $field ];
			}
		}
		return $restored;
	}

	/**
	 * Current values of fields.
	 *
	 * @param object|false $user   User.
	 * @param string[]     $fields Fields.
	 * @return array<string, string>
	 */
	private static function values( $user, array $fields ): array {
		$values = array();
		foreach ( $fields as $field ) {
			$values[ $field ] = $user && isset( $user->$field ) ? (string) $user->$field : '';
		}
		return $values;
	}

	/**
	 * Field => value keyed by argument names, for previews and results.
	 *
	 * @param array<string, string> $fields Field => value.
	 * @return array<string, string>
	 */
	private static function arguments( array $fields ): array {
		$named = array();
		foreach ( $fields as $field => $value ) {
			$named[ (string) array_search( $field, self::FIELDS, true ) ] = $value;
		}
		return $named;
	}

	/**
	 * Fingerprint of the user now.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function fingerprint( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? Site::user_fingerprint( $user ) : '';
	}
}
