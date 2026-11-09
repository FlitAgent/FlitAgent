<?php
/**
 * Reads of the WP-Cron event list shared by the cron write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Finds scheduled events the way core stores them: `_get_cron_array()[ timestamp ][ hook ][ md5( serialize( args ) ) ]`.
 */
final class CronEvents {

	/**
	 * Unix time from an ISO 8601 date or a Unix timestamp string.
	 *
	 * @param string $next_run Value.
	 * @return int
	 * @throws ToolError When it is not a time.
	 */
	public static function timestamp( string $next_run ): int {
		if ( 1 === preg_match( '/^\d{1,12}$/', $next_run ) ) {
			return (int) $next_run;
		}
		$time = strtotime( $next_run );
		if ( false === $time || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}/', $next_run ) ) {
			throw new ToolError( 'next_run must be an ISO 8601 date and time (as list_cron_events returns it) or a Unix timestamp.' );
		}
		return $time;
	}

	/**
	 * Events of a hook at one time, by core's args key.
	 *
	 * @param string $hook      Hook.
	 * @param int    $timestamp Timestamp.
	 * @return array<string, array{args: array, schedule: string|null, interval: int|null}>
	 */
	public static function at( string $hook, int $timestamp ): array {
		$crons  = _get_cron_array();
		$events = array();
		$found  = is_array( $crons ) && isset( $crons[ $timestamp ][ $hook ] ) && is_array( $crons[ $timestamp ][ $hook ] ) ? $crons[ $timestamp ][ $hook ] : array();
		foreach ( $found as $key => $event ) {
			$events[ (string) $key ] = array(
				'args'     => isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array(),
				'schedule' => ! empty( $event['schedule'] ) ? (string) $event['schedule'] : null,
				'interval' => isset( $event['interval'] ) ? (int) $event['interval'] : null,
			);
		}
		return $events;
	}

	/**
	 * Core's key for an event's args.
	 *
	 * @param array $args Args.
	 * @return string
	 */
	public static function key( array $args ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- core keys cron events by md5( serialize( $args ) ).
		return md5( serialize( $args ) );
	}
}
