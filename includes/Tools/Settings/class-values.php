<?php
/**
 * Value formatting for settings tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Settings;

use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Describes, redacts and shortens stored values.
 */
final class Values {

	/**
	 * Longest value shown in a list, in characters.
	 */
	const PREVIEW_LENGTH = 200;

	/**
	 * JSON-friendly type name of a value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function type( $value ): string {
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 'number';
		}
		if ( is_string( $value ) ) {
			return 'string';
		}
		if ( null === $value ) {
			return 'null';
		}
		return 'array';
	}

	/**
	 * Whether a string value is itself JSON text (an object or array).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_json_text( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$trimmed = trim( $value );
		return '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) && null !== json_decode( $trimmed, true );
	}

	/**
	 * A short, redacted text preview of a stored (possibly serialized) value.
	 *
	 * @param string $name  Option or transient name.
	 * @param mixed  $value Stored value.
	 * @return array{value: string, truncated: bool}
	 */
	public static function preview( string $name, $value ): array {
		if ( Redactor::is_secret_key( $name ) ) {
			return array(
				'value'     => Redactor::MASK,
				'truncated' => false,
			);
		}

		$value = maybe_unserialize( $value );
		if ( ! is_scalar( $value ) && null !== $value ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- preview text only.
			$value = (string) json_encode( Redactor::redact( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		$text = (string) $value;

		if ( strlen( $text ) > self::PREVIEW_LENGTH ) {
			return array(
				'value'     => substr( $text, 0, self::PREVIEW_LENGTH ) . '...',
				'truncated' => true,
			);
		}
		return array(
			'value'     => $text,
			'truncated' => false,
		);
	}

	/**
	 * ISO 8601 time for a Unix timestamp, or null.
	 *
	 * @param mixed $timestamp Timestamp.
	 * @return string|null
	 */
	public static function iso( $timestamp ): ?string {
		if ( ! is_numeric( $timestamp ) || (int) $timestamp <= 0 ) {
			return null;
		}
		return gmdate( 'c', (int) $timestamp );
	}

	/**
	 * Turns a WP-CLI style pattern (* and ?) into a LIKE pattern.
	 *
	 * @param object $db     The $wpdb object.
	 * @param string $search Pattern.
	 * @return string
	 */
	public static function like( $db, string $search ): string {
		return str_replace( array( '*', '?' ), array( '%', '_' ), $db->esc_like( $search ) );
	}
}
