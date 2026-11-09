<?php
/**
 * Write tool: add_menu_item.
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
 * `add_menu_item`: adds a custom link, post/page or term to a menu through the core menu-items controller. Reversible:
 * rollback deletes the item, unless it has gained child items (they would be orphaned).
 */
final class AddMenuItem implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'add_menu_item';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: add a menu item',
			'Adds an item to a navigation menu: a custom link (type custom with title and url: http(s), site-relative /path, #, ?, mailto: or tel:), a post or page (type post_type, object = post type, object_id), or a term (type taxonomy, object = taxonomy, object_id). Optional parent (an item of the same menu) and position (menu order; default last). Always returns approval_required; undo with rollback_change.',
			array(
				'menu_id'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Menu id.',
				),
				'type'      => array(
					'type'        => 'string',
					'enum'        => array( 'custom', 'post_type', 'taxonomy' ),
					'description' => 'Item type.',
				),
				'title'     => array(
					'type'        => 'string',
					'maxLength'   => 255,
					'description' => 'Label (required for custom links; defaults to the object\'s title otherwise).',
				),
				'url'       => array(
					'type'        => 'string',
					'maxLength'   => 2048,
					'description' => 'Link target, custom links only.',
				),
				'object'    => array(
					'type'        => 'string',
					'pattern'     => '^[a-z0-9_-]{1,32}$',
					'description' => 'Post type or taxonomy name, e.g. page or category.',
				),
				'object_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Post or term id.',
				),
				'parent'    => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Parent item id in the same menu, or 0.',
				),
				'position'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Menu order (1 = first).',
				),
			),
			array( 'menu_id', 'type' ),
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
	 * Assesses the new item. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the item is invalid.
	 */
	public function assess( array $args ): Assessment {
		$menu_id = (int) $args['menu_id'];
		self::check_shape( $args );
		$refusal = Menus::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$menu = Menus::menu( $menu_id );
		if ( null === $menu ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Menu %d was not found or you cannot edit it.', $menu_id ) );
		}
		self::check_object( $args );
		$items = (array) Menus::items( $menu_id );
		Menus::check_parent( $items, $menu_id, isset( $args['parent'] ) ? (int) $args['parent'] : 0, 0 );

		$fields = self::fields( $args, $items );
		$label  = 'custom' === $args['type'] ? sprintf( '"%s" -> %s', $fields['title'], $fields['url'] ) : sprintf( '%s %d', $fields['object'], $fields['object_id'] );
		return Assessment::reversible(
			sprintf( 'Add %s to menu "%s" (%d) at position %d.', $label, $menu['name'], $menu_id, $fields['menu_order'] ),
			array(
				'before' => array(
					'menu'  => (string) $menu['name'],
					'items' => (string) count( $items ),
				),
				'after'  => array(
					'menu'  => (string) $menu['name'],
					'items' => (string) ( count( $items ) + 1 ),
					'item'  => $label,
				),
			),
			array( 'fingerprint' => Menus::menu_fingerprint( $menu_id ) ),
			false
		);
	}

	/**
	 * Creates the item.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the menu changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$menu_id = (int) $args['menu_id'];
		if ( Menus::menu_fingerprint( $menu_id ) !== ( $assessment->preconditions()['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Menu %d changed after the request was assessed; nothing was added.', (int) $menu_id ) );
		}
		$item = Rest::write( 'POST', '/wp/v2/menu-items', self::fields( $args, (array) Menus::items( $menu_id ) ) );
		$id   = isset( $item['id'] ) ? (int) $item['id'] : 0;
		if ( $id <= 0 ) {
			throw new ToolError( 'WordPress did not return the new menu item.' );
		}
		return new Outcome(
			array(
				'item_id' => $id,
				'menu_id' => $menu_id,
			),
			Rollback::make( Rollback::SNAPSHOT, 'menu_item', $id, array( 'menu_id' => $menu_id ), array( 'exists' => false ) ),
			array( 'fingerprint' => Menus::item_fingerprint( $id ) )
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
		return null === Menus::item( (int) $outcome->result()['item_id'] ) ? $outcome->with_verification_failure( 'The new menu item could not be read back.' ) : $outcome;
	}

	/**
	 * Assesses deleting the item again. Read-only.
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
		$fields = Menus::fields( $item );
		if ( array() !== Menus::children( (array) Menus::items( $fields['menus'] ), $id ) ) {
			return Assessment::prohibited( 'has_children', sprintf( 'Menu item %d has child items now; removing it would orphan them. Move or delete them first.', $id ) );
		}
		return Assessment::reversible(
			sprintf( 'Remove menu item "%s" (%d), which the write added.', $fields['title'], $id ),
			array(
				'current'  => array( 'item' => (string) $fields['title'] ),
				'restored' => array( 'item' => '(removed)' ),
			),
			array( 'fingerprint' => Menus::item_fingerprint( $id ) ),
			false
		);
	}

	/**
	 * Deletes the item, if it is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the item changed or WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$id = (int) $rollback['target']['id'];
		if ( Menus::item_fingerprint( $id ) !== ( $expected_state['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Menu item %d changed after the rollback was assessed; nothing was removed.', (int) $id ) );
		}
		Rest::write( 'DELETE', '/wp/v2/menu-items/' . $id, array( 'force' => true ) );
		return new Outcome( array( 'removed_item_id' => $id ), Rollback::none() );
	}

	/**
	 * Throws unless the arguments fit the item type.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @throws ToolError When they do not.
	 */
	private static function check_shape( array $args ): void {
		if ( 'custom' === $args['type'] ) {
			if ( ! isset( $args['url'] ) || '' === trim( (string) $args['url'] ) ) {
				throw new ToolError( 'url is required for custom links.' );
			}
			if ( ! isset( $args['title'] ) || '' === trim( (string) $args['title'] ) ) {
				throw new ToolError( 'title is required for custom links.' );
			}
			Menus::check_url( (string) $args['url'] );
			return;
		}
		if ( isset( $args['url'] ) ) {
			throw new ToolError( 'url is only for custom links; post and term items link to their object.' );
		}
		if ( ! isset( $args['object'], $args['object_id'] ) ) {
			throw new ToolError( 'object and object_id are required for post_type and taxonomy items.' );
		}
	}

	/**
	 * Throws unless the linked post or term exists.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @throws ToolError When it does not.
	 */
	private static function check_object( array $args ): void {
		if ( 'custom' === $args['type'] ) {
			return;
		}
		$object = (string) $args['object'];
		$id     = (int) $args['object_id'];
		if ( 'post_type' === $args['type'] ) {
			$post  = get_post( $id );
			$found = $post && $post->post_type === $object && 'trash' !== $post->post_status;
		} else {
			$term  = get_term( $id, $object );
			$found = $term && ! is_wp_error( $term );
		}
		if ( ! $found ) {
			throw new ToolError( sprintf( '%s %d was not found.', esc_textarea( $object ), (int) $id ) );
		}
	}

	/**
	 * Fields posted to the menu-items controller.
	 *
	 * @param array<string, mixed>             $args  Arguments.
	 * @param array<int, array<string, mixed>> $items Current items of the menu.
	 * @return array<string, mixed>
	 */
	private static function fields( array $args, array $items ): array {
		$last = 0;
		foreach ( $items as $item ) {
			$last = max( $last, (int) $item['menu_order'] );
		}
		$fields = array(
			'menus'      => (int) $args['menu_id'],
			'type'       => (string) $args['type'],
			'status'     => 'publish',
			'parent'     => isset( $args['parent'] ) ? (int) $args['parent'] : 0,
			'menu_order' => isset( $args['position'] ) ? (int) $args['position'] : $last + 1,
		);
		if ( isset( $args['title'] ) ) {
			$fields['title'] = (string) $args['title'];
		}
		if ( 'custom' === $args['type'] ) {
			$fields['url']    = (string) $args['url'];
			$fields['object'] = 'custom';
		} else {
			$fields['object']    = (string) $args['object'];
			$fields['object_id'] = (int) $args['object_id'];
		}
		return $fields;
	}
}
