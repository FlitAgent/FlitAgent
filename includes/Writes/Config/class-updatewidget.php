<?php
/**
 * Write tool: update_widget.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

use FlitAgent\Mcp\ToolError;
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
 * `update_widget`: replaces a widget's settings (its raw instance) through the core widgets controller. Only widget
 * types that show their instance in REST qualify. Reversible from a snapshot of the previous instance.
 */
final class UpdateWidget implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'update_widget';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: update a widget',
			'Replaces the settings of one classic widget (its instance, e.g. {"content": "<!-- wp:paragraph -->..."} for a block widget or {"title": ..., "text": ...} for a text widget). Only widget types that expose their settings in the REST API can be changed. Always returns approval_required; undo with rollback_change.',
			array(
				'widget_id' => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_-]{1,100}$',
					'description' => 'Widget id, e.g. block-2.',
				),
				'instance'  => array(
					'type'        => 'object',
					'description' => 'The complete new settings.',
				),
			),
			array( 'widget_id', 'instance' ),
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
	 * Assesses the change. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When nothing would change.
	 */
	public function assess( array $args ): Assessment {
		$id     = (string) $args['widget_id'];
		$widget = Widgets::editable( $id );
		if ( $widget instanceof Assessment ) {
			return $widget;
		}
		$before = Widgets::raw( $widget );
		if ( Canonical::json( $before ) === Canonical::json( $args['instance'] ) ) {
			throw new ToolError( sprintf( 'Widget %s already has those settings; nothing to change.', esc_textarea( $id ) ) );
		}
		return Assessment::reversible(
			sprintf( 'Replace the settings of widget %s in %s.', $id, Widgets::place( Widgets::sidebars(), $id )['sidebar'] ),
			array(
				'widget' => $id,
				'before' => Widgets::display( $before ),
				'after'  => Widgets::display( (array) $args['instance'] ),
			),
			array( 'fingerprint' => Widgets::fingerprint( $id ) ),
			false
		);
	}

	/**
	 * Writes the instance.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the widget changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$id = (string) $args['widget_id'];
		if ( Widgets::fingerprint( $id ) !== ( $assessment->preconditions()['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Widget %s changed after the request was assessed; nothing was changed.', esc_textarea( $id ) ) );
		}
		$before = Widgets::raw( (array) Widgets::widget( $id ) );
		Rest::write( 'PUT', '/wp/v2/widgets/' . rawurlencode( $id ), array( 'instance' => array( 'raw' => (array) $args['instance'] ) ) );
		return new Outcome(
			array( 'widget_id' => $id ),
			Rollback::make( Rollback::SNAPSHOT, 'widget', $id, array(), array( 'instance' => Exact::pack( $before ) ) ),
			array( 'fingerprint' => Widgets::fingerprint( $id ) )
		);
	}

	/**
	 * Reads the instance back; the widget's own update() may change it.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$widget = Widgets::widget( (string) $args['widget_id'] );
		if ( null === $widget ) {
			return $outcome->with_verification_failure( 'The widget could not be read back.' );
		}
		if ( Canonical::json( Widgets::raw( $widget ) ) === Canonical::json( $args['instance'] ) ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result( array( 'stored_verbatim' => false ) )->with_warning( 'stored_differently', 'WordPress stored the widget settings differently (the widget sanitized them).' );
	}

	/**
	 * Assesses restoring the previous instance. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$id     = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		$widget = Widgets::editable( $id );
		if ( $widget instanceof Assessment ) {
			return $widget;
		}
		return Assessment::reversible(
			sprintf( 'Restore the previous settings of widget %s.', $id ),
			array(
				'widget'   => $id,
				'current'  => Widgets::display( Widgets::raw( $widget ) ),
				'restored' => Widgets::display( (array) Exact::unpack( $rollback['before']['instance'] ) ),
			),
			array( 'fingerprint' => Widgets::fingerprint( $id ) ),
			false
		);
	}

	/**
	 * Restores the previous instance exactly, if the widget is still as assessed. It writes the snapshot into the
	 * widget_<id_base> option itself, as WP_Widget::save_settings() does, and not through the widgets REST route: that
	 * route runs the widget's update(), which may start from the current instance (WP_Widget_Text::update() does), so
	 * keys the write added would survive. The snapshot is what WordPress stored before, so it needs no sanitizing again.
	 * Like WP_Widget::save_settings(), it rewrites the whole option, read fresh just before.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the widget changed or is gone.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$id     = (string) $rollback['target']['id'];
		$parsed = wp_parse_widget_id( $id );
		$option = 'widget_' . $parsed['id_base'];
		// Read the option fresh for the check and the write, so an edit made since this request loaded its options is
		// seen: one to this widget stops the rollback, one to another widget of this type is kept.
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		if ( Widgets::fingerprint( $id ) !== ( $expected_state['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Widget %s changed after the rollback was assessed; nothing was restored.', esc_textarea( $id ) ) );
		}
		$before    = (array) Exact::unpack( $rollback['before']['instance'] );
		$instances = get_option( $option );
		if ( empty( $parsed['number'] ) || ! is_array( $instances ) || ! isset( $instances[ $parsed['number'] ] ) ) {
			throw new ToolError( sprintf( 'Widget %s no longer exists; nothing was restored.', esc_textarea( $id ) ) );
		}
		$instances[ $parsed['number'] ] = $before;
		update_option( $option, $instances );

		$outcome = new Outcome( array( 'widget_id' => $id ), Rollback::none() );
		$widget  = Widgets::widget( $id );
		if ( null === $widget || Canonical::json( Widgets::raw( $widget ) ) !== Canonical::json( $before ) ) {
			$outcome = $outcome->with_warning( 'not_exact', sprintf( 'The settings of widget %s could not be restored exactly: WordPress stored them differently. Check the widget in Appearance > Widgets.', $id ) );
		}
		return $outcome;
	}
}
