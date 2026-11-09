<?php
/**
 * Write tool: delete_menu.
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
 * `delete_menu`: deletes a navigation menu with its items through the core menus controller. Destructive: the menu
 * disappears from every location. The menu, its locations and items are snapshotted; rollback re-creates them (with new
 * ids, parents re-linked), also with approval.
 */
final class DeleteMenu implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_menu';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete a menu',
			'Deletes a navigation menu and all its items; it disappears from its theme locations. Always needs an administrator\'s approval (the preview shows the item count and locations). rollback_change re-creates the menu, its items and locations (new ids), also with approval.',
			array(
				'menu_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Menu id.',
				),
			),
			array( 'menu_id' ),
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
		$id      = (int) $args['menu_id'];
		$refusal = Menus::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$menu = Menus::menu( $id );
		if ( null === $menu ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Menu %d was not found or you cannot edit it.', $id ) );
		}
		$items = Menus::items( $id );
		if ( null === $items ) {
			return Assessment::prohibited( 'too_large', sprintf( 'Menu %d has more than %d items, too many to snapshot for a rollback.', $id, Menus::MAX_ITEMS ) );
		}
		$locations = isset( $menu['locations'] ) ? (array) $menu['locations'] : array();

		return Assessment::destructive(
			sprintf( 'Delete menu "%s" (%d) with its %d items%s.', $menu['name'], $id, count( $items ), array() === $locations ? '' : '; it disappears from ' . implode( ', ', $locations ) ),
			array(
				'before' => array(
					'menu'      => (string) $menu['name'],
					'items'     => (string) count( $items ),
					'locations' => implode( ', ', $locations ),
				),
				'after'  => array( 'menu' => '(deleted)' ),
			),
			array( 'fingerprint' => Menus::menu_fingerprint( $id ) )
		);
	}

	/**
	 * Snapshots and deletes the menu.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the menu changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$id = (int) $args['menu_id'];
		if ( Menus::menu_fingerprint( $id ) !== ( $assessment->preconditions()['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Menu %d changed after the request was assessed; nothing was deleted.', (int) $id ) );
		}
		$menu  = (array) Menus::menu( $id );
		$items = array();
		foreach ( (array) Menus::items( $id ) as $item ) {
			$items[ (int) $item['id'] ] = Menus::fields( $item );
		}

		Rest::write( 'DELETE', '/wp/v2/menus/' . $id, array( 'force' => true ) );

		return new Outcome(
			array(
				'menu_id'       => $id,
				'items_deleted' => count( $items ),
			),
			Rollback::make(
				Rollback::SNAPSHOT,
				'menu',
				$id,
				array(),
				array(
					'name'        => (string) $menu['name'],
					'description' => isset( $menu['description'] ) ? (string) $menu['description'] : '',
					'locations'   => isset( $menu['locations'] ) ? array_values( (array) $menu['locations'] ) : array(),
					'items'       => $items,
				)
			),
			array( 'fingerprint' => Menus::menu_fingerprint( $id ) )
		);
	}

	/**
	 * Checks the menu is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return null === Menus::menu( (int) $args['menu_id'] ) ? $outcome : $outcome->with_verification_failure( 'The menu still exists after the delete.' );
	}

	/**
	 * Assesses re-creating the menu. Destructive like the delete. Read-only.
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
		$before = isset( $rollback['before'] ) && is_array( $rollback['before'] ) ? $rollback['before'] : array();
		$name   = isset( $before['name'] ) ? (string) $before['name'] : '';
		foreach ( Menus::all() as $menu ) {
			if ( strtolower( (string) $menu['name'] ) === strtolower( $name ) ) {
				return Assessment::prohibited( 'name_taken', sprintf( 'A menu named "%s" exists again (id %d); rename or delete it first.', $name, $menu['id'] ) );
			}
		}
		$items     = isset( $before['items'] ) ? (array) $before['items'] : array();
		$locations = isset( $before['locations'] ) ? (array) $before['locations'] : array();

		return Assessment::destructive(
			sprintf( 'Re-create menu "%s" with %d items%s (new ids).', $name, count( $items ), array() === $locations ? '' : ' in ' . implode( ', ', $locations ) ),
			array(
				'current'  => array( 'menu' => '(deleted)' ),
				'restored' => array(
					'menu'      => $name,
					'items'     => (string) count( $items ),
					'locations' => implode( ', ', $locations ),
				),
			),
			array( 'fingerprint' => Menus::menu_fingerprint( $id ) )
		);
	}

	/**
	 * Re-creates the menu, its locations and its items, parents first.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When WordPress refused to create the menu.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		if ( Menus::menu_fingerprint( (int) $rollback['target']['id'] ) !== ( $expected_state['fingerprint'] ?? '' ) ) {
			throw new ToolError( 'The menu changed after the rollback was assessed; nothing was re-created.' );
		}
		$before     = (array) $rollback['before'];
		$registered = Menus::locations();
		$locations  = array_values( array_intersect( (array) $before['locations'], array_keys( $registered ) ) );
		$menu       = Rest::write(
			'POST',
			'/wp/v2/menus',
			array(
				'name'        => (string) $before['name'],
				'description' => (string) $before['description'],
				'locations'   => $locations,
			)
		);
		$menu_id    = isset( $menu['id'] ) ? (int) $menu['id'] : 0;
		if ( $menu_id <= 0 ) {
			throw new ToolError( 'WordPress did not return the re-created menu.' );
		}

		$pending = (array) $before['items'];
		uasort(
			$pending,
			static function ( $a, $b ) {
				return (int) $a['menu_order'] <=> (int) $b['menu_order'];
			}
		);
		$new_ids = array();
		$failed  = 0;
		do {
			$progress = false;
			foreach ( $pending as $old_id => $fields ) {
				$parent = (int) $fields['parent'];
				if ( 0 !== $parent && ! isset( $new_ids[ $parent ] ) && isset( $pending[ $parent ] ) ) {
					continue;
				}
				unset( $pending[ $old_id ] );
				$progress         = true;
				$fields['menus']  = $menu_id;
				$fields['parent'] = isset( $new_ids[ $parent ] ) ? $new_ids[ $parent ] : 0;
				try {
					$created            = Rest::write( 'POST', '/wp/v2/menu-items', $fields );
					$new_ids[ $old_id ] = (int) $created['id'];
				} catch ( ToolError $e ) {
					++$failed;
				}
			}
		} while ( $progress && array() !== $pending );

		$outcome = new Outcome(
			array(
				'menu_id'        => $menu_id,
				'items_restored' => count( $new_ids ),
				'locations'      => $locations,
			),
			Rollback::none()
		);
		if ( $failed > 0 || count( $locations ) !== count( (array) $before['locations'] ) ) {
			$outcome = $outcome->with_warning( 'partly_restored', sprintf( '%d items could not be re-created (their target may be gone) and %d locations are no longer registered.', $failed, count( (array) $before['locations'] ) - count( $locations ) ) );
		}
		return $outcome;
	}
}
