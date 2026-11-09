<?php
/**
 * User meta snapshots for the user meta write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Users;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\Summary;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by set_user_meta and delete_user_meta: the key rail, reading every value of a key, and restoring a snapshot
 * of them (the rollback of both tools).
 */
final class Meta {

	/**
	 * The `user_id` and `key` input properties.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function properties(): array {
		return array(
			'user_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'ID of the user.',
			),
			'key'     => array(
				'type'        => 'string',
				'pattern'     => Privilege::META_KEY_PATTERN,
				'description' => 'Meta key (ASCII letters, digits and _ . : -). Roles, capabilities, user levels, sessions, application passwords, two-factor data, email addresses, user-table fields and secret-looking keys are refused.',
			),
		);
	}

	/**
	 * The user, or a refusal: missing or not editable (same message), or a protected key.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 * @return object|Assessment
	 */
	public static function target( int $user_id, string $key ) {
		$user = Site::target( $user_id, 'edit_user' );
		if ( $user instanceof Assessment ) {
			return $user;
		}
		if ( 1 !== preg_match( '/' . Privilege::META_KEY_PATTERN . '/D', $key ) || Privilege::is_protected_meta_key( $key ) ) {
			return Assessment::prohibited( 'protected_key', sprintf( 'User meta key %s is protected (roles and capabilities, user levels, sessions, application passwords, two-factor data, email addresses, user-table fields or secret-looking keys); FlitAgent does not change it.', $key ) );
		}
		return $user;
	}

	/**
	 * Every stored value of the key, in order.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 * @return array<int, mixed>
	 */
	public static function values( int $user_id, string $key ): array {
		$values = get_user_meta( $user_id, $key, false );
		return is_array( $values ) ? array_values( $values ) : array();
	}

	/**
	 * Refusal when the values would not come back exactly from the snapshot (it is stored as order-preserving JSON, so
	 * PHP objects cannot be restored).
	 *
	 * @param array<int, mixed> $values Values.
	 * @param string            $key    Meta key.
	 * @return Assessment|null
	 */
	public static function unrestorable( array $values, string $key ): ?Assessment {
		if ( self::decode( Canonical::encode( $values ) ) === $values ) {
			return null;
		}
		return Assessment::prohibited( 'not_restorable', sprintf( 'User meta %s holds a PHP object or other data FlitAgent cannot snapshot and restore exactly.', $key ) );
	}

	/**
	 * A value as preview text: strings as they are, anything else as JSON.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function show( $value ): string {
		return is_string( $value ) ? $value : Canonical::json( $value );
	}

	/**
	 * Values as preview text: one value as it is, several as a JSON list, none as "(not set)".
	 *
	 * @param array<int, mixed> $values Values.
	 * @return string
	 */
	public static function show_all( array $values ): string {
		if ( array() === $values ) {
			return '(not set)';
		}
		return 1 === count( $values ) ? self::show( $values[0] ) : Canonical::json( $values );
	}

	/**
	 * Fingerprint of the key's values.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 * @return string
	 */
	public static function fingerprint( int $user_id, string $key ): string {
		return Canonical::hash( self::values( $user_id, $key ) );
	}

	/**
	 * Rollback reference holding every value before the write.
	 *
	 * @param int               $user_id User ID.
	 * @param string            $key     Meta key.
	 * @param array<int, mixed> $before  Values before the write.
	 * @return array<string, mixed>
	 */
	public static function rollback_reference( int $user_id, string $key, array $before ): array {
		return Rollback::make( Rollback::SNAPSHOT, 'user_meta', $user_id, array( 'key' => $key ), array( 'values_json' => Canonical::encode( $before ) ) );
	}

	/**
	 * Assesses restoring the snapshot. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 * @throws ToolError When the key already holds the snapshot.
	 */
	public static function assess_restore( array $rollback ): Assessment {
		$user_id = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$key     = isset( $rollback['refs']['key'] ) ? (string) $rollback['refs']['key'] : '';
		$user    = self::target( $user_id, $key );
		if ( $user instanceof Assessment ) {
			return $user;
		}
		$current  = self::values( $user_id, $key );
		$restored = self::snapshot( $rollback );
		if ( $current === $restored ) {
			throw new ToolError( sprintf( 'User meta %s of user %d already holds the values from before the change; nothing to restore.', esc_textarea( $key ), (int) $user_id ) );
		}

		return Assessment::reversible(
			sprintf( 'Restore user meta %s of user %d "%s" (%s).', $key, $user_id, $user->user_login, Summary::count( count( $restored ), 'value', 'values' ) ),
			array(
				'user_id'  => $user_id,
				'key'      => $key,
				'current'  => array( 'value' => self::show_all( $current ) ),
				'restored' => array( 'value' => self::show_all( $restored ) ),
			),
			array( 'fingerprint' => Canonical::hash( $current ) ),
			false
		);
	}

	/**
	 * Restores the snapshot, if the key is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the key changed.
	 */
	public static function restore( array $rollback, array $expected_state ): Outcome {
		$fresh = self::assess_restore( $rollback );
		if ( $fresh->is_refused() || $fresh->preconditions() !== $expected_state ) {
			throw new ToolError( 'The user meta changed after the rollback was assessed; nothing was restored.' );
		}
		$user_id  = (int) $rollback['target']['id'];
		$key      = (string) $rollback['refs']['key'];
		$restored = self::snapshot( $rollback );

		delete_user_meta( $user_id, $key );
		foreach ( $restored as $value ) {
			add_user_meta( $user_id, $key, wp_slash( $value ) );
		}

		$outcome = new Outcome(
			array(
				'user_id'         => $user_id,
				'key'             => $key,
				'values_restored' => count( $restored ),
			),
			Rollback::none()
		);
		return self::values( $user_id, $key ) === $restored ? $outcome : $outcome->with_warning( 'restored_differently', 'WordPress stored the restored values differently from the snapshot.' );
	}

	/**
	 * Values from a rollback reference.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return array<int, mixed>
	 */
	private static function snapshot( array $rollback ): array {
		return isset( $rollback['before']['values_json'] ) ? array_values( self::decode( $rollback['before']['values_json'] ) ) : array();
	}

	/**
	 * Decodes a JSON snapshot (receipts store it as a string, so key order survives the receipt's canonical JSON).
	 *
	 * @param mixed $json JSON.
	 * @return array<int|string, mixed>
	 */
	private static function decode( $json ): array {
		$values = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $values ) ? $values : array();
	}
}
