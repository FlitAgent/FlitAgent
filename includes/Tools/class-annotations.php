<?php
/**
 * MCP tool annotations.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Builds tool annotations. All hints are explicit, as the ChatGPT app directory requires.
 */
final class Annotations {

	/**
	 * Annotations for a tool that only reads: no changes, same result for the same state.
	 *
	 * @param string $title      Human-readable title.
	 * @param bool   $open_world True only if the tool reaches a service outside this site (e.g. WordPress.org); a tool
	 *                           that reads only this site, even over HTTP, is closed-world.
	 * @return array<string, mixed>
	 */
	public static function read_only( string $title, bool $open_world = false ): array {
		return array(
			'title'           => $title,
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => $open_world,
		);
	}
}
