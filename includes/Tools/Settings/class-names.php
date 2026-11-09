<?php
/**
 * Option and transient name checks.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Settings;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses names that look secret or could disguise another name.
 */
final class Names {

	/**
	 * Throws unless the name is printable ASCII without spaces and does not look secret.
	 *
	 * @param string $name Option or transient name.
	 * @param string $kind "option" or "transient", for the message.
	 * @throws ToolError When the name is refused.
	 */
	public static function check( string $name, string $kind ): void {
		if ( 1 !== preg_match( '/^[\x21-\x7E]+$/', $name ) ) {
			throw new ToolError( sprintf( 'The %s name "%s" is refused: names must be printable ASCII with no spaces. Use the exact name.', esc_textarea( $kind ), esc_textarea( $name ) ) );
		}
		if ( Redactor::is_secret_key( $name ) ) {
			throw new ToolError( sprintf( 'The %s "%s" is refused: its name looks like it holds a secret (password, key, salt, token).', esc_textarea( $kind ), esc_textarea( $name ) ) );
		}
	}
}
