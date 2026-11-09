<?php
/**
 * Tool error whose message is safe to show the model.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by a tool to reject a call with a clear reason, e.g. an invalid argument. The server returns the message as
 * a tool error (`isError: true`) so the model can correct the call. Never put internal details in the message; any
 * other exception becomes a generic error.
 *
 * Throw sites escape the values in a message, as WordPress requires of exception messages, with esc_textarea(): unlike
 * esc_html() it also encodes entities already in the value (post content with `&amp;`), so plain() gets the exact text
 * back for JSON and receipts. wp-admin prints the escaped message with esc_html(), which leaves those entities alone.
 * A message reused inside another message is read with plain() before it is escaped again.
 */
final class ToolError extends \RuntimeException {

	/**
	 * A throwable's message as plain text.
	 *
	 * @param \Throwable $error Error.
	 * @return string
	 */
	public static function plain( \Throwable $error ): string {
		return htmlspecialchars_decode( $error->getMessage(), ENT_QUOTES );
	}
}
