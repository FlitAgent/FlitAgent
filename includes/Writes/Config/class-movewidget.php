<?php
/**
 * Write tool: move_widget.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `move_widget`: puts a widget in a sidebar at a position through the core sidebars controller (which takes the widget
 * out of its old sidebar). Reversible: rollback puts the old sidebar's list back, which returns the widget to its place.
 */
final class MoveWidget implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'move_widget';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: move a widget',
			'Moves one classic widget to a sidebar (widget area), optionally at a position (1 = first; default last), or reorders it within its sidebar. Use sidebar wp_inactive_widgets to deactivate it. Always returns approval_required; undo with rollback_change.',
			array(
				'widget_id' => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_-]{1,100}$',
					'description' => 'Widget id, e.g. block-2.',
				),
				'sidebar'   => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_-]{1,100}$',
					'description' => 'Target sidebar id.',
				),
				'position'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Position in the target sidebar (1 = first).',
				),
			),
			array( 'widget_id', 'sidebar' ),
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
		return array( Risk::REVERSIBLE );
	}

	/**
	 * Never auto-approvable: widgets are public.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the move. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the sidebar is unknown or nothing would change.
	 */
	public function assess( array $args ): Assessment {
		$id     = (string) $args['widget_id'];
		$widget = Widgets::editable( $id, false );
		if ( $widget instanceof Assessment ) {
			return $widget;
		}
		$sidebars = Widgets::sidebars();
		$list     = self::target_list( $sidebars, $args );
		$from     = Widgets::place( $sidebars, $id );
		$to       = array(
			'sidebar'  => (string) $args['sidebar'],
			'position' => (int) array_search( $id, $list, true ) + 1,
		);
		if ( $from === $to ) {
			throw new ToolError( sprintf( 'Widget %s is already at position %d of %s; nothing to change.', esc_textarea( $id ), (int) $to['position'], esc_textarea( $to['sidebar'] ) ) );
		}
		$undone = Widgets::undone_after( Widgets::after_put( $to['sidebar'], $list ), 'Move the widget to another widget area instead, or delete it with delete_widget.' );
		if ( null !== $undone ) {
			return $undone;
		}
		return Assessment::reversible(
			sprintf( 'Move widget %s from %s (position %d) to %s (position %d).', $id, $from['sidebar'], $from['position'], $to['sidebar'], $to['position'] ),
			array(
				'before' => array_map( 'strval', $from ),
				'after'  => array_map( 'strval', $to ),
			),
			array( 'fingerprint' => Widgets::fingerprint( $id ) ),
			false
		);
	}

	/**
	 * Moves the widget.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the sidebars changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$id = (string) $args['widget_id'];
		if ( Widgets::fingerprint( $id ) !== ( $assessment->preconditions()['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'The widgets changed after the request was assessed; widget %s was not moved.', esc_textarea( $id ) ) );
		}
		$sidebars = Widgets::sidebars();
		$from     = Widgets::place( $sidebars, $id );
		Rest::write( 'PUT', '/wp/v2/sidebars/' . rawurlencode( (string) $args['sidebar'] ), array( 'widgets' => self::target_list( $sidebars, $args ) ) );

		return new Outcome(
			array(
				'widget_id' => $id,
				'sidebar'   => (string) $args['sidebar'],
			),
			Rollback::make(
				Rollback::SNAPSHOT,
				'widget',
				$id,
				array( 'sidebar' => $from['sidebar'] ) + ThemeBound::refs(),
				array( 'widgets' => isset( $sidebars[ $from['sidebar'] ] ) ? $sidebars[ $from['sidebar'] ] : array() )
			),
			array( 'fingerprint' => Widgets::fingerprint( $id ) )
		);
	}

	/**
	 * Reads the position back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$place = Widgets::place( Widgets::sidebars(), (string) $args['widget_id'] );
		return $place['sidebar'] === (string) $args['sidebar'] ? $outcome : $outcome->with_verification_failure( 'The widget is not in the requested sidebar after the move.' );
	}

	/**
	 * Assesses moving the widget back. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$id      = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		$refusal = ThemeBound::refusal( $rollback );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$widget = Widgets::editable( $id, false );
		if ( $widget instanceof Assessment ) {
			return $widget;
		}
		$sidebar  = (string) $rollback['refs']['sidebar'];
		$sidebars = Widgets::sidebars();
		if ( ! isset( $sidebars[ $sidebar ] ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Sidebar %s no longer exists.', $sidebar ) );
		}
		$list  = self::restored_list( $sidebars[ $sidebar ], $id, (array) $rollback['before']['widgets'] );
		$place = Widgets::place( $sidebars, $id );
		$back  = (int) array_search( $id, $list, true ) + 1;
		if ( $sidebar === $place['sidebar'] && $back === $place['position'] ) {
			return Assessment::prohibited( 'already_restored', sprintf( 'Widget %s is already at position %d of %s; nothing to roll back.', $id, $place['position'], $sidebar ) );
		}
		$undone = Widgets::undone_after( Widgets::after_put( $sidebar, $list ), 'Arrange the widgets in Appearance > Widgets instead.' );
		if ( null !== $undone ) {
			return $undone;
		}
		return Assessment::reversible(
			sprintf( 'Move widget %s back to %s.', $id, $sidebar ),
			array(
				'current'  => array_map( 'strval', Widgets::place( $sidebars, $id ) ),
				'restored' => array(
					'sidebar'  => $sidebar,
					'position' => (string) $back,
				),
			),
			array( 'fingerprint' => Widgets::fingerprint( $id ) ),
			false
		);
	}

	/**
	 * Moves the widget back into its old sidebar, if the widgets are still as assessed. Only this widget moves: the
	 * sidebar keeps its current widgets (a widget deleted since is not put back, one added since stays), and the widget
	 * goes next to the neighbours it had before the move.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the widgets changed or WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$id = (string) $rollback['target']['id'];
		if ( Widgets::fingerprint( $id ) !== ( $expected_state['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'The widgets changed after the rollback was assessed; widget %s was not moved back.', esc_textarea( $id ) ) );
		}
		$sidebar  = (string) $rollback['refs']['sidebar'];
		$sidebars = Widgets::sidebars();
		if ( ! isset( $sidebars[ $sidebar ] ) ) {
			throw new ToolError( sprintf( 'Sidebar %s no longer exists; widget %s was not moved back.', esc_textarea( $sidebar ), esc_textarea( $id ) ) );
		}
		Rest::write( 'PUT', '/wp/v2/sidebars/' . rawurlencode( $sidebar ), array( 'widgets' => self::restored_list( $sidebars[ $sidebar ], $id, (array) $rollback['before']['widgets'] ) ) );
		return new Outcome(
			array(
				'widget_id' => $id,
				'sidebar'   => $sidebar,
			),
			Rollback::none()
		);
	}

	/**
	 * A sidebar's current widgets with one widget put back where it was: after the nearest widget that came before it
	 * in the old list and is still in the sidebar, else before the nearest one that came after it, else at its old
	 * position (at most the end). Nothing else in the list changes, so no deleted widget comes back.
	 *
	 * @param string[] $current Widget ids now in the sidebar.
	 * @param string   $id      Widget to put back.
	 * @param mixed[]  $old     The sidebar's widget ids before the move.
	 * @return string[]
	 */
	private static function restored_list( array $current, string $id, array $old ): array {
		$list  = array_values( array_diff( array_map( 'strval', $current ), array( $id ) ) );
		$old   = array_values( array_map( 'strval', $old ) );
		$index = array_search( $id, $old, true );
		$index = false === $index ? count( $old ) : (int) $index;

		$at = null;
		for ( $i = $index - 1; $i >= 0 && null === $at; --$i ) {
			$found = array_search( $old[ $i ], $list, true );
			$at    = false === $found ? null : (int) $found + 1;
		}
		$total = count( $old );
		for ( $i = $index + 1; $i < $total && null === $at; ++$i ) {
			$found = array_search( $old[ $i ], $list, true );
			$at    = false === $found ? null : (int) $found;
		}
		array_splice( $list, null === $at ? min( $index, count( $list ) ) : $at, 0, array( $id ) );
		return $list;
	}

	/**
	 * The target sidebar's widget list after the move.
	 *
	 * @param array<string, string[]> $sidebars Sidebars.
	 * @param array<string, mixed>    $args     Arguments.
	 * @return string[]
	 * @throws ToolError When the sidebar does not exist.
	 */
	private static function target_list( array $sidebars, array $args ): array {
		$sidebar = (string) $args['sidebar'];
		if ( ! isset( $sidebars[ $sidebar ] ) ) {
			throw new ToolError( sprintf( '%s is not a widget area of the active theme. Areas: %s.', esc_textarea( $sidebar ), esc_textarea( implode( ', ', array_keys( $sidebars ) ) ) ) );
		}
		$id   = (string) $args['widget_id'];
		$list = array_values( array_diff( $sidebars[ $sidebar ], array( $id ) ) );
		$at   = isset( $args['position'] ) ? min( (int) $args['position'] - 1, count( $list ) ) : count( $list );
		array_splice( $list, $at, 0, array( $id ) );
		return $list;
	}
}
