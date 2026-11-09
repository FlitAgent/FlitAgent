<?php
/**
 * Write tool: set_user_meta.
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
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `set_user_meta`: sets one user meta key to a string value. Protected keys (roles, capabilities, user levels,
 * sessions, application passwords, two-factor data, email addresses, user-table fields, secret-looking names) are
 * refused in any case and with any prefix. Reversible: the previous values are snapshotted and restored by rollback.
 */
final class SetUserMeta implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'set_user_meta';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: set user meta',
			'Sets one user meta key of one user to a string value (creating it, or replacing its single value). Always needs an administrator\'s approval in wp-admin. Keys for roles, capabilities, user levels, sessions, application passwords, two-factor data, email addresses, user-table fields and secret-looking names are refused (use the dedicated tools). Roll back with rollback_change.',
			Meta::properties() + array(
				'value' => array(
					'type'        => 'string',
					'maxLength'   => 65535,
					'description' => 'New value, stored as a string.',
				),
			),
			array( 'user_id', 'key', 'value' ),
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
	 * Assesses the change. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the key already has that value.
	 */
	public function assess( array $args ): Assessment {
		$user_id = (int) $args['user_id'];
		$key     = (string) $args['key'];
		$value   = (string) $args['value'];
		$user    = Meta::target( $user_id, $key );
		if ( $user instanceof Assessment ) {
			return $user;
		}
		$current = Meta::values( $user_id, $key );
		if ( count( $current ) > 1 ) {
			return Assessment::prohibited( 'multiple_values', sprintf( 'User meta %s of user %d has %d values; setting one would replace all of them. Delete it with delete_user_meta first.', $key, $user_id, count( $current ) ) );
		}
		$refusal = Meta::unrestorable( $current, $key );
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( array( $value ) === $current ) {
			throw new ToolError( sprintf( 'User meta %s of user %d already has that value; nothing to change.', esc_textarea( $key ), (int) $user_id ) );
		}

		return Assessment::reversible(
			sprintf( 'Set user meta %s of user %d "%s".', $key, $user_id, $user->user_login ),
			array(
				'user_id' => $user_id,
				'key'     => $key,
				'before'  => array( 'value' => Meta::show_all( $current ) ),
				'after'   => array( 'value' => $value ),
			),
			array( 'fingerprint' => Meta::fingerprint( $user_id, $key ) ),
			false
		);
	}

	/**
	 * Writes the value.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the key changed since the assessment or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$fresh = $this->assess( $args );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $assessment->preconditions() ) {
			throw new ToolError( 'The user meta changed while the change was being applied; nothing was changed.' );
		}
		$user_id = (int) $args['user_id'];
		$key     = (string) $args['key'];
		$before  = Meta::values( $user_id, $key );
		if ( false === update_user_meta( $user_id, $key, wp_slash( (string) $args['value'] ) ) ) {
			throw new ToolError( sprintf( 'WordPress could not update user meta %s.', esc_textarea( $key ) ) );
		}

		return new Outcome(
			array(
				'user_id' => $user_id,
				'key'     => $key,
			),
			Meta::rollback_reference( $user_id, $key, $before ),
			array( 'fingerprint' => Meta::fingerprint( $user_id, $key ) )
		);
	}

	/**
	 * Reads the value back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		if ( array( (string) $args['value'] ) === Meta::values( (int) $args['user_id'], (string) $args['key'] ) ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result( array( 'stored_verbatim' => false ) )->with_warning( 'stored_differently', 'WordPress stored the value differently from the request (a filter may have changed it).' );
	}

	/**
	 * Assesses restoring the previous values. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		return Meta::assess_restore( $rollback );
	}

	/**
	 * Restores the previous values.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		return Meta::restore( $rollback, $expected_state );
	}
}
