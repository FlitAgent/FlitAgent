<?php
/**
 * Write tool: delete_widget.
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
 * `delete_widget`: deletes a widget for good through the core widgets controller (`force`). Destructive. Its type,
 * settings and place are snapshotted; rollback re-creates it (new id) at the same place, also with approval.
 */
final class DeleteWidget implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_widget';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete a widget',
			'Deletes one classic widget for good (to only hide it, move_widget it to wp_inactive_widgets). Always needs an administrator\'s approval. rollback_change re-creates it (new id) in the same sidebar and position, also with approval.',
			array(
				'widget_id' => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_-]{1,100}$',
					'description' => 'Widget id, e.g. block-2.',
				),
			),
			array( 'widget_id' ),
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
	 * Assesses the deletion. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$id     = (string) $args['widget_id'];
		$widget = Widgets::editable( $id );
		if ( $widget instanceof Assessment ) {
			return $widget;
		}
		$undone = Widgets::undone_after( Widgets::after_delete( $id ), 'Move or delete those widgets first.', array( $id ) );
		if ( null !== $undone ) {
			return $undone;
		}
		$place = Widgets::place( Widgets::sidebars(), $id );
		return Assessment::destructive(
			sprintf( 'Delete widget %s (%s) from %s for good.', $id, $widget['id_base'], $place['sidebar'] ),
			array(
				'before' => array(
					'widget'   => $id,
					'sidebar'  => $place['sidebar'],
					'instance' => Widgets::display( Widgets::raw( $widget ) ),
				),
				'after'  => array( 'widget' => '(deleted)' ),
			),
			array( 'fingerprint' => Widgets::fingerprint( $id ) )
		);
	}

	/**
	 * Snapshots and deletes the widget.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the widget changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$id = (string) $args['widget_id'];
		if ( Widgets::fingerprint( $id ) !== ( $assessment->preconditions()['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Widget %s changed after the request was assessed; nothing was deleted.', esc_textarea( $id ) ) );
		}
		$widget   = (array) Widgets::widget( $id );
		$sidebars = Widgets::sidebars();
		$place    = Widgets::place( $sidebars, $id );
		Rest::write( 'DELETE', '/wp/v2/widgets/' . rawurlencode( $id ), array( 'force' => true ) );

		return new Outcome(
			array( 'widget_id' => $id ),
			Rollback::make(
				Rollback::SNAPSHOT,
				'widget',
				$id,
				array( 'sidebar' => $place['sidebar'] ),
				array(
					'id_base'  => (string) $widget['id_base'],
					'instance' => Exact::pack( Widgets::raw( $widget ) ),
					'widgets'  => isset( $sidebars[ $place['sidebar'] ] ) ? $sidebars[ $place['sidebar'] ] : array(),
				)
			),
			array( 'fingerprint' => Widgets::fingerprint( $id ) )
		);
	}

	/**
	 * Checks the widget is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return null === Widgets::widget( (string) $args['widget_id'] ) ? $outcome : $outcome->with_verification_failure( 'The widget still exists after the delete.' );
	}

	/**
	 * Assesses re-creating the widget. Destructive like the delete. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$id      = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		$refusal = Widgets::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$sidebar = (string) $rollback['refs']['sidebar'];
		if ( ! isset( Widgets::sidebars()[ $sidebar ] ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Sidebar %s no longer exists, so the widget cannot be re-created in it.', $sidebar ) );
		}
		return Assessment::destructive(
			sprintf( 'Re-create widget %s (%s) in %s (new id).', $id, $rollback['before']['id_base'], $sidebar ),
			array(
				'current'  => array( 'widget' => '(deleted)' ),
				'restored' => array(
					'sidebar'  => $sidebar,
					'instance' => Widgets::display( (array) Exact::unpack( $rollback['before']['instance'] ) ),
				),
			),
			array( 'fingerprint' => Widgets::fingerprint( $id ) )
		);
	}

	/**
	 * Re-creates the widget and puts it back at its position.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the widgets changed or WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$old = (string) $rollback['target']['id'];
		if ( Widgets::fingerprint( $old ) !== ( $expected_state['fingerprint'] ?? '' ) ) {
			throw new ToolError( 'The widgets changed after the rollback was assessed; nothing was re-created.' );
		}
		$sidebar = (string) $rollback['refs']['sidebar'];
		$created = Rest::write(
			'POST',
			'/wp/v2/widgets',
			array(
				'id_base'  => (string) $rollback['before']['id_base'],
				'sidebar'  => $sidebar,
				'instance' => array( 'raw' => (array) Exact::unpack( $rollback['before']['instance'] ) ),
			)
		);
		$new     = isset( $created['id'] ) ? (string) $created['id'] : '';
		if ( '' === $new ) {
			throw new ToolError( 'WordPress did not return the re-created widget.' );
		}

		// Back to its old position: the old order with the new id, keeping widgets added since at the end.
		$now   = Widgets::sidebars()[ $sidebar ] ?? array();
		$order = array();
		foreach ( (array) $rollback['before']['widgets'] as $widget ) {
			$widget = $widget === $old ? $new : (string) $widget;
			if ( in_array( $widget, $now, true ) ) {
				$order[] = $widget;
			}
		}
		$order = array_merge( $order, array_values( array_diff( $now, $order ) ) );
		Rest::write( 'PUT', '/wp/v2/sidebars/' . rawurlencode( $sidebar ), array( 'widgets' => $order ) );

		return new Outcome(
			array(
				'widget_id' => $new,
				'sidebar'   => $sidebar,
			),
			Rollback::none()
		);
	}
}
