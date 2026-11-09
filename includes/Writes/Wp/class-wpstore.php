<?php
/**
 * Database store for the write layer.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Wp;

use FlitAgent\Writes\Gateway;
use FlitAgent\Writes\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Stores idempotency keys, change sets and receipts with $wpdb. Every query is prepared; column names come from fixed
 * lists. Receipts are never deleted or rewritten: the only receipt update sets `rolled_back_by` once.
 */
final class WpStore implements Store {

	/**
	 * Change set columns (besides id and uuid).
	 */
	const CHANGE_COLUMNS = array( 'tool', 'args_json', 'args_hash', 'requester_user_id', 'client_id', 'client_name', 'tier', 'summary', 'preview_json', 'preconditions_json', 'status', 'approved_by', 'approved_at', 'expires_at', 'binding', 'receipt_id', 'created_at' );

	/**
	 * Receipt columns written on insert (besides id, uuid and rolled_back_by).
	 */
	const RECEIPT_COLUMNS = array( 'change_id', 'idempotency_key', 'user_id', 'client_id', 'client_name', 'tool', 'tier', 'args_json', 'args_hash', 'approval', 'status', 'result_json', 'rollback_json', 'rolls_back', 'created_at' );

	/**
	 * Integer columns, cast on read.
	 */
	const INT_COLUMNS = array( 'id', 'requester_user_id', 'approved_by', 'approved_at', 'expires_at', 'created_at', 'user_id' );

	/**
	 * Columns list queries may filter on.
	 */
	const FILTERS = array( 'status', 'tool', 'requester_user_id' );

	/**
	 * Claims an idempotency scope with INSERT IGNORE on its unique key.
	 *
	 * @param string $scope     Scope.
	 * @param string $args_hash Arguments hash.
	 * @param int    $now       Unix time.
	 * @return array<string, mixed>|null
	 * @throws \RuntimeException When the scope can be neither claimed nor read.
	 */
	public function claim_key( string $scope, string $args_hash, int $now ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FlitAgent's own table; names from constants, values prepared.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FlitAgent's own table; names from constants, values prepared.
				"INSERT IGNORE INTO {$this->table( 'idempotency' )} (scope, args_hash, status, response_json, created_at) VALUES (%s, %s, %s, %s, %d)",
				$scope,
				$args_hash,
				'in_progress',
				'',
				$now
			)
		);
		if ( 1 === (int) $inserted ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FlitAgent's own table; names from constants, values prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT args_hash, status, response_json FROM {$this->table( 'idempotency' )} WHERE scope = %s", $scope ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			throw new \RuntimeException( 'Could not claim the idempotency key.' );
		}
		return $row;
	}

	/**
	 * Stores the response of a claimed scope.
	 *
	 * @param string $scope         Scope.
	 * @param string $response_json Response JSON.
	 */
	public function complete_key( string $scope, string $response_json ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FlitAgent's own table; names from constants, values prepared.
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table( 'idempotency' )} SET status = %s, response_json = %s WHERE scope = %s", 'done', $response_json, $scope ) );
	}

	/**
	 * Frees a claimed scope.
	 *
	 * @param string $scope Scope.
	 */
	public function release_key( string $scope ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FlitAgent's own table; names from constants, values prepared.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table( 'idempotency' )} WHERE scope = %s AND status = %s", $scope, 'in_progress' ) );
	}

	/**
	 * Inserts a change set.
	 *
	 * @param array<string, mixed> $row Columns.
	 * @return string UUID.
	 */
	public function insert_change( array $row ): string {
		return $this->insert( 'changes', self::CHANGE_COLUMNS, $row );
	}

	/**
	 * A change set.
	 *
	 * @param string $uuid UUID.
	 * @return array<string, mixed>|null
	 */
	public function change( string $uuid ): ?array {
		return $this->find( 'changes', $uuid );
	}

	/**
	 * Compare-and-set update of a change set.
	 *
	 * @param string               $uuid          UUID.
	 * @param array<string, mixed> $fields        Columns to set.
	 * @param string[]             $from_statuses Allowed current statuses (empty = any).
	 * @return bool
	 * @throws \InvalidArgumentException On an unknown column.
	 */
	public function update_change( string $uuid, array $fields, array $from_statuses ): bool {
		global $wpdb;
		$sets   = array();
		$values = array();
		foreach ( $fields as $column => $value ) {
			if ( ! in_array( $column, self::CHANGE_COLUMNS, true ) ) {
				throw new \InvalidArgumentException( 'Unknown change column.' );
			}
			$sets[]   = $column . ' = ' . ( is_int( $value ) ? '%d' : '%s' );
			$values[] = $value;
		}
		$sql      = "UPDATE {$this->table( 'changes' )} SET " . implode( ', ', $sets ) . ' WHERE uuid = %s';
		$values[] = $uuid;
		if ( array() !== $from_statuses ) {
			$sql   .= ' AND status IN (' . implode( ',', array_fill( 0, count( $from_statuses ), '%s' ) ) . ')';
			$values = array_merge( $values, array_values( $from_statuses ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FlitAgent's own table; names from constants, values prepared.
		$updated = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Change sets, newest first.
	 *
	 * @param array<string, mixed> $where  Filters.
	 * @param int                  $limit  Limit.
	 * @param int                  $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function changes( array $where, int $limit, int $offset ): array {
		return $this->select( 'changes', $where, $limit, $offset );
	}

	/**
	 * Unexpired change sets in one status: uuid and tool, plus args_json for plans.
	 *
	 * @param string $status Status.
	 * @param int    $now    Current time.
	 * @return array<int, array{uuid: string, tool: string, args_json: string}>
	 */
	public function open_change_heads( string $status, int $now ): array {
		global $wpdb;
		$table = $this->table( 'changes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FlitAgent's own table; name from constants, values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT uuid, tool FROM {$table} WHERE status = %s AND expires_at > %d", $status, $now ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FlitAgent's own table; name from constants, values prepared.
		$plans = $wpdb->get_results( $wpdb->prepare( "SELECT uuid, args_json FROM {$table} WHERE status = %s AND expires_at > %d AND tool = %s", $status, $now, Gateway::PLAN_TOOL ), ARRAY_A );
		$args  = array_column( is_array( $plans ) ? $plans : array(), 'args_json', 'uuid' );

		$heads = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$heads[] = array(
				'uuid'      => (string) $row['uuid'],
				'tool'      => (string) $row['tool'],
				'args_json' => (string) ( $args[ $row['uuid'] ] ?? '' ),
			);
		}
		return $heads;
	}

	/**
	 * Inserts a receipt.
	 *
	 * @param array<string, mixed> $row Columns.
	 * @return string UUID.
	 */
	public function insert_receipt( array $row ): string {
		return $this->insert( 'receipts', self::RECEIPT_COLUMNS, $row );
	}

	/**
	 * A receipt.
	 *
	 * @param string $uuid UUID.
	 * @return array<string, mixed>|null
	 */
	public function receipt( string $uuid ): ?array {
		return $this->find( 'receipts', $uuid );
	}

	/**
	 * Links a rollback receipt, once.
	 *
	 * @param string $receipt          Receipt rolled back.
	 * @param string $rollback_receipt Rollback receipt.
	 * @return bool
	 */
	public function link_rollback( string $receipt, string $rollback_receipt ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FlitAgent's own table; names from constants, values prepared.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table( 'receipts' )} SET rolled_back_by = %s WHERE uuid = %s AND rolled_back_by = ''", $rollback_receipt, $receipt ) );
		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Receipts, newest first.
	 *
	 * @param array<string, mixed> $where  Filters.
	 * @param int                  $limit  Limit.
	 * @param int                  $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function receipts( array $where, int $limit, int $offset ): array {
		return $this->select( 'receipts', $where, $limit, $offset );
	}

	/**
	 * Full table name.
	 *
	 * @param string $table Table key.
	 * @return string
	 */
	private function table( string $table ): string {
		return Tables::name( $table );
	}

	/**
	 * Inserts a row with a new UUID.
	 *
	 * @param string               $table   Table key.
	 * @param string[]             $columns Allowed columns.
	 * @param array<string, mixed> $row     Row.
	 * @return string UUID.
	 * @throws \RuntimeException When the insert fails.
	 */
	private function insert( string $table, array $columns, array $row ): string {
		global $wpdb;
		$data    = array( 'uuid' => wp_generate_uuid4() );
		$formats = array( '%s' );
		foreach ( $columns as $column ) {
			$value           = array_key_exists( $column, $row ) ? $row[ $column ] : '';
			$data[ $column ] = $value;
			$formats[]       = is_int( $value ) ? '%d' : '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- FlitAgent's own table; names from constants, values prepared.
		if ( false === $wpdb->insert( $this->table( $table ), $data, $formats ) ) {
			throw new \RuntimeException( 'Could not store the write record.' );
		}
		return $data['uuid'];
	}

	/**
	 * A row by UUID.
	 *
	 * @param string $table Table key.
	 * @param string $uuid  UUID.
	 * @return array<string, mixed>|null
	 */
	private function find( string $table, string $uuid ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FlitAgent's own table; names from constants, values prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table( $table )} WHERE uuid = %s", $uuid ), ARRAY_A );
		return is_array( $row ) ? self::typed( $row ) : null;
	}

	/**
	 * Rows, newest first.
	 *
	 * @param string               $table  Table key.
	 * @param array<string, mixed> $where  Filters.
	 * @param int                  $limit  Limit.
	 * @param int                  $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	private function select( string $table, array $where, int $limit, int $offset ): array {
		global $wpdb;
		list( $clause, $values ) = $this->where( $where );
		$values[]                = max( 0, $limit );
		$values[]                = max( 0, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FlitAgent's own table; names from constants, values prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table( $table )}" . $clause . ' ORDER BY id DESC LIMIT %d OFFSET %d', $values ), ARRAY_A );
		return array_map( array( self::class, 'typed' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * WHERE clause from equality filters on allowed columns.
	 *
	 * @param array<string, mixed> $where Filters.
	 * @return array{0: string, 1: array<int, mixed>}
	 * @throws \InvalidArgumentException On an unknown column.
	 */
	private function where( array $where ): array {
		$parts  = array();
		$values = array();
		foreach ( $where as $column => $value ) {
			if ( ! in_array( $column, self::FILTERS, true ) ) {
				throw new \InvalidArgumentException( 'Unknown filter column.' );
			}
			$parts[]  = $column . ' = ' . ( is_int( $value ) ? '%d' : '%s' );
			$values[] = $value;
		}
		return array( array() === $parts ? '' : ' WHERE ' . implode( ' AND ', $parts ), $values );
	}

	/**
	 * Casts integer columns.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function typed( array $row ): array {
		foreach ( self::INT_COLUMNS as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}
		return $row;
	}
}
