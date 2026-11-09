<?php
/**
 * Safe error messages from plugin code.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an error raised by third-party code into a message safe to return over MCP: HTML removed, absolute file paths
 * reduced to their file name, whitespace collapsed, at most 300 characters.
 */
final class ErrorText {

	/**
	 * Longest message returned.
	 */
	const MAX = 300;

	/**
	 * Safe message of a throwable.
	 *
	 * @param \Throwable $error Error.
	 * @return string
	 */
	public static function of( \Throwable $error ): string {
		return self::clean( ToolError::plain( $error ) );
	}

	/**
	 * Safe version of a message.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function clean( string $message ): string {
		$message = preg_replace( '/<[^>]*>/', '', $message );
		$message = preg_replace_callback(
			'#(?:[A-Za-z]:)?(?:[\\\\/][^\\\\/\s:\'"()<>]+){2,}[\\\\/]?#',
			static function ( array $found ): string {
				$parts = preg_split( '#[\\\\/]#', rtrim( $found[0], '\\/' ) );
				return (string) end( $parts );
			},
			(string) $message
		);
		$message = trim( (string) preg_replace( '/\s+/', ' ', (string) $message ) );
		if ( strlen( $message ) > self::MAX ) {
			$message = substr( $message, 0, self::MAX - 3 ) . '...';
		}
		return '' === $message ? 'No message.' : $message;
	}
}
