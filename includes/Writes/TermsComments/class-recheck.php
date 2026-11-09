<?php
/**
 * Apply-time re-check shared by the taxonomy and comment write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\TermsComments;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * Guards apply(): the target must still be exactly as assessed, so nothing is written on a changed state.
 */
final class Recheck {

	/**
	 * Assesses again right before writing and throws when the result differs from the approved assessment.
	 *
	 * @param WriteTool            $tool       Tool.
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made before.
	 * @param string               $what       What is changed, for the message.
	 * @throws ToolError When refused now or the state changed.
	 */
	public static function unchanged( WriteTool $tool, array $args, Assessment $assessment, string $what ): void {
		$fresh = $tool->assess( $args );
		if ( $fresh->is_refused() ) {
			throw new ToolError( esc_textarea( $fresh->refusal()['message'] ) );
		}
		if ( $fresh->preconditions() !== $assessment->preconditions() ) {
			throw new ToolError( sprintf( '%s changed while the write was being applied; nothing was changed.', esc_textarea( $what ) ) );
		}
	}
}
