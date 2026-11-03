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
	 * @param string $title Human-readable title.
	 * @return array<string, mixed>
	 */
	public static function read_only( string $title ): array {
		return array(
			'title'           => $title,
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
	}
}
