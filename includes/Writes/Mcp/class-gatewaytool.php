<?php
/**
 * An MCP tool served by the write Gateway.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Mcp;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\SiteText;

defined( 'ABSPATH' ) || exit;

/**
 * Always protected; every call goes to one Gateway method.
 */
final class GatewayTool implements Tool {

	/**
	 * MCP definition.
	 *
	 * @var array<string, mixed>
	 */
	private $definition;

	/**
	 * Runs the call: `function ( array $arguments ): array`.
	 *
	 * @var callable
	 */
	private $run;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $definition MCP definition (with `name`).
	 * @param callable             $run        Gateway call.
	 */
	public function __construct( array $definition, callable $run ) {
		$this->definition = $definition;
		$this->run        = $run;
	}

	/**
	 * Tool name.
	 */
	public function name(): string {
		return $this->definition['name'];
	}

	/**
	 * MCP definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return $this->definition;
	}

	/**
	 * Write-layer tools are always protected (`manage_options`).
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * Runs the call.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError The Gateway's own error, its message made plain.
	 */
	public function call( array $arguments ): array {
		try {
			return SiteText::for_write( call_user_func( $this->run, $arguments ) );
		} catch ( ToolError $e ) {
			throw new ToolError( esc_textarea( SiteText::readable( ToolError::plain( $e ) ) ) );
		}
	}
}
