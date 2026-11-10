<?php
/**
 * Write tool: update_menu_item.
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
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `update_menu_item`: changes the label, link (custom links), parent, position, target, title attribute or description
 * of one menu item through the core menu-items controller. A new position moves the item there among all the menu's items
 * and renumbers the others 1, 2, 3 and so on, as the menu editor in wp-admin does when it saves. Reversible from a snapshot of
 * the changed fields and of every other item's old position.
 */
final class UpdateMenuItem implements WriteTool, Reversible {

	/**
	 * Argument => REST field.
	 */
	const FIELDS = array(
		'title'       => 'title',
		'url'         => 'url',
		'parent'      => 'parent',
		'position'    => 'menu_order',
		'target'      => 'target',
		'attr_title'  => 'attr_title',
		'description' => 'description',
	);

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'update_menu_item';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: update a menu item',
			'Changes one menu item: title (label), url (custom links only), parent (an item of the same menu, or 0), position (1 = first among all the menu\'s items; the other items are renumbered 1, 2, 3 and so on to make room, as the menu editor does), target ("" or "_blank"), attr_title, description. Always returns approval_required; undo with rollback_change.',
			array(
				'item_id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Menu item id.',
				),
				'title'       => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
				'url'         => array(
					'type'      => 'string',
					'maxLength' => 2048,
				),
				'parent'      => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'position'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'target'      => array(
					'type' => 'string',
					'enum' => array( '', '_blank' ),
				),
				'attr_title'  => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
				'description' => array(
					'type'      => 'string',
					'maxLength' => 1000,
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
	 * Assesses the change. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When nothing is given, a value is invalid, or nothing would change.
	 */
	public function assess( array $args ): Assessment {
		$id      = (int) $args['item_id'];
		$changes = array_intersect_key( $args, self::FIELDS );
		if ( array() === $changes ) {
			throw new ToolError( 'Give at least one of ' . esc_textarea( implode( ', ', array_keys( self::FIELDS ) ) ) . '.' );
		}
		$refusal = Menus::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$item = Menus::item( $id );
		if ( null === $item ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Menu item %d was not found or you cannot edit it.', $id ) );
		}
		$fields = Menus::fields( $item );
		if ( isset( $changes['url'] ) ) {
			if ( 'custom' !== $fields['type'] ) {
				throw new ToolError( 'url is only for custom links; post and term items link to their object.' );
			}
			Menus::check_url( (string) $changes['url'] );
		}
		if ( isset( $changes['parent'] ) ) {
			Menus::check_parent( (array) Menus::items( $fields['menus'] ), $fields['menus'], (int) $changes['parent'], $id );
		}

		$before = self::current( $fields, array_keys( $changes ) );
		$after  = array_map( 'strval', $changes );
		$items  = array();
		$moves  = array();
		if ( isset( $changes['position'] ) ) {
			$items = Menus::items( $fields['menus'] );
			if ( null === $items ) {
				return Assessment::prohibited( 'too_many_items', sprintf( 'Menu %d has more than %d items; FlitAgent cannot renumber it.', $fields['menus'], Menus::MAX_ITEMS ) );
			}
			if ( (int) $changes['position'] > count( $items ) ) {
				throw new ToolError( sprintf( 'position must be from 1 to %d (the menu has %s).', count( $items ), esc_textarea( Summary::count( count( $items ), 'item', 'items' ) ) ) );
			}
			// The position is the item's place in the menu, which ties, gaps or an old 0 can make differ from menu_order.
			$before['position'] = (string) self::rank( $items, $id );
			$moves              = self::moves( $items, $id, (int) $changes['position'] );
		}
		if ( $before === $after && array() === $moves ) {
			throw new ToolError( sprintf( 'Menu item %d already has those values; nothing to change.', (int) $id ) );
		}

		$preview       = array(
			'before' => $before,
			'after'  => $after,
		);
		$preconditions = array( 'fingerprint' => Menus::item_fingerprint( $id ) );
		$moved_note    = '';
		if ( isset( $changes['position'] ) ) {
			$preview['other_items_moved'] = self::describe( $items, $moves, false );
			$preconditions['menu']        = Menus::menu_fingerprint( $fields['menus'] );
			if ( array() !== $moves ) {
				$moved_note = sprintf( ' %s renumbered to keep the order 1, 2, 3.', Summary::count( count( $moves ), 'other item is', 'other items are' ) );
			}
		}

		return Assessment::reversible(
			sprintf( 'Change the %s of menu item "%s" (%d).', implode( ', ', array_keys( $changes ) ), $fields['title'], $id ) . $moved_note,
			$preview,
			$preconditions,
			false
		);
	}

	/**
	 * Updates the item.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the item changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$id            = (int) $args['item_id'];
		$preconditions = $assessment->preconditions();
		$fields        = Menus::fields( (array) Menus::item( $id ) );
		if ( Menus::item_fingerprint( $id ) !== ( $preconditions['fingerprint'] ?? '' ) || ( isset( $preconditions['menu'] ) && Menus::menu_fingerprint( $fields['menus'] ) !== $preconditions['menu'] ) ) {
			throw new ToolError( sprintf( 'Menu item %d changed after the request was assessed; nothing was changed.', (int) $id ) );
		}
		$changes = array_intersect_key( $args, self::FIELDS );
		$before  = array();
		$update  = array();
		foreach ( $changes as $arg => $value ) {
			$before[ self::FIELDS[ $arg ] ] = $fields[ self::FIELDS[ $arg ] ];
			$update[ self::FIELDS[ $arg ] ] = $value;
		}
		$moves = isset( $changes['position'] ) ? self::moves( (array) Menus::items( $fields['menus'] ), $id, (int) $changes['position'] ) : array();
		if ( isset( $changes['position'] ) ) {
			unset( $update['menu_order'] );
			$moves[ $id ] = array( (int) $fields['menu_order'], (int) $changes['position'] );
		} elseif ( 0 === (int) $fields['menu_order'] ) {
			// Core's REST route saves an item stored at 0 (older WordPress) at 1; the snapshot keeps the 0 for rollback.
			$before['menu_order'] = 0;
		}
		self::write( $id, $update, $moves, 'nothing was changed' );
		$positions = array();
		foreach ( $moves as $other => $move ) {
			if ( $other !== $id ) {
				$positions[ $other ] = $move[0];
			}
		}

		$snapshot = array( 'fields' => $before );
		if ( array() !== $positions ) {
			$snapshot['positions'] = $positions;
		}
		return new Outcome(
			array( 'item_id' => $id ),
			Rollback::make( Rollback::SNAPSHOT, 'menu_item', $id, array(), $snapshot ),
			array( 'fingerprint' => self::fingerprint( $id, $fields['menus'], array_keys( $positions ) ) )
		);
	}

	/**
	 * Reads the item back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$item = Menus::item( (int) $args['item_id'] );
		if ( null === $item ) {
			return $outcome->with_verification_failure( 'The menu item could not be read back.' );
		}
		$changes = array_intersect_key( $args, self::FIELDS );
		if ( self::current( Menus::fields( $item ), array_keys( $changes ) ) === array_map( 'strval', $changes ) ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result( array( 'stored_verbatim' => false ) )->with_warning( 'stored_differently', 'WordPress stored the menu item differently from the request.' );
	}

	/**
	 * Assesses restoring the changed fields. Read-only.
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
		$item = Menus::item( $id );
		if ( null === $item ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Menu item %d no longer exists.', $id ) );
		}
		$fields    = Menus::fields( $item );
		$restored  = isset( $rollback['before']['fields'] ) ? (array) $rollback['before']['fields'] : array();
		$positions = isset( $rollback['before']['positions'] ) ? array_map( 'intval', (array) $rollback['before']['positions'] ) : array();
		$current   = array();
		foreach ( array_keys( $restored ) as $field ) {
			$current[ $field ] = self::text( $fields[ $field ] );
		}
		$preview = array(
			'current'  => $current,
			'restored' => array_map( array( __CLASS__, 'text' ), $restored ),
		);
		$note    = '';
		if ( array() !== $positions ) {
			$items                           = (array) Menus::items( $fields['menus'] );
			$moves                           = self::restorable( $items, $positions );
			$gone                            = array_values( array_diff( array_keys( $positions ), array_keys( $moves ) ) );
			$preview['other_items_restored'] = self::describe( $items, $moves, true );
			$parts                           = array();
			if ( array() !== $moves ) {
				$parts[] = sprintf( '%s to %s', Summary::count( count( $moves ), 'other item moves back', 'other items move back' ), 1 === count( $moves ) ? 'its old position' : 'their old positions' );
			}
			if ( array() !== $gone ) {
				$parts[] = 1 === count( $gone ) ? sprintf( 'item %d is no longer in the menu and is skipped', $gone[0] ) : sprintf( 'items %s are no longer in the menu and are skipped', Summary::join( $gone ) );
			}
			$note = ' ' . ucfirst( implode( '; ', $parts ) ) . '.';
		}
		return Assessment::reversible(
			sprintf( 'Restore the %s of menu item "%s" (%d).', implode( ', ', array_keys( $restored ) ), $fields['title'], $id ) . $note,
			$preview,
			array( 'fingerprint' => self::fingerprint( $id, $fields['menus'], array_keys( $positions ) ) ),
			false
		);
	}

	/**
	 * Restores the changed fields, if the item is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the item changed or WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$id        = (int) $rollback['target']['id'];
		$positions = isset( $rollback['before']['positions'] ) ? array_map( 'intval', (array) $rollback['before']['positions'] ) : array();
		$item      = Menus::item( $id );
		$menu      = null === $item ? 0 : Menus::fields( $item )['menus'];
		if ( self::fingerprint( $id, $menu, array_keys( $positions ) ) !== ( $expected_state['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Menu item %d changed after the rollback was assessed; nothing was restored.', (int) $id ) );
		}
		$fields = (array) $rollback['before']['fields'];
		$moves  = array() === $positions ? array() : self::restorable( (array) Menus::items( $menu ), $positions );
		if ( array_key_exists( 'menu_order', $fields ) ) {
			$moves[ $id ] = array( (int) Menus::fields( (array) $item )['menu_order'], (int) $fields['menu_order'] );
			unset( $fields['menu_order'] );
		}
		self::write( $id, $fields, $moves, 'nothing was restored' );

		$outcome = new Outcome( array( 'item_id' => $id ), Rollback::none() );
		$gone    = array_values( array_diff( array_keys( $positions ), array_keys( $moves ) ) );
		if ( array() !== $gone ) {
			$outcome = $outcome->with_warning( 'items_gone', sprintf( 'Menu %s no longer in the menu, so %s not put back.', 1 === count( $gone ) ? sprintf( 'item %d is', $gone[0] ) : sprintf( 'items %s are', Summary::join( $gone ) ), 1 === count( $gone ) ? 'its position was' : 'their positions were' ) );
		}
		return $outcome;
	}

	/**
	 * Writes menu_order values and the item's other fields; when one write fails, or anything throws, puts back the
	 * menu_order values already written, so nothing is left moved, and reports the failure.
	 *
	 * The item's own menu_order is written again after its other fields: core's REST route saves an item stored at 0
	 * at 1 (ticket 28140), so the order the item must end at is written last.
	 *
	 * The menu_order goes through wp_update_post() with that field alone: the REST route re-validates the whole item (a
	 * custom link without a URL, an archive whose post type is gone) and refuses 0, which older WordPress stored for a
	 * menu's first item.
	 *
	 * @param int                               $id      The item whose other fields change.
	 * @param array<string, mixed>              $fields  Its other REST fields to write, or none.
	 * @param array<int, array{0: int, 1: int}> $moves   Item id => array( current menu_order, new menu_order ).
	 * @param string                            $nothing How the failure ends, e.g. "nothing was changed".
	 * @throws ToolError When a write fails; anything else a hook threw is rethrown after the put-back.
	 */
	private static function write( int $id, array $fields, array $moves, string $nothing ): void {
		$done = array();
		try {
			foreach ( $moves as $other => $move ) {
				if ( $move[0] === $move[1] ) {
					continue;
				}
				if ( ! Menus::set_order( $other, $move[1] ) ) {
					throw new ToolError( sprintf( 'WordPress could not move menu item %d.', $other ) );
				}
				$done[ $other ] = $move[0];
			}
			if ( array() !== $fields ) {
				if ( isset( $moves[ $id ] ) && ! isset( $done[ $id ] ) ) {
					$done[ $id ] = $moves[ $id ][0];
				}
				Rest::write( 'POST', '/wp/v2/menu-items/' . $id, $fields );
				if ( isset( $moves[ $id ] ) && ! Menus::set_order( $id, $moves[ $id ][1] ) ) {
					throw new ToolError( sprintf( 'WordPress could not move menu item %d.', $id ) );
				}
			}
		} catch ( \Throwable $e ) {
			$stuck = array();
			foreach ( array_reverse( $done, true ) as $other => $order ) {
				try {
					$back = Menus::set_order( $other, $order );
				} catch ( \Throwable $ignored ) {
					$back = false;
				}
				if ( ! $back ) {
					$stuck[] = $other;
				}
			}
			if ( ! $e instanceof ToolError ) {
				// Not a message for the app: the Gateway records it as a failed write without its details.
				throw $e;
			}
			$message = rtrim( ToolError::plain( $e ), '.' );
			if ( array() === $stuck ) {
				$message = sprintf( '%s; %s.', $message, $nothing );
			} else {
				$stuck   = array_reverse( $stuck );
				$which   = 1 === count( $stuck ) ? sprintf( 'item %d', $stuck[0] ) : 'items ' . Summary::join( $stuck );
				$message = sprintf( '%s, and menu %s could not be put back.', $message, $which );
			}
			throw new ToolError( esc_textarea( $message ) );
		}//end try
	}

	/**
	 * The stored positions of items still in the menu, as moves from their current menu_order.
	 *
	 * @param array<int, array<string, mixed>> $items     The menu's items.
	 * @param array<int, int>                  $positions Item id => menu_order to restore.
	 * @return array<int, array{0: int, 1: int}>
	 */
	private static function restorable( array $items, array $positions ): array {
		$moves = array();
		foreach ( $items as $other ) {
			if ( isset( $positions[ (int) $other['id'] ] ) ) {
				$moves[ (int) $other['id'] ] = array( (int) $other['menu_order'], $positions[ (int) $other['id'] ] );
			}
		}
		return $moves;
	}

	/**
	 * An item's place in the menu, from 1: menu order, ties by id, as moves() orders it.
	 *
	 * @param array<int, array<string, mixed>> $items The menu's items.
	 * @param int                              $id    Item id.
	 * @return int
	 */
	private static function rank( array $items, int $id ): int {
		$ids = array_map( 'intval', array_column( self::sorted( $items ), 'id' ) );
		$at  = array_search( $id, $ids, true );
		return false === $at ? 0 : $at + 1;
	}

	/**
	 * Items in menu order, ties by id.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sorted( array $items ): array {
		usort(
			$items,
			static function ( $a, $b ) {
				return array( (int) $a['menu_order'], (int) $a['id'] ) <=> array( (int) $b['menu_order'], (int) $b['id'] );
			}
		);
		return $items;
	}

	/**
	 * The other items a move renumbers: the menu in its current order (ties by id), the item taken out and put back at
	 * the position, and every other item numbered 1, 2, 3 and so on by its place, as the menu editor saves a menu.
	 *
	 * @param array<int, array<string, mixed>> $items    The menu's items.
	 * @param int                              $id       The item that moves.
	 * @param int                              $position Its new position, from 1.
	 * @return array<int, array{0: int, 1: int}> Other item id => array( old menu_order, new menu_order ), changed ones only.
	 */
	private static function moves( array $items, int $id, int $position ): array {
		$items = self::sorted( $items );
		$order = array();
		foreach ( $items as $item ) {
			if ( (int) $item['id'] !== $id ) {
				$order[ (int) $item['id'] ] = (int) $item['menu_order'];
			}
		}
		$ids = array_keys( $order );
		array_splice( $ids, $position - 1, 0, array( $id ) );

		$moves = array();
		foreach ( $ids as $index => $other ) {
			if ( $other !== $id && $order[ $other ] !== $index + 1 ) {
				$moves[ $other ] = array( $order[ $other ], $index + 1 );
			}
		}
		return $moves;
	}

	/**
	 * Moves as the approval screen lists them: `"About" (102): position 2 to 3`, in menu order.
	 *
	 * @param array<int, array<string, mixed>>  $items    The menu's items.
	 * @param array<int, array{0: int, 1: int}> $moves    Item id => array( from, to ).
	 * @param bool                              $by_order Whether to list them in the order of their new positions.
	 * @return string[]
	 */
	private static function describe( array $items, array $moves, bool $by_order ): array {
		$titles = array();
		foreach ( $items as $item ) {
			$titles[ (int) $item['id'] ] = (string) Menus::fields( $item )['title'];
		}
		if ( $by_order ) {
			uasort(
				$moves,
				static function ( $a, $b ) {
					return $a[1] <=> $b[1];
				}
			);
		}
		$lines = array();
		foreach ( $moves as $other => $move ) {
			$lines[] = sprintf( '"%s" (%d): position %d to %d', isset( $titles[ $other ] ) ? $titles[ $other ] : '', $other, $move[0], $move[1] );
		}
		return $lines;
	}

	/**
	 * Fingerprint of the item and, when a move renumbered others, of those items' positions.
	 *
	 * @param int   $id     Item id.
	 * @param int   $menu   Its menu.
	 * @param int[] $others Ids of the other items the move renumbered.
	 * @return string
	 */
	private static function fingerprint( int $id, int $menu, array $others ): string {
		$item = Menus::item_fingerprint( $id );
		if ( array() === $others ) {
			return $item;
		}
		$positions = array_fill_keys( $others, null );
		foreach ( (array) Menus::items( $menu ) as $other ) {
			if ( array_key_exists( (int) $other['id'], $positions ) ) {
				$positions[ (int) $other['id'] ] = (int) $other['menu_order'];
			}
		}
		ksort( $positions );
		return Canonical::hash( array( $item, $positions ) );
	}

	/**
	 * Current values of the given arguments, as text.
	 *
	 * @param array<string, mixed> $fields Item fields.
	 * @param string[]             $args   Argument names.
	 * @return array<string, string>
	 */
	private static function current( array $fields, array $args ): array {
		$current = array();
		foreach ( $args as $arg ) {
			$current[ $arg ] = self::text( $fields[ self::FIELDS[ $arg ] ] );
		}
		return $current;
	}

	/**
	 * A field value as text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function text( $value ): string {
		return is_scalar( $value ) || null === $value ? (string) $value : implode( ' ', (array) $value );
	}
}
