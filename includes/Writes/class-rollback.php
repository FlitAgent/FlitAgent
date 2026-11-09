<?php
/**
 * Rollback references stored in receipts.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * The one shape every write tool uses to say how its write can be undone.
 *
 * Restorable, from make():
 *
 *     array(
 *         'type'   => 'revision' | 'trash' | 'snapshot' | 'regen',
 *         'target' => array( 'kind' => 'post', 'id' => 12 ),   // what was written
 *         'refs'   => array( 'revision_id' => 40 ),           // references the tool needs (ids, names)
 *         'before' => array( 'value' => '...' ),              // raw before-values for a snapshot, if any
 *     )
 *
 * No rollback, from none(): `array( 'type' => 'none' )`. The Gateway adds `after` (the post-write fingerprint) when it
 * stores the receipt. The whole reference is kept in the receipt for the tool's rollback(); MCP only ever sees
 * public_view().
 */
final class Rollback {

	/**
	 * Restored from a WordPress revision.
	 */
	const REVISION = 'revision';

	/**
	 * Restored from the trash.
	 */
	const TRASH = 'trash';

	/**
	 * Restored from before-values FlitAgent stored in the receipt.
	 */
	const SNAPSHOT = 'snapshot';

	/**
	 * Derived data that regenerates; nothing to restore.
	 */
	const REGEN = 'regen';

	/**
	 * No rollback.
	 */
	const NONE = 'none';

	/**
	 * Types that can be rolled back.
	 */
	const RESTORABLE = array( self::REVISION, self::TRASH, self::SNAPSHOT, self::REGEN );

	/**
	 * A rollback reference.
	 *
	 * @param string               $type   One of the RESTORABLE types.
	 * @param string               $kind   Target kind, e.g. `post`, `option`, `term`.
	 * @param int|string           $id     Target id or name.
	 * @param array<string, mixed> $refs   References (ids, names) the tool needs.
	 * @param array<string, mixed> $before Raw before-values for a snapshot.
	 * @return array<string, mixed>
	 */
	public static function make( string $type, string $kind, $id, array $refs = array(), array $before = array() ): array {
		return array(
			'type'   => $type,
			'target' => array(
				'kind' => $kind,
				'id'   => $id,
			),
			'refs'   => $refs,
			'before' => $before,
		);
	}

	/**
	 * No rollback.
	 *
	 * @return array{type: string}
	 */
	public static function none(): array {
		return array( 'type' => self::NONE );
	}

	/**
	 * Whether a reference can be rolled back.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return bool
	 */
	public static function is_restorable( array $rollback ): bool {
		return isset( $rollback['type'] ) && in_array( $rollback['type'], self::RESTORABLE, true );
	}

	/**
	 * What MCP may see: type, target and availability, never refs or before-values.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return array<string, mixed>
	 */
	public static function public_view( array $rollback ): array {
		return array(
			'type'      => isset( $rollback['type'] ) && is_string( $rollback['type'] ) ? $rollback['type'] : self::NONE,
			'target'    => isset( $rollback['target'] ) && is_array( $rollback['target'] ) ? $rollback['target'] : null,
			'available' => self::is_restorable( $rollback ),
		);
	}
}
