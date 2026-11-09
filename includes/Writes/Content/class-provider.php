<?php
/**
 * Content write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Content writes. Package A (content and post meta) adds its tools here.
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
		return array( new CreatePost(), new UpdatePost(), new ReplaceInPost(), new RestorePostRevision(), new TrashPost(), new RestorePost(), new DeletePostPermanently(), new SetPostMeta(), new DeletePostMeta(), new SetPostTerms() );
	}
}
