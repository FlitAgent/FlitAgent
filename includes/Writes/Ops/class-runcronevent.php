<?php
/**
 * Write tool: run_cron_event.
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
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `run_cron_event`: runs one scheduled WP-Cron event now, like `wp-cron.php`: the due occurrence is unscheduled, a
 * recurring event is moved to its next occurrence, then the hook's callbacks run with the event's args. Destructive:
 * the callbacks can do anything and cannot be undone, so it always needs approval and has no rollback. FlitAgent's own
 * hooks and `wp_maybe_auto_update` (which installs updates without a per-change approval; use update_plugin, update_theme or update_core) are refused.
 */
final class RunCronEvent implements WriteTool {

	/**
	 * Hooks never run on request.
	 */
	const REFUSED_HOOKS = array( 'wp_maybe_auto_update' );

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'run_cron_event';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: run a cron event now',
			'Runs one scheduled WP-Cron event now, identified by hook and next_run as list_cron_events shows them (add args when several events share the hook and time), the way WordPress\'s own cron runner does: a recurring event moves to its next occurrence, then the hook\'s callbacks run. Always needs an administrator\'s approval (returns approval_required). Cannot be rolled back: whatever the callbacks did stays done. FlitAgent\'s own hooks and wp_maybe_auto_update (automatic updates) are refused. Output the callbacks print is discarded.',
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
	 * Assesses running the event. Read-only: no callback runs.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When next_run is not a time, or several events match and no args were given.
	 */
	public function assess( array $args ): Assessment {
		$hook      = (string) $args['hook'];
		$timestamp = CronEvents::timestamp( (string) $args['next_run'] );
		if ( ! current_user_can( 'manage_options' ) ) {
			return Assessment::prohibited( 'forbidden', 'Running cron events needs the manage_options capability.' );
		}
		if ( in_array( $hook, self::REFUSED_HOOKS, true ) || 0 === strpos( $hook, 'flitagent_' ) ) {
			return Assessment::prohibited( 'protected_hook', sprintf( 'Cron hook %s is never run on request (FlitAgent\'s own hooks, and automatic updates, which would skip per-change approval; use update_plugin, update_theme or update_core).', $hook ) );
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
		if ( false === has_action( $hook ) ) {
			return Assessment::prohibited( 'no_callbacks', sprintf( 'Nothing is hooked to %s (the plugin that scheduled it may be inactive), so running it would do nothing.', $hook ) );
		}

		$key   = (string) key( $events );
		$event = current( $events );
		return Assessment::destructive(
			sprintf( 'Run the %s cron event %s due %s now; its callbacks can change anything and cannot be undone.', null === $event['schedule'] ? 'one-off' : $event['schedule'], $hook, gmdate( 'c', $timestamp ) ),
			array(
				'hook'     => $hook,
				'next_run' => gmdate( 'c', $timestamp ),
				'schedule' => $event['schedule'],
				'args'     => Redactor::redact( $event['args'] ),
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
	 * Moves the event on, then runs its callbacks.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When the event changed, could not be moved, or a callback failed.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$expected  = $assessment->preconditions();
		$hook      = (string) $expected['hook'];
		$timestamp = (int) $expected['timestamp'];
		$events    = CronEvents::at( $hook, $timestamp );
		$event     = isset( $events[ $expected['key'] ] ) ? $events[ $expected['key'] ] : null;
		if ( null === $event || Canonical::hash( $event ) !== $expected['event'] ) {
			throw new ToolError( sprintf( 'The %s event changed before it could run; nothing was run.', esc_textarea( $hook ) ) );
		}

		// Unschedule first, then reschedule: core counts the next run from now, which for an event run before it is due
		// can be the due time itself, and unscheduling after rescheduling would then remove the new occurrence.
		if ( true !== wp_unschedule_event( $timestamp, $hook, $event['args'], true ) ) {
			throw new ToolError( sprintf( 'WordPress could not unschedule the due %s event; nothing was run.', esc_textarea( $hook ) ) );
		}
		$next = null;
		if ( null !== $event['schedule'] ) {
			if ( true !== wp_reschedule_event( $timestamp, $event['schedule'], $hook, $event['args'], true ) ) {
				wp_schedule_event( $timestamp, $event['schedule'], $hook, $event['args'] );
				throw new ToolError( sprintf( 'WordPress could not move the %s event to its next occurrence; it was put back and nothing was run.', esc_textarea( $hook ) ) );
			}
			$next = self::next_time( $hook, $event['args'] );
		}

		$ran_at = time();
		$level  = ob_get_level();
		ob_start();
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- runs the scheduled event's own hook, as wp-cron.php does.
			do_action_ref_array( $hook, $event['args'] );
		} catch ( \Throwable $error ) {
			self::close_buffers( $level );
			throw new ToolError( sprintf( 'A callback of %s failed: %s The event was already moved on as if it had run.', esc_textarea( $hook ), esc_textarea( ErrorText::of( $error ) ) ) );
		}
		$output = self::close_buffers( $level );

		return new Outcome(
			array(
				'hook'          => $hook,
				'ran'           => true,
				'ran_at'        => gmdate( 'c', $ran_at ),
				'scheduled_for' => gmdate( 'c', $timestamp ),
				'next_run'      => null === $next ? null : gmdate( 'c', $next ),
				'output_bytes'  => strlen( $output ),
			),
			Rollback::none()
		);
	}

	/**
	 * Nothing to read back: the callbacks' effects are their own.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return $outcome;
	}

	/**
	 * The earliest time at which the hook is scheduled with these args, or null.
	 *
	 * @param string $hook Hook.
	 * @param array  $args Args.
	 * @return int|null
	 */
	private static function next_time( string $hook, array $args ): ?int {
		$crons = _get_cron_array();
		$key   = CronEvents::key( $args );
		foreach ( is_array( $crons ) ? $crons : array() as $timestamp => $hooks ) {
			if ( isset( $hooks[ $hook ][ $key ] ) ) {
				return (int) $timestamp;
			}
		}
		return null;
	}

	/**
	 * Closes the output buffers opened since $level and returns what they held.
	 *
	 * @param int $level Buffer level before running callbacks.
	 * @return string
	 */
	private static function close_buffers( int $level ): string {
		$output = '';
		while ( ob_get_level() > $level ) {
			$output = (string) ob_get_clean() . $output;
		}
		return $output;
	}
}
