<?php
/**
 * Redaction for receipts, previews and results.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Masks values under secret-looking keys (Tools\Redactor) and, for compact output, replaces long strings with their
 * hash, length and head.
 */
final class Redaction {

	/**
	 * Strings longer than this (bytes) are compacted.
	 */
	const MAX_STRING = 1024;

	/**
	 * Characters kept from the start of a compacted string.
	 */
	const HEAD = 64;

	/**
	 * Secrets masked, everything else kept (stored previews for the approval screen).
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	public static function secrets( $data ) {
		return Redactor::redact( $data );
	}

	/**
	 * Secrets masked and strings over 1 KB replaced by `array( 'sha256', 'length' (bytes), 'head' )` (receipts, MCP).
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	public static function compact( $data ) {
		return self::shorten( Redactor::redact( $data ) );
	}

	/**
	 * Replaces long strings at any depth.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	private static function shorten( $data ) {
		if ( is_string( $data ) && strlen( $data ) > self::MAX_STRING ) {
			preg_match( '/^.{0,' . self::HEAD . '}/su', $data, $head );
			return array(
				'sha256' => hash( 'sha256', $data ),
				'length' => strlen( $data ),
				'head'   => isset( $head[0] ) ? $head[0] : substr( $data, 0, self::HEAD ),
			);
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::shorten( $value );
			}
		}
		return $data;
	}
}
