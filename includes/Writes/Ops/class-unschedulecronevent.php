<?php
/**
 * Write tool: unschedule_cron_event.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Redactor;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `unschedule_cron_event`: removes one scheduled WP-Cron event, identified by hook and next run (plus its args when
 * several events share them). Destructive: the work it would have done is skipped. The receipt keeps the event (hook,
 * args, schedule, interval, timestamp) so `rollback_change` can schedule it again. Core and FlitAgent hooks are refused.
 */
final class UnscheduleCronEvent implements WriteTool, Reversible {

	/**
	 * Hooks core (and WordPress itself) depends on; never unscheduled.
	 */
	const PROTECTED_HOOKS = array(
		'wp_version_check',
		'wp_update_plugins',
		'wp_update_themes',
		'wp_maybe_auto_update',
		'wp_scheduled_delete',
		'wp_scheduled_auto_draft_delete',
		'delete_expired_transients',
		'wp_privacy_delete_old_export_files',
		'wp_site_health_scheduled_check',
		'recovery_mode_clean_expired_keys',
		'wp_https_detection',
		'wp_update_user_counts',
		'wp_delete_temp_updater_backups',
		'wp_update_comment_type_batch',
		'wp_split_shared_term_batch',
		'wp_batch_split_terms',
		'importer_scheduled_cleanup',
		'upgrader_scheduled_cleanup',
		'publish_future_post',
		'do_pings',
	);

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'unschedule_cron_event';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: unschedule a cron event',
			'Removes one scheduled WP-Cron event, identified by hook and next_run as list_cron_events shows them (add args when several events share the hook and time). Always needs an administrator\'s approval (returns approval_required). Core hooks (update checks, trash and draft cleanup, scheduled posts and similar) and FlitAgent\'s own hooks are refused. rollback_change schedules the event again with the same args and recurrence.',
			array(
				'hook'     => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 255,
					'description' => 'Hook name of the event.',
				),
				'next_run' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 64,
					'description' => 'Next run of the event: the next_run value from list_cron_events (ISO 8601, e.g. "2026-10-03T12:00:00+00:00") or a Unix timestamp.',
				),
				'args'     => array(
					'type'        => 'array',
					'description' => 'The event\'s arguments, only needed when several events share hook and next_run.',
				),
			),
			array( 'hook', 'next_run' ),
			$this->tiers(),
			false
		);
	}

	/**
	 * Tiers.
	 *
	 * @return string[]
	 */
	public function tiers(): array {
		return array( Risk::DESTRUCTIVE );
	}

	/**
	 * Never auto-approvable.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses removing the event. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When next_run is not a time, or several events match and no args were given.
	 */
	public function assess( array $args ): Assessment {
		$hook      = (string) $args['hook'];
		$timestamp = CronEvents::timestamp( (string) $args['next_run'] );
		if ( ! current_user_can( 'manage_options' ) ) {
			return Assessment::prohibited( 'forbidden', 'Unscheduling cron events needs the manage_options capability.' );
		}
		if ( in_array( $hook, self::PROTECTED_HOOKS, true ) || 0 === strpos( $hook, 'flitagent_' ) ) {
			return Assessment::prohibited( 'protected_hook', sprintf( 'Cron hook %s belongs to WordPress core or FlitAgent and is never unscheduled.', $hook ) );
		}

		$events = CronEvents::at( $hook, $timestamp );
		if ( array_key_exists( 'args', $args ) ) {
			$key    = CronEvents::key( (array) $args['args'] );
			$events = isset( $events[ $key ] ) ? array( $key => $events[ $key ] ) : array();
		}
		if ( array() === $events ) {
			return Assessment::prohibited( 'not_found', sprintf( 'No %s event is scheduled at %s%s.', $hook, gmdate( 'c', $timestamp ), array_key_exists( 'args', $args ) ? ' with those args' : '' ) );
		}
		if ( count( $events ) > 1 ) {
			throw new ToolError( sprintf( '%d %s events are scheduled at %s with different args; pass args to choose one (list_cron_events shows them).', count( $events ), esc_textarea( $hook ), esc_textarea( gmdate( 'c', $timestamp ) ) ) );
		}

		$key   = (string) key( $events );
		$event = current( $events );
		if ( Canonical::decode( Canonical::json( array( 'args' => $event['args'] ) ) ) !== array( 'args' => $event['args'] ) ) {
			return Assessment::prohibited( 'unsupported_args', sprintf( 'The %s event has arguments (objects or unordered keys) that could not be restored exactly, so FlitAgent does not unschedule it.', $hook ) );
		}
		return Assessment::destructive(
			sprintf( 'Unschedule the %s cron event %s due %s; the work it does will not run until it is scheduled again.', null === $event['schedule'] ? 'one-off' : $event['schedule'], $hook, gmdate( 'c', $timestamp ) ),
			array(
				'hook'     => $hook,
				'next_run' => gmdate( 'c', $timestamp ),
				'schedule' => $event['schedule'],
				'interval' => $event['interval'],
				'args'     => Redactor::redact( $event['args'] ),
				'before'   => array( 'scheduled' => 'yes' ),
				'after'    => array( 'scheduled' => 'no' ),
			),
			array(
				'hook'      => $hook,
				'timestamp' => $timestamp,
				'key'       => $key,
				'event'     => Canonical::hash( $event ),
			)
		);
	}

	/**
	 * Unschedules the event.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When the event changed or could not be removed.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$expected  = $assessment->preconditions();
		$hook      = (string) $expected['hook'];
		$timestamp = (int) $expected['timestamp'];
		$events    = CronEvents::at( $hook, $timestamp );
		$event     = isset( $events[ $expected['key'] ] ) ? $events[ $expected['key'] ] : null;
		if ( null === $event || Canonical::hash( $event ) !== $expected['event'] ) {
			throw new ToolError( sprintf( 'The %s event changed while it was being unscheduled; nothing was changed.', esc_textarea( $hook ) ) );
		}

		$done = wp_unschedule_event( $timestamp, $hook, $event['args'], true );
		if ( true !== $done ) {
			throw new ToolError( sprintf( 'WordPress could not unschedule the %s event.', esc_textarea( $hook ) ) );
		}

		return new Outcome(
			array(
				'hook'        => $hook,
				'next_run'    => gmdate( 'c', $timestamp ),
				'unscheduled' => true,
			),
			Rollback::make(
				Rollback::SNAPSHOT,
				'cron_event',
				$hook,
				array(),
				array(
					'timestamp' => $timestamp,
					'args'      => $event['args'],
					'schedule'  => $event['schedule'],
					'interval'  => $event['interval'],
				)
			),
			array( 'fingerprint' => self::fingerprint( $hook, $event['args'] ) )
		);
	}

	/**
	 * Reads back that the event is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$rollback = $outcome->rollback();
		$events   = CronEvents::at( (string) $rollback['target']['id'], (int) $rollback['before']['timestamp'] );
		return isset( $events[ CronEvents::key( $rollback['before']['args'] ) ] ) ? $outcome->with_verification_failure( 'The event is still scheduled after unscheduling it.' ) : $outcome;
	}

	/**
	 * Assesses scheduling the event again. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Assessment::prohibited( 'forbidden', 'Scheduling cron events needs the manage_options capability.' );
		}
		$hook   = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		$before = isset( $rollback['before'] ) && is_array( $rollback['before'] ) ? $rollback['before'] : array();
		if ( '' === $hook || ! isset( $before['timestamp'], $before['args'] ) || ! is_array( $before['args'] ) ) {
			return Assessment::prohibited( 'no_rollback', 'This receipt does not record the event, so it cannot be scheduled again.' );
		}
		$schedule = isset( $before['schedule'] ) && is_string( $before['schedule'] ) ? $before['schedule'] : null;
		if ( null !== $schedule && ! isset( wp_get_schedules()[ $schedule ] ) ) {
			return Assessment::prohibited( 'schedule_missing', sprintf( 'The %s recurrence no longer exists (the plugin that added it may be inactive), so the event cannot be scheduled again.', $schedule ) );
		}

		return Assessment::reversible(
			sprintf( 'Schedule the %s cron event %s again for %s.', null === $schedule ? 'one-off' : $schedule, $hook, gmdate( 'c', (int) $before['timestamp'] ) ),
			array(
				'hook'     => $hook,
				'schedule' => $schedule,
				'args'     => Redactor::redact( $before['args'] ),
				'current'  => array( 'scheduled' => 'no' ),
				'restored' => array( 'scheduled' => 'yes' ),
			),
			array( 'fingerprint' => self::fingerprint( $hook, $before['args'] ) ),
			false
		);
	}

	/**
	 * Schedules the event again.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the event changed since the assessment or could not be scheduled.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$hook   = (string) $rollback['target']['id'];
		$before = $rollback['before'];
		if ( ! isset( $expected_state['fingerprint'] ) || self::fingerprint( $hook, $before['args'] ) !== $expected_state['fingerprint'] ) {
			throw new ToolError( sprintf( 'The %s events changed after the rollback was assessed; nothing was scheduled.', esc_textarea( $hook ) ) );
		}

		$timestamp = (int) $before['timestamp'];
		$done      = is_string( $before['schedule'] ) && '' !== $before['schedule']
			? wp_schedule_event( $timestamp, $before['schedule'], $hook, $before['args'], true )
			: wp_schedule_single_event( $timestamp, $hook, $before['args'], true );
		if ( true !== $done ) {
			throw new ToolError( sprintf( 'WordPress could not schedule the %s event again%s.', esc_textarea( $hook ), is_wp_error( $done ) ? ': ' . esc_textarea( ErrorText::clean( $done->get_error_message() ) ) : '' ) );
		}

		return new Outcome(
			array(
				'hook'        => $hook,
				'next_run'    => gmdate( 'c', $timestamp ),
				'rescheduled' => true,
			),
			Rollback::none()
		);
	}

	/**
	 * Fingerprint of every scheduled time of one hook with one set of args.
	 *
	 * @param string $hook Hook.
	 * @param array  $args Event args.
	 * @return string
	 */
	private static function fingerprint( string $hook, array $args ): string {
		$crons = _get_cron_array();
		$key   = CronEvents::key( $args );
		$times = array();
		foreach ( is_array( $crons ) ? $crons : array() as $timestamp => $hooks ) {
			if ( isset( $hooks[ $hook ][ $key ] ) ) {
				$times[] = (int) $timestamp;
			}
		}
		return Canonical::hash( array( $hook, $key, $times ) );
	}
}
