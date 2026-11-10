<?php
/**
 * Output buffers third-party code leaves open.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * Discards output buffers opened above a recorded level, so nothing a plugin, theme or builder printed reaches the MCP
 * response.
 */
final class Buffers {

	/**
	 * Discards every buffer above `$level`. A buffer opened without PHP_OUTPUT_HANDLER_REMOVABLE cannot be closed: its
	 * content is discarded when it allows that, and the loop stops there instead of running forever.
	 *
	 * @param int $level Level recorded before the third-party call.
	 * @return string What the discarded buffers held, outermost first.
	 */
	public static function discard( int $level ): string {
		$output = '';
		while ( ob_get_level() > $level ) {
			$status   = ob_get_status();
			$flags    = isset( $status['flags'] ) ? (int) $status['flags'] : 0;
			$contents = (string) ob_get_contents();
			if ( 0 === ( $flags & PHP_OUTPUT_HANDLER_REMOVABLE ) ) {
				if ( 0 !== ( $flags & PHP_OUTPUT_HANDLER_CLEANABLE ) ) {
					ob_clean();
					$output = $contents . $output;
				}
				return $output;
			}
			$before = ob_get_level();
			if ( ! ob_end_clean() || ob_get_level() >= $before ) {
				return $output;
			}
			$output = $contents . $output;
		}
		return $output;
	}
}
