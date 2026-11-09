<?php
/**
 * Snapshot values that survive canonical storage.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

use FlitAgent\Writes\Canonical;

defined( 'ABSPATH' ) || exit;

/**
 * Receipts store rollback references as canonical JSON, which sorts object keys. A before-value is therefore kept as an
 * order-preserving JSON string inside the reference, so a rollback restores arrays with their original key order and
 * scalar types. PHP objects come back as arrays.
 */
final class Exact {

	/**
	 * A value as an order-preserving JSON string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function pack( $value ): string {
		return Canonical::encode( $value );
	}

	/**
	 * The value a pack() string held.
	 *
	 * @param mixed $packed String from pack().
	 * @return mixed
	 */
	public static function unpack( $packed ) {
		return is_string( $packed ) ? json_decode( $packed, true ) : null;
	}
}
