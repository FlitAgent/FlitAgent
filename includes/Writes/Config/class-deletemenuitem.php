<?php
/**
 * Write tool: delete_menu_item.
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
 * `delete_menu_item`: deletes one menu item without child items through the core menu-items controller. Reversible:
 * the item's fields are snapshotted and rollback re-creates it (new id) in the same menu, parent and position.
 */
final class DeleteMenuItem implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_menu_item';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete a menu item',
			'Deletes one menu item. Items with child items are refused (delete or move the children first). Always returns approval_required; rollback_change re-creates the item (new id) at the same place.',
			array(
				'item_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Menu item id.',
				),
			),
			array( 'item_id' ),
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
	 * Never auto-approvable: menus are public navigation.
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
		$id      = (int) $args['item_id'];
		$refusal = Menus::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$item = Menus::item( $id );
		if ( null === $item ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Menu item %d was not found or you cannot edit it.', $id ) );
		}
		$fields = Menus::fields( $item );
		if ( array() !== Menus::children( (array) Menus::items( $fields['menus'] ), $id ) ) {
			return Assessment::prohibited( 'has_children', sprintf( 'Menu item %d has child items; deleting it would orphan them. Delete or move them first.', $id ) );
		}
		return Assessment::reversible(
			sprintf( 'Delete menu item "%s" (%d) from menu %s.', $fields['title'], $id, Menus::label( $fields['menus'] ) ),
			array(
				'before' => array(
					'item' => (string) $fields['title'],
					'link' => 'custom' === $fields['type'] ? (string) $fields['url'] : $fields['object'] . ' ' . $fields['object_id'],
				),
				'after'  => array( 'item' => '(deleted)' ),
			),
			array( 'fingerprint' => Menus::item_fingerprint( $id ) ),
			false
		);
	}

	/**
	 * Snapshots and deletes the item.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the item changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$id = (int) $args['item_id'];
		if ( Menus::item_fingerprint( $id ) !== ( $assessment->preconditions()['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Menu item %d changed after the request was assessed; nothing was deleted.', (int) $id ) );
		}
		$fields = Menus::fields( (array) Menus::item( $id ) );
		Rest::write( 'DELETE', '/wp/v2/menu-items/' . $id, array( 'force' => true ) );

		return new Outcome(
			array( 'item_id' => $id ),
			Rollback::make( Rollback::SNAPSHOT, 'menu_item', $id, array( 'menu_id' => $fields['menus'] ), array( 'fields' => $fields ) ),
			array( 'fingerprint' => Menus::item_fingerprint( $id ) )
		);
	}

	/**
	 * Checks the item is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return null === Menus::item( (int) $args['item_id'] ) ? $outcome : $outcome->with_verification_failure( 'The menu item still exists after the delete.' );
	}

	/**
	 * Assesses re-creating the item. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$id      = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$refusal = Menus::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$fields  = isset( $rollback['before']['fields'] ) ? (array) $rollback['before']['fields'] : array();
		$menu_id = isset( $fields['menus'] ) ? (int) $fields['menus'] : 0;
		if ( null === Menus::menu( $menu_id ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Menu %d no longer exists, so the item cannot be re-created in it.', $menu_id ) );
		}
		return Assessment::reversible(
			sprintf( 'Re-create menu item "%s" in menu %s (new id).', isset( $fields['title'] ) ? $fields['title'] : '', Menus::label( $menu_id ) ),
			array(
				'current'  => array( 'item' => '(deleted)' ),
				'restored' => array( 'item' => isset( $fields['title'] ) ? (string) $fields['title'] : '' ),
			),
			array( 'fingerprint' => Menus::item_fingerprint( $id ) ),
			false
		);
	}

	/**
	 * Re-creates the item at its menu, parent (top level if the parent is gone) and position.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		if ( Menus::item_fingerprint( (int) $rollback['target']['id'] ) !== ( $expected_state['fingerprint'] ?? '' ) ) {
			throw new ToolError( 'The menu item changed after the rollback was assessed; nothing was re-created.' );
		}
		$fields   = (array) $rollback['before']['fields'];
		$orphaned = $fields['parent'] > 0 && null === Menus::item( (int) $fields['parent'] );
		if ( $orphaned ) {
			$fields['parent'] = 0;
		}
		list( $created, $ordered ) = Menus::create_item( $fields );
		$outcome                   = new Outcome( array( 'item_id' => $created ), Rollback::none() );
		if ( $orphaned ) {
			$outcome = $outcome->with_warning( 'parent_gone', 'The item\'s parent no longer exists, so it was re-created at the top level.' );
		}
		if ( ! $ordered ) {
			$outcome = $outcome->with_warning( 'order_not_restored', sprintf( 'The item was re-created, but WordPress could not put it back at menu order %d, so it is at menu order 1.', (int) $fields['menu_order'] ) );
		}
		return $outcome;
	}
}
