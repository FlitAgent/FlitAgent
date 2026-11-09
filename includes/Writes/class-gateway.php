<?php
/**
 * The pipeline every write runs through.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Runs write tools. WordPress-free: storage, settings, identity, time and signing come in through ports.
 *
 * A write call goes: kill switch and multisite refusal -> protected gate -> signed-request app -> closed
 * schema (with `idempotency_key`) -> dry run (assessment only) or idempotency claim -> assess() -> refuse (receipt
 * `refused`), apply now (reversible, auto-approvable, enabled by policy) or queue a pending change set for a human.
 * Applying: apply() -> verify() -> receipt. An approved change is applied by apply_change(), bound by HMAC to the change,
 * tool, arguments, requester, client and expiry, once, after a fresh assess() that must not show a higher tier or
 * changed preconditions.
 */
final class Gateway {

	/**
	 * Name of the built-in rollback write tool.
	 */
	const ROLLBACK_TOOL = 'rollback_change';

	/**
	 * Name of the apply meta-tool (its idempotency scope).
	 */
	const APPLY_TOOL = 'apply_change';

	/**
	 * Name of the plan meta-tool: several writes submitted together and approved once (#122).
	 */
	const PLAN_TOOL = 'submit_plan';

	/**
	 * Most items in one plan.
	 */
	const MAX_PLAN_ITEMS = 25;

	/**
	 * Seconds a pending change waits for a decision.
	 */
	const PENDING_TTL = 86400;

	/**
	 * Receipt statuses of writes that happened.
	 */
	const APPLIED_STATUSES = array( 'applied', 'applied_with_warnings', 'verification_failed' );

	/**
	 * Every receipt status.
	 */
	const RECEIPT_STATUSES = array( 'applied', 'applied_with_warnings', 'verification_failed', 'failed', 'refused' );

	/**
	 * Generic message for an unexpected failure.
	 */
	const FAILED = 'The tool failed to run.';

	/**
	 * The refusal of every write while Access for AI tools is Read only. The MCP server gives the same message for a write
	 * tool called while it is not registered (Plugin::register_rest_routes()).
	 */
	const WRITES_OFF = 'Writes are turned off on this site: its Access for AI tools is Read only. An administrator can choose Read and propose changes in wp-admin under FlitAgent > Settings.';

	/**
	 * Write tools by name.
	 *
	 * @var array<string, WriteTool>
	 */
	private $tools = array();

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Policy.
	 *
	 * @var Policy
	 */
	private $policy;

	/**
	 * Identity.
	 *
	 * @var Identity
	 */
	private $identity;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Signer.
	 *
	 * @var Signer
	 */
	private $signer;

	/**
	 * Constructor.
	 *
	 * @param WriteTool[] $tools    Write tools (the rollback tool is added automatically).
	 * @param Store       $store    Store.
	 * @param Policy      $policy   Policy.
	 * @param Identity    $identity Identity of the caller.
	 * @param Clock       $clock    Clock.
	 * @param Signer      $signer   Signer for approval bindings.
	 * @throws \LogicException When two tools share a name.
	 */
	public function __construct( array $tools, Store $store, Policy $policy, Identity $identity, Clock $clock, Signer $signer ) {
		$this->store    = $store;
		$this->policy   = $policy;
		$this->identity = $identity;
		$this->clock    = $clock;
		$this->signer   = $signer;

		$tools[] = new RollbackTool(
			$store,
			function ( string $name ): ?WriteTool {
				return isset( $this->tools[ $name ] ) ? $this->tools[ $name ] : null;
			},
			function (): string {
				return $this->identity->client_id();
			}
		);
		foreach ( $tools as $tool ) {
			if ( isset( $this->tools[ $tool->name() ] ) ) {
				throw new \LogicException( sprintf( 'Two write tools are named %s.', esc_textarea( $tool->name() ) ) );
			}
			$this->tools[ $tool->name() ] = $tool;
		}
	}

	/**
	 * Every write tool, including the rollback tool.
	 *
	 * @return WriteTool[]
	 */
	public function tools(): array {
		return array_values( $this->tools );
	}

	/**
	 * Names of the tools an administrator may enable auto-approve for: those that can assess as reversible and
	 * auto-approvable.
	 *
	 * @return string[]
	 */
	public function auto_approvable_tools(): array {
		$names = array();
		foreach ( $this->tools as $name => $tool ) {
			if ( $tool->may_auto_approve() && in_array( Risk::REVERSIBLE, $tool->tiers(), true ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Runs a write tool call from MCP.
	 *
	 * @param string               $name Tool name.
	 * @param array<string, mixed> $args Arguments, including `idempotency_key` and optional `dry_run`.
	 * @return array<string, mixed> Response for the model.
	 * @throws ToolError On refusal, invalid arguments or failure.
	 */
	public function call( string $name, array $args ): array {
		$this->guard_caller();
		if ( ! isset( $this->tools[ $name ] ) ) {
			throw new ToolError( 'Unknown tool' );
		}
		$tool = $this->tools[ $name ];

		Schema::validate( $tool->definition()['inputSchema'], $args );
		$key       = $args['idempotency_key'];
		$dry_run   = isset( $args['dry_run'] ) && true === $args['dry_run'];
		$tool_args = $args;
		unset( $tool_args['idempotency_key'], $tool_args['dry_run'] );

		if ( $dry_run ) {
			return $this->dry_run( $tool, $tool_args );
		}

		return $this->once(
			$name,
			$key,
			Canonical::hash( $tool_args ),
			function () use ( $tool, $tool_args, $key ): array {
				return $this->run( $tool, $tool_args, $key );
			}
		);
	}

	/**
	 * Applies an approved change for its requester (the `apply_change` meta-tool).
	 *
	 * @param array<string, mixed> $args `change_id` and `idempotency_key`.
	 * @return array<string, mixed>
	 * @throws ToolError On an unknown change, invalid arguments, refusal or failure.
	 */
	public function apply_change( array $args ): array {
		$this->guard_caller();
		Schema::validate( self::apply_change_schema(), $args );
		$change_id = $args['change_id'];

		return $this->once(
			self::APPLY_TOOL,
			$args['idempotency_key'],
			Canonical::hash( array( 'change_id' => $change_id ) ),
			function () use ( $change_id, $args ): array {
				return $this->apply_stored( $change_id, $args['idempotency_key'] );
			}
		);
	}

	/**
	 * Submits a plan (the `submit_plan` meta-tool): each item is assessed and queued exactly as if it were called alone,
	 * except that policy never auto-applies it. The plan itself is one pending change whose arguments list the items'
	 * change ids; approving it approves the chosen items, and apply_change on it applies them in order.
	 *
	 * @param array<string, mixed> $args `title`, `items` and `idempotency_key`.
	 * @return array<string, mixed>
	 * @throws ToolError On invalid arguments, or when no item could be queued.
	 */
	public function submit_plan( array $args ): array {
		$this->guard_caller();
		Schema::validate( self::submit_plan_schema(), $args );
		$count = count( $args['items'] );
		if ( $count < 1 || $count > self::MAX_PLAN_ITEMS ) {
			throw new ToolError( sprintf( 'items must list 1 to %d changes.', (int) self::MAX_PLAN_ITEMS ) );
		}
		$key   = $args['idempotency_key'];
		$title = $args['title'];
		$items = $args['items'];

		return $this->once(
			self::PLAN_TOOL,
			$key,
			Canonical::hash(
				array(
					'title' => $title,
					'items' => $items,
				)
			),
			function () use ( $title, $items, $key ): array {
				return $this->queue_plan( $title, $items, $key );
			}
		);
	}

	/**
	 * A change set, for its requester (the `get_change` meta-tool).
	 *
	 * @param array<string, mixed> $args `change_id`.
	 * @return array<string, mixed>
	 * @throws ToolError On an unknown change.
	 */
	public function get_change( array $args ): array {
		$this->guard_caller();
		Schema::validate( self::get_change_schema(), $args );
		$change = $this->visible_change( $args['change_id'] );
		if ( null === $change ) {
			throw new ToolError( 'Unknown change' );
		}
		return $this->change_view( $change );
	}

	/**
	 * Receipts, newest first, redacted (the `list_receipts` meta-tool).
	 *
	 * @param array<string, mixed> $args `limit`, `page`, `tool`, `status`.
	 * @return array<string, mixed>
	 * @throws ToolError When writes are unavailable, the caller may not use it, or on invalid arguments.
	 */
	public function list_receipts( array $args ): array {
		$this->guard();
		if ( ! $this->identity->can_write() ) {
			throw new ToolError( 'Unknown tool' );
		}
		Schema::validate( self::list_receipts_schema(), $args );
		$limit = isset( $args['limit'] ) ? $args['limit'] : 20;
		$page  = isset( $args['page'] ) ? $args['page'] : 1;
		$where = array_intersect_key( $args, array_flip( array( 'tool', 'status' ) ) );

		$rows     = $this->store->receipts( $where, $limit + 1, ( $page - 1 ) * $limit );
		$has_more = count( $rows ) > $limit;
		$receipts = array();
		foreach ( array_slice( $rows, 0, $limit ) as $row ) {
			$receipts[] = self::receipt_view( $row );
		}

		return array(
			'receipts' => $receipts,
			'page'     => $page,
			'limit'    => $limit,
			'has_more' => $has_more,
		);
	}

	/**
	 * Approves a pending change. Called only from wp-admin after the nonce, cookie-session, capability and
	 * no-application-password checks; never reachable from MCP.
	 *
	 * For a plan, `$items` lists the item change ids to approve (null: all of them); the other items are rejected, and
	 * approving none rejects the plan.
	 *
	 * @param string        $change_id Change UUID.
	 * @param int           $approver  Approving user id.
	 * @param string[]|null $items     Plan items to approve, or null for all.
	 * @return string New status.
	 * @throws ToolError When writes are off, on multisite, or the change is not pending.
	 */
	public function approve( string $change_id, int $approver, ?array $items = null ): string {
		$this->guard();
		$change = $this->store->change( $change_id );
		if ( null === $change ) {
			throw new ToolError( 'Unknown change.' );
		}
		if ( 'pending' !== $change['status'] ) {
			throw new ToolError( sprintf( 'This change is not pending (status: %s).', esc_textarea( $change['status'] ) ) );
		}
		$now = $this->clock->now();
		if ( $now >= (int) $change['expires_at'] ) {
			$this->store->update_change( $change_id, array( 'status' => 'expired' ), array( 'pending' ) );
			throw new ToolError( 'This change expired before it was approved. Ask the agent to request it again.' );
		}

		if ( self::PLAN_TOOL === $change['tool'] ) {
			$ids      = self::plan_item_ids( $change );
			$selected = null === $items ? $ids : array_values( array_intersect( $ids, $items ) );
			if ( array() === $selected ) {
				return $this->reject( $change_id, $approver );
			}
			foreach ( $ids as $id ) {
				try {
					if ( in_array( $id, $selected, true ) ) {
						$this->approve( $id, $approver );
					} else {
						$this->reject( $id, $approver );
					}
				} catch ( ToolError $e ) {
					// An item that expired or was decided on its own keeps its status; apply_change reports it.
					continue;
				}
			}
		}

		$expires = $now + $this->policy->approval_ttl();
		$updated = $this->store->update_change(
			$change_id,
			array(
				'status'      => 'approved',
				'approved_by' => $approver,
				'approved_at' => $now,
				'expires_at'  => $expires,
				'binding'     => $this->binding( $change_id, (string) $change['tool'], hash( 'sha256', (string) $change['args_json'] ), (int) $change['requester_user_id'], (string) $change['client_id'], $expires ),
			),
			array( 'pending' )
		);
		if ( ! $updated ) {
			throw new ToolError( 'This change is not pending.' );
		}
		return 'approved';
	}

	/**
	 * Rejects a pending or approved (not yet applied) change. Called only from wp-admin. Allowed while writes are off,
	 * so administrators can clear the queue. The rejecting user is stored in `approved_by` (the decision maker).
	 *
	 * @param string $change_id Change UUID.
	 * @param int    $approver  Rejecting user id.
	 * @return string New status.
	 * @throws ToolError When the change cannot be rejected.
	 */
	public function reject( string $change_id, int $approver ): string {
		$change  = $this->store->change( $change_id );
		$updated = $this->store->update_change(
			$change_id,
			array(
				'status'      => 'rejected',
				'approved_by' => $approver,
				'approved_at' => $this->clock->now(),
				'binding'     => '',
			),
			array( 'pending', 'approved' )
		);
		if ( ! $updated ) {
			throw new ToolError( 'Unknown change, or it can no longer be rejected.' );
		}
		if ( null !== $change && self::PLAN_TOOL === $change['tool'] ) {
			foreach ( self::plan_item_ids( $change ) as $id ) {
				try {
					$this->reject( $id, $approver );
				} catch ( ToolError $e ) {
					// Already decided or applied on its own.
					continue;
				}
			}
		}
		return 'rejected';
	}

	/**
	 * Input schema of `apply_change`.
	 *
	 * @return array<string, mixed>
	 */
	public static function apply_change_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'change_id'       => self::id_property( 'change_id from an approval_required response.' ),
				'idempotency_key' => Definition::key_property(),
			),
			'required'             => array( 'change_id', 'idempotency_key' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema of `submit_plan`.
	 *
	 * @return array<string, mixed>
	 */
	public static function submit_plan_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'           => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => 'What the plan does, shown to the administrator, e.g. "Fix what health triage found".',
				),
				'items'           => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => self::MAX_PLAN_ITEMS,
					'description' => 'The changes, in the order they will be applied: each { "tool": "<write tool>", "arguments": { ... } } with the arguments that tool takes, without idempotency_key or dry_run (for example a fix from get_health_triage).',
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'tool'      => array( 'type' => 'string' ),
							'arguments' => array( 'type' => 'object' ),
						),
						'required'             => array( 'tool', 'arguments' ),
						'additionalProperties' => false,
					),
				),
				'idempotency_key' => Definition::key_property(),
			),
			'required'             => array( 'title', 'items', 'idempotency_key' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema of `get_change`.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_change_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'change_id' => self::id_property( 'change_id from an approval_required response.' ) ),
			'required'             => array( 'change_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema of `list_receipts`.
	 *
	 * @return array<string, mixed>
	 */
	public static function list_receipts_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'description' => 'Receipts per page (default 20).',
				),
				'page'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 10000,
					'description' => 'Page, from 1.',
				),
				'tool'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 64,
				),
				'status' => array(
					'type' => 'string',
					'enum' => self::RECEIPT_STATUSES,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Refuses everything while writes are off or on multisite.
	 *
	 * @throws ToolError When writes are unavailable.
	 */
	private function guard(): void {
		if ( ! $this->policy->writes_enabled() ) {
			throw new ToolError( esc_textarea( self::WRITES_OFF ) );
		}
		if ( $this->policy->is_multisite() ) {
			throw new ToolError( 'Writes are not available on multisite installs yet.' );
		}
	}

	/**
	 * Refuses callers that may not write.
	 *
	 * @throws ToolError When the caller may not write.
	 */
	private function guard_caller(): void {
		$this->guard();
		if ( ! $this->identity->can_write() ) {
			throw new ToolError( 'Unknown tool' );
		}
		if ( '' === $this->identity->client_id() ) {
			throw new ToolError( 'Writes need a request from flitagent.com that names the AI app, so each change is tied to that app.' );
		}
	}

	/**
	 * Runs a call at most once per idempotency scope (user, client, tool, key).
	 *
	 * @param string   $tool      Tool name.
	 * @param string   $key       Idempotency key.
	 * @param string   $args_hash Hash of the arguments.
	 * @param callable $run       Returns the response, or `array( 'error' => message )` for a recorded error.
	 * @return array<string, mixed>
	 * @throws ToolError On conflict or a (replayed) error.
	 */
	private function once( string $tool, string $key, string $args_hash, callable $run ): array {
		$scope    = hash( 'sha256', implode( "\n", array( (string) $this->identity->user_id(), $this->identity->client_id(), $tool, $key ) ) );
		$existing = $this->store->claim_key( $scope, $args_hash, $this->clock->now() );

		if ( null !== $existing ) {
			if ( ! hash_equals( (string) $existing['args_hash'], $args_hash ) ) {
				throw new ToolError( 'idempotency_conflict: this idempotency_key was already used with different arguments. Use a new key for a different change.' );
			}
			if ( 'done' !== $existing['status'] ) {
				return array( 'status' => 'in_progress' );
			}
			$stored = Canonical::decode( $existing['response_json'] );
			if ( isset( $stored['error'] ) ) {
				throw new ToolError( esc_textarea( (string) $stored['error'] ) );
			}
			$stored['replayed'] = true;
			return $stored;
		}

		try {
			$response = $run();
		} catch ( ToolError $e ) {
			// Invalid arguments: nothing was attempted, so the key may be used again.
			$this->store->release_key( $scope );
			throw $e;
		} catch ( \Throwable $e ) {
			$response = array( 'error' => self::FAILED );
		}

		$this->store->complete_key( $scope, Canonical::encode( $response ) );
		if ( isset( $response['error'] ) ) {
			throw new ToolError( esc_textarea( (string) $response['error'] ) );
		}
		return $response;
	}

	/**
	 * Assesses, then refuses, applies or queues.
	 *
	 * @param WriteTool            $tool Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @param string               $key  Idempotency key.
	 * @return array<string, mixed>
	 */
	private function run( WriteTool $tool, array $args, string $key ): array {
		$assessment = $this->assess( $tool, $args );
		if ( $assessment->is_refused() ) {
			return $this->refuse( $tool, $args, $key, $assessment, '' );
		}
		if ( $this->auto_applies( $tool, $assessment ) ) {
			return $this->execute( $tool, $args, $key, $assessment, 'policy', null );
		}
		return $this->queue( $tool, $args, $assessment );
	}

	/**
	 * Assesses, treating a tier the tool did not declare as destructive.
	 *
	 * @param WriteTool            $tool Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	private function assess( WriteTool $tool, array $args ): Assessment {
		$assessment = $tool->assess( $args );
		if ( ! $assessment->is_refused() && ! in_array( $assessment->tier(), $tool->tiers(), true ) ) {
			$assessment = $assessment->at_least( Risk::DESTRUCTIVE );
		}
		return $assessment;
	}

	/**
	 * Whether a write is applied without a human: only reversible, auto-approvable, from a tool that may auto-approve,
	 * and enabled by policy. A destructive assessment is never auto-approvable (Assessment invariant), whatever the
	 * policy option says.
	 *
	 * @param WriteTool  $tool       Tool.
	 * @param Assessment $assessment Assessment.
	 * @return bool
	 */
	private function auto_applies( WriteTool $tool, Assessment $assessment ): bool {
		return Risk::REVERSIBLE === $assessment->tier()
			&& $assessment->auto_approvable()
			&& $tool->may_auto_approve()
			&& $this->policy->auto_approves( $tool->name() );
	}

	/**
	 * Dry run: the assessment only.
	 *
	 * @param WriteTool            $tool Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When refused.
	 */
	private function dry_run( WriteTool $tool, array $args ): array {
		$assessment = $this->assess( $tool, $args );
		if ( $assessment->is_refused() ) {
			throw new ToolError( 'Refused: ' . esc_textarea( $assessment->refusal()['message'] ) );
		}
		return array(
			'dry_run'           => true,
			'tool'              => $tool->name(),
			'tier'              => $assessment->tier(),
			'summary'           => $assessment->summary(),
			'preview'           => Redaction::compact( $assessment->preview() ),
			'requires_approval' => ! $this->auto_applies( $tool, $assessment ),
		);
	}

	/**
	 * Records a refusal.
	 *
	 * @param WriteTool            $tool       Tool.
	 * @param array<string, mixed> $args       Arguments.
	 * @param string               $key        Idempotency key.
	 * @param Assessment           $assessment Refused assessment.
	 * @param string               $change_id  Change set, or ''.
	 * @return array{error: string}
	 */
	private function refuse( WriteTool $tool, array $args, string $key, Assessment $assessment, string $change_id ): array {
		$refusal = $assessment->refusal();
		$receipt = $this->receipt( $tool, $args, $key, $change_id, Risk::PROHIBITED, 'none', 'refused', $refusal, Rollback::none() );
		return array( 'error' => sprintf( 'Refused: %s (receipt %s)', $refusal['message'], $receipt ) );
	}

	/**
	 * Creates a pending change set.
	 *
	 * @param WriteTool            $tool       Tool.
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return array<string, mixed>
	 */
	private function queue( WriteTool $tool, array $args, Assessment $assessment ): array {
		$now       = $this->clock->now();
		$args_json = Canonical::json( $args );
		$change_id = $this->store->insert_change(
			array(
				'tool'               => $tool->name(),
				'args_json'          => $args_json,
				'args_hash'          => hash( 'sha256', $args_json ),
				'requester_user_id'  => $this->identity->user_id(),
				'client_id'          => $this->identity->client_id(),
				'client_name'        => $this->identity->client_name(),
				'tier'               => $assessment->tier(),
				'summary'            => $assessment->summary(),
				'preview_json'       => Canonical::json( Redaction::secrets( $assessment->preview() ) ),
				'preconditions_json' => Canonical::json( $assessment->preconditions() ),
				'status'             => 'pending',
				'approved_by'        => 0,
				'approved_at'        => 0,
				'expires_at'         => $now + self::PENDING_TTL,
				'binding'            => '',
				'receipt_id'         => '',
				'created_at'         => $now,
			)
		);

		return array(
			'status'      => 'approval_required',
			'change_id'   => $change_id,
			'tool'        => $tool->name(),
			'tier'        => $assessment->tier(),
			'summary'     => $assessment->summary(),
			'preview'     => Redaction::compact( $assessment->preview() ),
			'expires_at'  => self::time( $now + self::PENDING_TTL ),
			'approve_url' => $this->policy->approve_url( $change_id ),
			'message'     => 'Nothing was changed yet. An administrator must approve this change in wp-admin (approve_url). Then call apply_change with this change_id and a new idempotency_key.',
		);
	}

	/**
	 * Applies an approved, stored change.
	 *
	 * @param string $change_id Change UUID.
	 * @param string $key       Idempotency key of the apply call.
	 * @return array<string, mixed>
	 */
	private function apply_stored( string $change_id, string $key ): array {
		$change = $this->visible_change( $change_id );
		if ( null === $change ) {
			return array( 'error' => 'Unknown change' );
		}
		$now = $this->clock->now();

		if ( 'approved' !== $change['status'] ) {
			if ( 'pending' === $change['status'] && $now >= (int) $change['expires_at'] ) {
				$this->store->update_change( $change_id, array( 'status' => 'expired' ), array( 'pending' ) );
				$change['status'] = 'expired';
			}
			return $this->status_response( $change );
		}

		if ( $now >= (int) $change['expires_at'] ) {
			$this->store->update_change( $change_id, array( 'status' => 'expired' ), array( 'approved' ) );
			return $this->status_response( array( 'status' => 'expired' ) + $change );
		}

		$expected = $this->binding( $change_id, (string) $change['tool'], hash( 'sha256', (string) $change['args_json'] ), $this->identity->user_id(), $this->identity->client_id(), (int) $change['expires_at'] );
		if ( '' === (string) $change['binding'] || ! hash_equals( $expected, (string) $change['binding'] ) ) {
			return array( 'error' => 'Unknown change' );
		}

		// Single use: only one caller moves the change out of `approved`.
		if ( ! $this->store->update_change( $change_id, array( 'status' => 'applying' ), array( 'approved' ) ) ) {
			$current = $this->store->change( $change_id );
			return $this->status_response( null === $current ? array( 'status' => 'failed' ) + $change : $current );
		}

		if ( self::PLAN_TOOL === $change['tool'] ) {
			return $this->apply_plan( $change, $key );
		}

		$tool = isset( $this->tools[ $change['tool'] ] ) ? $this->tools[ $change['tool'] ] : null;
		if ( null === $tool ) {
			$this->store->update_change( $change_id, array( 'status' => 'failed' ), array( 'applying' ) );
			return array( 'error' => 'The tool for this change is no longer available.' );
		}
		$args = Canonical::decode( $change['args_json'] );

		try {
			$assessment = $this->assess( $tool, $args );
		} catch ( ToolError $e ) {
			return $this->stale( $change, ToolError::plain( $e ) );
		}
		if ( $assessment->is_refused() ) {
			$this->store->update_change( $change_id, array( 'status' => 'stale' ), array( 'applying' ) );
			return $this->refuse( $tool, $args, $key, $assessment, $change_id );
		}
		if ( Risk::rank( $assessment->tier() ) > Risk::rank( (string) $change['tier'] ) ) {
			return $this->stale( $change, sprintf( 'Its risk is now %s (approved as %s).', $assessment->tier(), $change['tier'] ) );
		}
		if ( Canonical::json( $assessment->preconditions() ) !== (string) $change['preconditions_json'] ) {
			return $this->stale( $change, 'What it changes was modified after the request.' );
		}

		return $this->execute( $tool, $args, $key, $assessment, 'user:' . (int) $change['approved_by'], $change );
	}

	/**
	 * Marks an applying change stale.
	 *
	 * @param array<string, mixed> $change Change row.
	 * @param string               $reason Why.
	 * @return array<string, mixed>
	 */
	private function stale( array $change, string $reason ): array {
		$this->store->update_change( (string) $change['uuid'], array( 'status' => 'stale' ), array( 'applying' ) );
		return array(
			'status'    => 'stale',
			'change_id' => $change['uuid'],
			'message'   => sprintf( 'Nothing was changed. %s A new approval is needed: call %s again to request one.', $reason, $change['tool'] ),
		);
	}

	/**
	 * Response for a change that is not ready to apply.
	 *
	 * @param array<string, mixed> $change Change row.
	 * @return array<string, mixed>
	 */
	private function status_response( array $change ): array {
		$id = $change['uuid'];
		switch ( $change['status'] ) {
			case 'pending':
				return array(
					'status'      => 'pending',
					'change_id'   => $id,
					'approve_url' => $this->policy->approve_url( $id ),
					'message'     => 'Waiting for an administrator to approve it in wp-admin.',
				);
			case 'applied':
				return array(
					'status'     => 'already_applied',
					'change_id'  => $id,
					'receipt_id' => $change['receipt_id'],
				);
			case 'expired':
				return array(
					'status'    => 'approval_expired',
					'change_id' => $id,
				);
			case 'applying':
				return array(
					'status'    => 'in_progress',
					'change_id' => $id,
				);
			default:
				return array(
					'status'    => (string) $change['status'],
					'change_id' => $id,
				);
		}//end switch
	}

	/**
	 * Applies, verifies and records a receipt.
	 *
	 * @param WriteTool                 $tool       Tool.
	 * @param array<string, mixed>      $args       Arguments.
	 * @param string                    $key        Idempotency key.
	 * @param Assessment                $assessment Fresh assessment.
	 * @param string                    $approval   `policy` or `user:<id>`.
	 * @param array<string, mixed>|null $change     Change row when applying an approved change.
	 * @return array<string, mixed>
	 */
	private function execute( WriteTool $tool, array $args, string $key, Assessment $assessment, string $approval, ?array $change ): array {
		$change_id = null === $change ? '' : (string) $change['uuid'];

		try {
			$outcome = $tool->apply( $args, $assessment );
		} catch ( \Throwable $e ) {
			$message = $e instanceof ToolError ? ToolError::plain( $e ) : self::FAILED;
			$receipt = $this->receipt( $tool, $args, $key, $change_id, $assessment->tier(), $approval, 'failed', array( 'error' => $message ), Rollback::none() );
			$this->finish_change( $change_id, 'failed', $receipt );
			return array( 'error' => sprintf( '%s Receipt %s records the failure.', $message, $receipt ) );
		}

		try {
			$outcome = $tool->verify( $args, $outcome );
		} catch ( \Throwable $e ) {
			$outcome = $outcome->with_verification_failure( 'The write could not be read back.' );
		}

		$status = 'applied';
		if ( $outcome->verification_failed() ) {
			$status = 'verification_failed';
		} elseif ( array() !== $outcome->warnings() ) {
			$status = 'applied_with_warnings';
		}

		$rollback = $outcome->rollback();
		if ( Rollback::is_restorable( $rollback ) ) {
			$rollback['after'] = $outcome->after();
		}
		$result  = Redaction::compact( $outcome->result() );
		$receipt = $this->receipt( $tool, $args, $key, $change_id, $assessment->tier(), $approval, $status, $result, $rollback );
		$this->finish_change( $change_id, 'applied', $receipt );

		if ( $tool instanceof RollbackTool ) {
			$this->store->link_rollback( (string) $args['receipt_id'], $receipt );
		}

		$response = array(
			'status'     => $status,
			'tool'       => $tool->name(),
			'tier'       => $assessment->tier(),
			'approval'   => $approval,
			'receipt_id' => $receipt,
			'result'     => $result,
			'warnings'   => $outcome->warnings(),
		);
		if ( '' !== $change_id ) {
			$response['change_id'] = $change_id;
		}
		return $response;
	}

	/**
	 * Moves an applying change to its final status.
	 *
	 * @param string $change_id Change UUID or ''.
	 * @param string $status    `applied` or `failed`.
	 * @param string $receipt   Receipt UUID.
	 */
	private function finish_change( string $change_id, string $status, string $receipt ): void {
		if ( '' !== $change_id ) {
			$this->store->update_change(
				$change_id,
				array(
					'status'     => $status,
					'receipt_id' => $receipt,
				),
				array( 'applying' )
			);
		}
	}

	/**
	 * Inserts a receipt.
	 *
	 * @param WriteTool            $tool      Tool.
	 * @param array<string, mixed> $args      Arguments (redacted here).
	 * @param string               $key       Idempotency key.
	 * @param string               $change_id Change UUID or ''.
	 * @param string               $tier      Tier.
	 * @param string               $approval  `policy`, `user:<id>` or `none`.
	 * @param string               $status    Receipt status.
	 * @param array<string, mixed> $result    Result (already redacted).
	 * @param array<string, mixed> $rollback  Rollback reference.
	 * @return string Receipt UUID.
	 */
	private function receipt( WriteTool $tool, array $args, string $key, string $change_id, string $tier, string $approval, string $status, array $result, array $rollback ): string {
		return $this->store->insert_receipt(
			array(
				'change_id'       => $change_id,
				'idempotency_key' => $key,
				'user_id'         => $this->identity->user_id(),
				'client_id'       => $this->identity->client_id(),
				'client_name'     => $this->identity->client_name(),
				'tool'            => $tool->name(),
				'tier'            => $tier,
				'args_json'       => Canonical::json( Redaction::compact( $args ) ),
				'args_hash'       => Canonical::hash( $args ),
				'approval'        => $approval,
				'status'          => $status,
				'result_json'     => Canonical::json( Redaction::compact( $result ) ),
				'rollback_json'   => Canonical::json( $rollback ),
				'rolls_back'      => $tool instanceof RollbackTool ? (string) $args['receipt_id'] : '',
				'created_at'      => $this->clock->now(),
			)
		);
	}

	/**
	 * A change the current caller requested with the same client, or null.
	 *
	 * @param string $change_id Change UUID.
	 * @return array<string, mixed>|null
	 */
	private function visible_change( string $change_id ): ?array {
		$change = $this->store->change( $change_id );
		if ( null === $change
			|| (int) $change['requester_user_id'] !== $this->identity->user_id()
			|| ! hash_equals( (string) $change['client_id'], $this->identity->client_id() ) ) {
			return null;
		}
		return $change;
	}

	/**
	 * Assesses and queues every item of a plan, then queues the plan itself.
	 *
	 * @param string                           $title Title.
	 * @param array<int, array<string, mixed>> $items Items.
	 * @param string                           $key   Idempotency key of the plan.
	 * @return array<string, mixed>
	 */
	private function queue_plan( string $title, array $items, string $key ): array {
		$results = array();
		$queued  = array();
		$tier    = Risk::REVERSIBLE;
		foreach ( array_values( $items ) as $index => $item ) {
			$result    = $this->queue_plan_item( $item, self::item_key( $key, $index ) );
			$results[] = $result;
			if ( 'queued' === $result['status'] ) {
				$queued[] = $result;
				if ( Risk::rank( $result['tier'] ) > Risk::rank( $tier ) ) {
					$tier = $result['tier'];
				}
			}
		}

		if ( array() === $queued ) {
			$reasons = array();
			foreach ( $results as $index => $result ) {
				$reasons[] = sprintf( '%d: %s', $index + 1, $result['error'] );
			}
			return array( 'error' => 'No item could be queued. ' . implode( ' ', $reasons ) );
		}

		$now       = $this->clock->now();
		$args_json = Canonical::json(
			array(
				'title' => $title,
				'items' => array_column( $queued, 'change_id' ),
			)
		);
		$preview   = array();
		foreach ( $queued as $result ) {
			$preview[] = array(
				'change_id' => $result['change_id'],
				'tool'      => $result['tool'],
				'tier'      => $result['tier'],
				'summary'   => $result['summary'],
			);
		}
		$summary   = sprintf( 1 === count( $queued ) ? '%s (%d change)' : '%s (%d changes)', $title, count( $queued ) );
		$change_id = $this->store->insert_change(
			array(
				'tool'               => self::PLAN_TOOL,
				'args_json'          => $args_json,
				'args_hash'          => hash( 'sha256', $args_json ),
				'requester_user_id'  => $this->identity->user_id(),
				'client_id'          => $this->identity->client_id(),
				'client_name'        => $this->identity->client_name(),
				'tier'               => $tier,
				'summary'            => $summary,
				'preview_json'       => Canonical::json( array( 'items' => $preview ) ),
				'preconditions_json' => Canonical::json( array() ),
				'status'             => 'pending',
				'approved_by'        => 0,
				'approved_at'        => 0,
				'expires_at'         => $now + self::PENDING_TTL,
				'binding'            => '',
				'receipt_id'         => '',
				'created_at'         => $now,
			)
		);

		return array(
			'status'      => 'approval_required',
			'change_id'   => $change_id,
			'tool'        => self::PLAN_TOOL,
			'tier'        => $tier,
			'summary'     => $summary,
			'items'       => $results,
			'expires_at'  => self::time( $now + self::PENDING_TTL ),
			'approve_url' => $this->policy->approve_url( $change_id ),
			'message'     => 'Nothing was changed yet. An administrator reviews the whole plan in wp-admin (approve_url) and may leave items out. Then call apply_change with this change_id and a new idempotency_key: the approved items are applied in order, each with its own receipt.',
		);
	}

	/**
	 * Assesses and queues one plan item; never auto-applies it.
	 *
	 * @param mixed  $item Item.
	 * @param string $key  Idempotency key derived for the item.
	 * @return array<string, mixed> `status` queued, refused or invalid.
	 */
	private function queue_plan_item( $item, string $key ): array {
		$name = is_array( $item ) && isset( $item['tool'] ) && is_string( $item['tool'] ) ? $item['tool'] : '';
		$args = is_array( $item ) && isset( $item['arguments'] ) && is_array( $item['arguments'] ) ? $item['arguments'] : null;

		$invalid = static function ( string $message ) use ( $name ): array {
			return array(
				'tool'   => $name,
				'status' => 'invalid',
				'error'  => $message,
			);
		};
		if ( null === $args || array() !== array_diff( array_keys( $item ), array( 'tool', 'arguments' ) ) ) {
			return $invalid( 'Each item needs exactly "tool" and "arguments".' );
		}
		if ( ! isset( $this->tools[ $name ] ) ) {
			return $invalid( sprintf( 'Unknown tool "%s".', $name ) );
		}
		if ( array_key_exists( 'idempotency_key', $args ) || array_key_exists( 'dry_run', $args ) ) {
			return $invalid( 'Item arguments may not include idempotency_key or dry_run: the plan has one idempotency_key.' );
		}
		$tool = $this->tools[ $name ];

		try {
			Schema::validate( $tool->definition()['inputSchema'], $args + array( 'idempotency_key' => $key ) );
			$assessment = $this->assess( $tool, $args );
		} catch ( ToolError $e ) {
			return $invalid( ToolError::plain( $e ) );
		}
		if ( $assessment->is_refused() ) {
			$refused = $this->refuse( $tool, $args, $key, $assessment, '' );
			return array(
				'tool'   => $name,
				'status' => 'refused',
				'error'  => $refused['error'],
			);
		}

		$queued = $this->queue( $tool, $args, $assessment );
		return array(
			'tool'      => $name,
			'status'    => 'queued',
			'change_id' => $queued['change_id'],
			'tier'      => $queued['tier'],
			'summary'   => $queued['summary'],
		);
	}

	/**
	 * Applies the approved items of an applying plan, in order. One item failing does not stop the others.
	 *
	 * @param array<string, mixed> $plan Plan row (status applying).
	 * @param string               $key  Idempotency key of the apply call.
	 * @return array<string, mixed>
	 */
	private function apply_plan( array $plan, string $key ): array {
		$results = array();
		foreach ( self::plan_item_ids( $plan ) as $index => $id ) {
			$item = $this->store->change( $id );
			if ( null === $item ) {
				$results[] = array(
					'change_id' => $id,
					'status'    => 'failed',
					'error'     => 'Unknown change',
				);
				continue;
			}
			if ( 'approved' !== $item['status'] ) {
				$results[] = array(
					'change_id' => $id,
					'tool'      => $item['tool'],
					'status'    => (string) $item['status'],
				);
				continue;
			}

			try {
				$response = $this->apply_stored( $id, self::item_key( $key, $index ) );
			} catch ( \Throwable $e ) {
				$response = array( 'error' => self::FAILED );
			}
			$result = array(
				'change_id' => $id,
				'tool'      => $item['tool'],
			);
			if ( isset( $response['error'] ) ) {
				$result['status'] = 0 === strpos( (string) $response['error'], 'Refused:' ) ? 'refused' : 'failed';
				$result['error']  = (string) $response['error'];
			} else {
				$result['status'] = (string) $response['status'];
				foreach ( array( 'receipt_id', 'warnings', 'message' ) as $field ) {
					if ( isset( $response[ $field ] ) ) {
						$result[ $field ] = $response[ $field ];
					}
				}
			}
			$results[] = $result;
		}//end foreach

		$this->store->update_change( (string) $plan['uuid'], array( 'status' => 'applied' ), array( 'applying' ) );

		return array(
			'status'    => 'plan_applied',
			'change_id' => $plan['uuid'],
			'items'     => $results,
		);
	}

	/**
	 * Item change ids of a plan row.
	 *
	 * @param array<string, mixed> $plan Plan row.
	 * @return string[]
	 */
	public static function plan_item_ids( array $plan ): array {
		$args = Canonical::decode( (string) $plan['args_json'] );
		return isset( $args['items'] ) && is_array( $args['items'] ) ? array_values( array_map( 'strval', $args['items'] ) ) : array();
	}

	/**
	 * The idempotency key recorded for one item of a plan.
	 *
	 * @param string $key   Plan (or apply) key.
	 * @param int    $index Item position.
	 * @return string
	 */
	private static function item_key( string $key, int $index ): string {
		return 'plan.' . substr( hash( 'sha256', $key . "\n" . $index ), 0, 40 );
	}

	/**
	 * The approval binding: HMAC over change, tool, argument hash, requester, client and expiry.
	 *
	 * @param string $change_id Change UUID.
	 * @param string $tool      Tool name.
	 * @param string $args_hash SHA-256 of the stored canonical arguments.
	 * @param int    $requester Requesting user id.
	 * @param string $client    Client id.
	 * @param int    $expires   Expiry (Unix time).
	 * @return string
	 */
	private function binding( string $change_id, string $tool, string $args_hash, int $requester, string $client, int $expires ): string {
		return $this->signer->sign( implode( '|', array( 'flitagent-approval-v1', $change_id, $tool, $args_hash, (string) $requester, $client, (string) $expires ) ) );
	}

	/**
	 * Public view of a change set.
	 *
	 * @param array<string, mixed> $change Change row.
	 * @return array<string, mixed>
	 */
	private function change_view( array $change ): array {
		$view = array(
			'change_id'  => $change['uuid'],
			'status'     => $change['status'],
			'tool'       => $change['tool'],
			'tier'       => $change['tier'],
			'summary'    => $change['summary'],
			'preview'    => Redaction::compact( Canonical::decode( $change['preview_json'] ) ),
			'created_at' => self::time( (int) $change['created_at'] ),
			'expires_at' => self::time( (int) $change['expires_at'] ),
		);
		if ( 'pending' === $change['status'] ) {
			$view['approve_url'] = $this->policy->approve_url( (string) $change['uuid'] );
		}
		if ( '' !== (string) $change['receipt_id'] ) {
			$view['receipt_id'] = $change['receipt_id'];
		}
		if ( self::PLAN_TOOL === $change['tool'] ) {
			$view['items'] = array();
			foreach ( self::plan_item_ids( $change ) as $id ) {
				$item            = $this->store->change( $id );
				$view['items'][] = null === $item ? array(
					'change_id' => $id,
					'status'    => 'unknown',
				) : array(
					'change_id' => $id,
					'tool'      => $item['tool'],
					'status'    => $item['status'],
					'summary'   => $item['summary'],
				);
			}
		}
		return $view;
	}

	/**
	 * Public view of a receipt: redacted arguments and result, rollback type only.
	 *
	 * @param array<string, mixed> $row Receipt row.
	 * @return array<string, mixed>
	 */
	public static function receipt_view( array $row ): array {
		return array(
			'receipt_id'     => $row['uuid'],
			'change_id'      => $row['change_id'],
			'tool'           => $row['tool'],
			'tier'           => $row['tier'],
			'status'         => $row['status'],
			'approval'       => $row['approval'],
			'user_id'        => (int) $row['user_id'],
			'client_name'    => $row['client_name'],
			'args'           => Canonical::decode( $row['args_json'] ),
			'result'         => Canonical::decode( $row['result_json'] ),
			'rollback'       => Rollback::public_view( Canonical::decode( $row['rollback_json'] ) ),
			'rolls_back'     => $row['rolls_back'],
			'rolled_back_by' => $row['rolled_back_by'],
			'created_at'     => self::time( (int) $row['created_at'] ),
		);
	}

	/**
	 * A Unix time as ISO 8601 UTC.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function time( int $timestamp ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * An id property.
	 *
	 * @param string $description Description.
	 * @return array<string, mixed>
	 */
	private static function id_property( string $description ): array {
		return array(
			'type'        => 'string',
			'minLength'   => 1,
			'maxLength'   => 64,
			'description' => $description,
		);
	}
}
