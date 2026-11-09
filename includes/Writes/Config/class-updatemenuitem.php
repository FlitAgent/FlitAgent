<?php
/**
 * Write tool: update_menu_item.
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
 * `update_menu_item`: changes the label, link (custom links), parent, position, target, title attribute or description
 * of one menu item through the core menu-items controller. Reversible from a snapshot of the changed fields.
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
			'Changes one menu item: title (label), url (custom links only), parent (an item of the same menu, or 0), position (menu order), target ("" or "_blank"), attr_title, description. Always returns approval_required; undo with rollback_change.',
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
		if ( $before === $after ) {
			throw new ToolError( sprintf( 'Menu item %d already has those values; nothing to change.', (int) $id ) );
		}
		return Assessment::reversible(
			sprintf( 'Change the %s of menu item "%s" (%d).', implode( ', ', array_keys( $changes ) ), $fields['title'], $id ),
			array(
				'before' => $before,
				'after'  => $after,
			),
			array( 'fingerprint' => Menus::item_fingerprint( $id ) ),
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
		$id = (int) $args['item_id'];
		if ( Menus::item_fingerprint( $id ) !== ( $assessment->preconditions()['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Menu item %d changed after the request was assessed; nothing was changed.', (int) $id ) );
		}
		$fields  = Menus::fields( (array) Menus::item( $id ) );
		$changes = array_intersect_key( $args, self::FIELDS );
		$before  = array();
		$update  = array();
		foreach ( $changes as $arg => $value ) {
			$before[ self::FIELDS[ $arg ] ] = $fields[ self::FIELDS[ $arg ] ];
			$update[ self::FIELDS[ $arg ] ] = $value;
		}
		Rest::write( 'POST', '/wp/v2/menu-items/' . $id, $update );

		return new Outcome(
			array( 'item_id' => $id ),
			Rollback::make( Rollback::SNAPSHOT, 'menu_item', $id, array(), array( 'fields' => $before ) ),
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
		$fields   = Menus::fields( $item );
		$restored = isset( $rollback['before']['fields'] ) ? (array) $rollback['before']['fields'] : array();
		$current  = array();
		foreach ( array_keys( $restored ) as $field ) {
			$current[ $field ] = self::text( $fields[ $field ] );
		}
		return Assessment::reversible(
			sprintf( 'Restore the %s of menu item "%s" (%d).', implode( ', ', array_keys( $restored ) ), $fields['title'], $id ),
			array(
				'current'  => $current,
				'restored' => array_map( array( __CLASS__, 'text' ), $restored ),
			),
			array( 'fingerprint' => Menus::item_fingerprint( $id ) ),
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
		$id = (int) $rollback['target']['id'];
		if ( Menus::item_fingerprint( $id ) !== ( $expected_state['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Menu item %d changed after the rollback was assessed; nothing was restored.', (int) $id ) );
		}
		Rest::write( 'POST', '/wp/v2/menu-items/' . $id, (array) $rollback['before']['fields'] );
		return new Outcome( array( 'item_id' => $id ), Rollback::none() );
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
