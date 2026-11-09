<?php
/**
 * The get_maintenance_status tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\System;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Reports maintenance mode (WP-CLI `maintenance-mode status`).
 */
final class MaintenanceStatus implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_maintenance_status';
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		return Definition::read_only(
			$this->name(),
			'Get maintenance mode status with FlitAgent',
			'reports whether WordPress is in maintenance mode (for example during an update), using wp_is_maintenance_mode().'
		);
	}

	/**
	 * Runs the tool.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError On invalid arguments.
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array(), $this->name() );

		return array( 'maintenance_mode' => (bool) wp_is_maintenance_mode() );
	}
}
