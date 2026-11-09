<?php
/**
 * Shared reads and checks for the menu write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;

defined( 'ABSPATH' ) || exit;

/**
 * Reads menus, items and locations through the core REST controllers, snapshots items, fingerprints menus and items,
 * and validates URLs and parents.
 */
final class Menus {

	/**
	 * Most items a menu may have for delete_menu to snapshot it.
	 */
	const MAX_ITEMS = 500;

	/**
	 * Item fields a snapshot keeps (REST names), which are also what a re-created item is posted with.
	 */
	const ITEM_FIELDS = array( 'title', 'url', 'type', 'object', 'object_id', 'parent', 'menu_order', 'target', 'attr_title', 'description', 'classes', 'xfn', 'status', 'menus' );

	/**
	 * URLs a custom item may link to: http(s), site-relative, fragment, query, mailto and tel.
	 */
	const URL_PATTERN = '#^(https?://|/|\#|\?|mailto:|tel:)#i';

	/**
	 * Refusal when the user cannot edit menus, or null.
	 *
	 * @return Assessment|null
	 */
	public static function refusal(): ?Assessment {
		return current_user_can( 'edit_theme_options' ) ? null : Assessment::prohibited( 'forbidden', 'Changing menus and widgets needs the edit_theme_options capability.' );
	}

	/**
	 * A menu, or null when it does not exist (or cannot be read).
	 *
	 * @param int $id Menu (term) id.
	 * @return array<string, mixed>|null
	 */
	public static function menu( int $id ): ?array {
		return $id > 0 ? Rest::read( '/wp/v2/menus/' . $id ) : null;
	}

	/**
	 * Menus (the first 100).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		return (array) Rest::read( '/wp/v2/menus', array( 'per_page' => 100 ) );
	}

	/**
	 * Items of a menu in menu order, or null when there are more than MAX_ITEMS.
	 *
	 * @param int $menu_id Menu id.
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function items( int $menu_id ): ?array {
		$items = array();
		$pages = (int) ceil( ( self::MAX_ITEMS + 1 ) / 100 );
		for ( $page = 1; $page <= $pages; $page++ ) {
			$batch = (array) Rest::read(
				'/wp/v2/menu-items',
				array(
					'menus'    => $menu_id,
					'per_page' => 100,
					'page'     => $page,
					'orderby'  => 'menu_order',
					'order'    => 'asc',
				)
			);
			$items = array_merge( $items, $batch );
			if ( count( $batch ) < 100 ) {
				break;
			}
		}
		return count( $items ) > self::MAX_ITEMS ? null : $items;
	}

	/**
	 * A menu item, or null.
	 *
	 * @param int $id Item id.
	 * @return array<string, mixed>|null
	 */
	public static function item( int $id ): ?array {
		return $id > 0 ? Rest::read( '/wp/v2/menu-items/' . $id ) : null;
	}

	/**
	 * Location slug => menu id (0 when unassigned), registered locations only.
	 *
	 * @return array<string, int>
	 */
	public static function locations(): array {
		$owners = array();
		foreach ( (array) Rest::read( '/wp/v2/menu-locations' ) as $slug => $location ) {
			$owners[ (string) $slug ] = isset( $location['menu'] ) ? (int) $location['menu'] : 0;
		}
		return $owners;
	}

	/**
	 * The fields of an item a snapshot keeps.
	 *
	 * @param array<string, mixed> $item Item from REST.
	 * @return array<string, mixed>
	 */
	public static function fields( array $item ): array {
		$fields = array();
		foreach ( self::ITEM_FIELDS as $field ) {
			$value = isset( $item[ $field ] ) ? $item[ $field ] : null;
			if ( 'title' === $field && is_array( $value ) ) {
				$value = isset( $value['raw'] ) ? $value['raw'] : '';
			}
			$fields[ $field ] = $value;
		}
		foreach ( array( 'object_id', 'parent', 'menu_order', 'menus' ) as $int ) {
			$fields[ $int ] = (int) $fields[ $int ];
		}
		return $fields;
	}

	/**
	 * Ids of the items whose parent is the given item.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @param int                              $id    Item id.
	 * @return int[]
	 */
	public static function children( array $items, int $id ): array {
		$children = array();
		foreach ( $items as $item ) {
			if ( (int) $item['parent'] === $id ) {
				$children[] = (int) $item['id'];
			}
		}
		sort( $children );
		return $children;
	}

	/**
	 * Fingerprint of a menu: its name, description, locations and items (or that it is missing).
	 *
	 * @param int $id Menu id.
	 * @return string
	 */
	public static function menu_fingerprint( int $id ): string {
		$menu = self::menu( $id );
		if ( null === $menu ) {
			return Canonical::hash( array( 'menu', $id, null ) );
		}
		$locations = isset( $menu['locations'] ) ? (array) $menu['locations'] : array();
		sort( $locations );
		$items = array();
		foreach ( (array) self::items( $id ) as $item ) {
			$items[ (int) $item['id'] ] = self::fields( $item );
		}
		ksort( $items );
		return Canonical::hash( array( 'menu', $id, $menu['name'], $menu['description'], $locations, $items ) );
	}

	/**
	 * Fingerprint of an item: its fields and child ids (or that it is missing).
	 *
	 * @param int $id Item id.
	 * @return string
	 */
	public static function item_fingerprint( int $id ): string {
		$item = self::item( $id );
		if ( null === $item ) {
			return Canonical::hash( array( 'item', $id, null ) );
		}
		$fields = self::fields( $item );
		return Canonical::hash( array( 'item', $id, $fields, self::children( (array) self::items( $fields['menus'] ), $id ) ) );
	}

	/**
	 * Throws unless a URL is one a custom item may link to.
	 *
	 * @param string $url URL.
	 * @throws ToolError When it is not.
	 */
	public static function check_url( string $url ): void {
		if ( 1 !== preg_match( self::URL_PATTERN, $url ) ) {
			throw new ToolError( 'url must start with http://, https://, /, #, ?, mailto: or tel:.' );
		}
	}

	/**
	 * Throws unless a parent is 0 or an item of the menu that would not create a cycle.
	 *
	 * @param array<int, array<string, mixed>> $items     Items of the menu.
	 * @param int                              $menu_id   Menu id.
	 * @param int                              $parent_id Proposed parent.
	 * @param int                              $item_id   Item being moved (0 for a new item).
	 * @throws ToolError When it is not.
	 */
	public static function check_parent( array $items, int $menu_id, int $parent_id, int $item_id ): void {
		if ( 0 === $parent_id ) {
			return;
		}
		$parents = array();
		foreach ( $items as $item ) {
			$parents[ (int) $item['id'] ] = (int) $item['parent'];
		}
		if ( ! isset( $parents[ $parent_id ] ) ) {
			throw new ToolError( sprintf( 'parent must be an item of menu %d (or 0 for the top level).', (int) $menu_id ) );
		}
		// Walk up from the proposed parent; reaching the item means a cycle. Bounded by the number of items.
		$step = $parent_id;
		$left = count( $parents );
		while ( $step > 0 && $left-- >= 0 ) {
			if ( $step === $item_id ) {
				throw new ToolError( 'An item cannot be its own parent or ancestor.' );
			}
			$step = isset( $parents[ $step ] ) ? $parents[ $step ] : 0;
		}
	}

	/**
	 * Gives a location to a menu (or to none) through the menus controller: the new menu's `locations` gains it, or the
	 * current owner's loses it.
	 *
	 * @param string $location Location slug.
	 * @param int    $menu_id  Menu id, or 0 for none.
	 * @param int    $owner    Menu that has the location now, or 0.
	 * @throws ToolError When a menu is missing or WordPress refused.
	 */
	public static function assign( string $location, int $menu_id, int $owner ): void {
		$target = $menu_id > 0 ? $menu_id : $owner;
		if ( 0 === $target ) {
			return;
		}
		$menu = self::menu( $target );
		if ( null === $menu ) {
			throw new ToolError( sprintf( 'Menu %d was not found.', (int) $target ) );
		}
		$locations = isset( $menu['locations'] ) ? (array) $menu['locations'] : array();
		$locations = $menu_id > 0 ? array_values( array_unique( array_merge( $locations, array( $location ) ) ) ) : array_values( array_diff( $locations, array( $location ) ) );
		Rest::write( 'POST', '/wp/v2/menus/' . $target, array( 'locations' => $locations ) );
	}

	/**
	 * "Name (id)" of a menu, or "(none)".
	 *
	 * @param int $id Menu id.
	 * @return string
	 */
	public static function label( int $id ): string {
		$menu = self::menu( $id );
		return null === $menu ? '(none)' : sprintf( '%s (%d)', $menu['name'], $id );
	}
}
