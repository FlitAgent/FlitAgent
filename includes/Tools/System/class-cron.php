<?php
/**
 * Scheduled-event reader.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\System;

use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Flattens the cron array into events. Reads only; never spawns WP-Cron.
 */
final class Cron {

	/**
	 * All scheduled events, soonest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function events(): array {
		$crons     = _get_cron_array();
		$schedules = wp_get_schedules();
		$now       = time();
		$events    = array();

		foreach ( is_array( $crons ) ? $crons : array() as $timestamp => $hooks ) {
			foreach ( is_array( $hooks ) ? $hooks : array() as $hook => $instances ) {
				foreach ( is_array( $instances ) ? $instances : array() as $event ) {
					$schedule = ! empty( $event['schedule'] ) ? (string) $event['schedule'] : null;
					$interval = isset( $event['interval'] ) ? (int) $event['interval'] : null;
					if ( null === $interval && null !== $schedule && isset( $schedules[ $schedule ]['interval'] ) ) {
						$interval = (int) $schedules[ $schedule ]['interval'];
					}
					$events[] = array(
						'hook'     => (string) $hook,
						'next_run' => gmdate( 'c', (int) $timestamp ),
						'schedule' => $schedule,
						'interval' => $interval,
						'overdue'  => (int) $timestamp < $now,
						'args'     => Redactor::redact( isset( $event['args'] ) ? $event['args'] : array() ),
					);
				}
			}
		}

		usort(
			$events,
			static function ( $a, $b ) {
				return strcmp( $a['next_run'], $b['next_run'] );
			}
		);

		return $events;
	}
}
