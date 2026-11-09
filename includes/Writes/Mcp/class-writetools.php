<?php
/**
 * The MCP tools of the write layer.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Mcp;

use FlitAgent\Tools\Definition as ReadDefinition;
use FlitAgent\Writes\Gateway;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the MCP tools for a Gateway: every write tool (rollback_change included), then `apply_change`, `get_change`
 * and `list_receipts`. None of them can approve: approval happens only in wp-admin.
 */
final class WriteTools {

	/**
	 * MCP tools served by a Gateway.
	 *
	 * @param Gateway $gateway Gateway.
	 * @return \FlitAgent\Mcp\Tool[]
	 */
	public static function for_gateway( Gateway $gateway ): array {
		$tools = array();
		foreach ( $gateway->tools() as $tool ) {
			$tools[] = self::write_tool( $gateway, $tool );
		}

		$tools[] = new GatewayTool(
			array(
				'name'        => Gateway::APPLY_TOOL,
				'title'       => 'FlitAgent: apply an approved change',
				'description' => 'From the FlitAgent WordPress plugin: Applies a change an administrator approved in wp-admin (change_id from an approval_required response), with exactly the arguments that were approved. Only the agent connection that requested it can apply it, once, before the approval expires (default 15 minutes). Returns the receipt, or the change status (pending, rejected, stale, approval_expired, already_applied). If the target changed since the request, the change becomes stale and must be requested again.',
				'inputSchema' => Gateway::apply_change_schema(),
				'annotations' => array(
					'title'           => 'FlitAgent: apply an approved change',
					'readOnlyHint'    => false,
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			array( $gateway, 'apply_change' )
		);

		$tools[] = new GatewayTool(
			array(
				'name'        => Gateway::PLAN_TOOL,
				'title'       => 'FlitAgent: submit a plan of changes',
				'description' => 'From the FlitAgent WordPress plugin: Submits several write tool calls (1-25) as one plan an administrator approves once in wp-admin, for example the fixes get_health_triage returned. Each item is checked exactly as if called alone; refused or invalid items are reported and the rest are queued. Nothing changes until an administrator approves the plan (they may leave items out); then call apply_change with the plan change_id: the approved items run in order, each with its own receipt and rollback, and one failing does not stop the others. Plans are never auto-approved.',
				'inputSchema' => Gateway::submit_plan_schema(),
				'annotations' => array(
					'title'           => 'FlitAgent: submit a plan of changes',
					'readOnlyHint'    => false,
					'destructiveHint' => false,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			),
			array( $gateway, 'submit_plan' )
		);

		$get_change = ReadDefinition::read_only(
			'get_change',
			'FlitAgent: get a change',
			'Shows a change this connection requested: status (pending, approved, rejected, expired, applied, stale, failed), tier, summary, preview and expiry.',
			Gateway::get_change_schema()['properties'],
			array( 'change_id' )
		);
		$tools[]    = new GatewayTool( $get_change, array( $gateway, 'get_change' ) );

		$list_receipts = ReadDefinition::read_only(
			'list_receipts',
			'FlitAgent: list write receipts',
			'Lists receipts of writes made through FlitAgent, newest first: tool, tier, status, who approved it (policy or a user), redacted arguments, result and whether it can be rolled back. Filter by tool or status; paginate with limit and page.',
			Gateway::list_receipts_schema()['properties']
		);
		$tools[]       = new GatewayTool( $list_receipts, array( $gateway, 'list_receipts' ) );

		return $tools;
	}

	/**
	 * Serves one write tool through the Gateway.
	 *
	 * @param Gateway   $gateway Gateway.
	 * @param WriteTool $tool    Write tool.
	 * @return GatewayTool
	 */
	private static function write_tool( Gateway $gateway, WriteTool $tool ): GatewayTool {
		$name = $tool->name();
		return new GatewayTool(
			$tool->definition(),
			static function ( array $arguments ) use ( $gateway, $name ): array {
				return $gateway->call( $name, $arguments );
			}
		);
	}
}
