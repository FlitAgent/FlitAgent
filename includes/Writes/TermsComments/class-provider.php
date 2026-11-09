<?php
/**
 * Taxonomy and comment write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\TermsComments;

defined( 'ABSPATH' ) || exit;

/**
 * Package B (#58): term and comment writes.
 */
final class Provider implements \FlitAgent\Tools\Provider {

	/**
	 * No read tools.
	 *
	 * @return \FlitAgent\Mcp\Tool[]
	 */
	public function reads(): array {
		return array();
	}

	/**
	 * Write tools.
	 *
	 * @return \FlitAgent\Writes\WriteTool[]
	 */
	public function writes(): array {
		return array(
			new CreateTerm(),
			new UpdateTerm(),
			new DeleteTerm(),
			new CreateComment(),
			new ModerateComment(),
			new DeleteCommentPermanently(),
		);
	}
}
