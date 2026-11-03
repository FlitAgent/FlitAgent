<?php
/**
 * Reads named fields from the current request, unslashed and sanitized.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The only way wp-admin handlers read $_POST and $_GET: each names the fields it needs and its nonce action, and gets
 * those fields as plain sanitized text only when the nonce is valid, so nothing else from the request travels further.
 * A field that is missing, or is not a string, is left out. The caller still checks the capability before it acts.
 */
final class Request {

	/**
	 * Nonce action of the links between FlitAgent's own screens (the setup guide's steps, a dialog to open).
	 */
	const VIEW = 'flitagent_view';

	/**
	 * Named fields from $_POST, only when the request carries a valid nonce for one of the actions. Without one nothing is
	 * read, so the caller's own checks refuse the request.
	 *
	 * @param array<int, string> $names   Field names.
	 * @param string             ...$actions Nonce actions. A form whose action depends on a field lists every possible
	 *                                       action here, and the caller checks the one that field names.
	 * @return array<string, string> Sanitized values by field name.
	 */
	public static function post( array $names, string ...$actions ): array {
		$verified = false;
		foreach ( $actions as $action ) {
			if ( isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $action ) ) {
				$verified = true;
				break;
			}
		}
		if ( ! $verified ) {
			return array();
		}
		$fields = array();
		foreach ( $names as $name ) {
			if ( isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ) {
				$fields[ $name ] = sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
			}
		}
		return $fields;
	}

	/**
	 * Whether this is a POST request.
	 *
	 * @return bool
	 */
	public static function is_post(): bool {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
	}

	/**
	 * Named fields from $_GET, only when the request carries a valid nonce for the action. Without one nothing is read,
	 * so the caller shows its default view.
	 *
	 * @param array<int, string> $names  Field names.
	 * @param string             $action Nonce action.
	 * @return array<string, string> Sanitized values by field name.
	 */
	public static function query( array $names, string $action = self::VIEW ): array {
		if ( ! isset( $_GET['_wpnonce'] ) || ! is_string( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $action ) ) {
			return array();
		}
		$fields = array();
		foreach ( $names as $name ) {
			if ( isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ) {
				$fields[ $name ] = sanitize_text_field( wp_unslash( $_GET[ $name ] ) );
			}
		}
		return $fields;
	}

	/**
	 * The query argument a wp-admin link carries so that query() reads its fields.
	 *
	 * @return string `&_wpnonce=…`
	 */
	public static function view_arg(): string {
		return '&_wpnonce=' . wp_create_nonce( self::VIEW );
	}
}
