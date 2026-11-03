<?php
/**
 * MCP tool contract.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * A tool exposed over MCP.
 */
interface Tool {

	/**
	 * Anyone can call the tool; it returns only data any visitor can already see.
	 */
	const ACCESS_PUBLIC = 'public';

	/**
	 * Only authenticated callers allowed by the server's access gate can list or call the tool.
	 */
	const ACCESS_PROTECTED = 'protected';

	/**
	 * Unique tool name, e.g. `get_site_info`.
	 */
	public function name(): string;

	/**
	 * Full MCP tool definition (name, title, description, schemas, annotations).
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array;

	/**
	 * Who may list and call the tool: `Tool::ACCESS_PUBLIC` or `Tool::ACCESS_PROTECTED`. The server enforces it.
	 */
	public function access(): string;

	/**
	 * Runs the tool.
	 *
	 * @param array<string, mixed> $arguments Tool arguments from the client.
	 * @return array<string, mixed> Structured result.
	 */
	public function call( array $arguments ): array;
}
