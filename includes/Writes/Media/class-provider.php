<?php
/**
 * Media write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Media writes (package C): upload from URL, update, delete.
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
		return array( new UploadMediaFromUrl(), new UpdateMedia(), new DeleteMedia() );
	}
}
