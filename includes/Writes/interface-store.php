<?php
/**
 * Storage port for idempotency keys, change sets and receipts.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * Rows are flat arrays of column => scalar; JSON columns (`*_json`) are strings encoded by Gateway. Receipts are
 * append-only: the only change allowed after insert is link_rollback().
 */
interface Store {

	/**
	 * Claims an idempotency scope atomically.
	 *
	 * @param string $scope     Hash of user, client, tool and key.
	 * @param string $args_hash Hash of the arguments.
	 * @param int    $now       Unix time.
	 * @return array<string, mixed>|null Null when claimed now; otherwise the existing row (`args_hash`, `status`
	 *                                   `in_progress`|`done`, `response_json`).
	 */
	public function claim_key( string $scope, string $args_hash, int $now ): ?array;

	/**
	 * Stores the response for a claimed scope.
	 *
	 * @param string $scope         Scope.
	 * @param string $response_json Response JSON.
	 */
	public function complete_key( string $scope, string $response_json ): void;

	/**
	 * Frees a claimed scope (the call was invalid and did nothing).
	 *
	 * @param string $scope Scope.
	 */
	public function release_key( string $scope ): void;

	/**
	 * Inserts a change set.
	 *
	 * @param array<string, mixed> $row Columns without `uuid`.
	 * @return string New UUID.
	 */
	public function insert_change( array $row ): string;

	/**
	 * A change set by UUID.
	 *
	 * @param string $uuid UUID.
	 * @return array<string, mixed>|null
	 */
	public function change( string $uuid ): ?array;

	/**
	 * Updates a change set only if its status is one of `$from_statuses` (compare-and-set; an empty list allows any).
	 *
	 * @param string               $uuid          UUID.
	 * @param array<string, mixed> $fields        Columns to set.
	 * @param string[]             $from_statuses Allowed current statuses.
	 * @return bool Whether a row was updated.
	 */
	public function update_change( string $uuid, array $fields, array $from_statuses ): bool;

	/**
	 * Change sets, newest first.
	 *
	 * @param array<string, mixed> $where  Column => value equality filters.
	 * @param int                  $limit  Limit.
	 * @param int                  $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function changes( array $where, int $limit, int $offset ): array;

	/**
	 * Unexpired change sets in one status, light enough to read on every admin page: uuid and tool only, plus args_json
	 * for plans (it lists their item ids). Never another change's arguments or preview.
	 *
	 * @param string $status Status.
	 * @param int    $now    Current time: rows that expire by then are left out.
	 * @return array<int, array{uuid: string, tool: string, args_json: string}>
	 */
	public function open_change_heads( string $status, int $now ): array;

	/**
	 * Inserts a receipt.
	 *
	 * @param array<string, mixed> $row Columns without `uuid` and `rolled_back_by`.
	 * @return string New UUID.
	 */
	public function insert_receipt( array $row ): string;

	/**
	 * A receipt by UUID.
	 *
	 * @param string $uuid UUID.
	 * @return array<string, mixed>|null
	 */
	public function receipt( string $uuid ): ?array;

	/**
	 * Sets `rolled_back_by` on a receipt only if it is not set yet.
	 *
	 * @param string $receipt          Receipt rolled back.
	 * @param string $rollback_receipt Receipt of the rollback.
	 * @return bool Whether it was linked.
	 */
	public function link_rollback( string $receipt, string $rollback_receipt ): bool;

	/**
	 * Receipts, newest first.
	 *
	 * @param array<string, mixed> $where  Column => value equality filters.
	 * @param int                  $limit  Limit.
	 * @param int                  $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function receipts( array $where, int $limit, int $offset ): array;
}
