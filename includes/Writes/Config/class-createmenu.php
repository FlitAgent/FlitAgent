<?php
/**
 * Write tool: create_menu.
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
 * `create_menu`: creates an empty navigation menu through the core menus controller. It assigns no location (that is
 * assign_menu_location), so rollback deletes the menu while it is still empty and unchanged.
 */
final class CreateMenu implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'create_menu';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: create a menu',
			'Creates an empty navigation menu (classic menus, Appearance > Menus). Add items with add_menu_item and show it with assign_menu_location. Always returns approval_required; undo with rollback_change.',
			array(
				'name'        => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 100,
					'description' => 'Menu name (must not be taken).',
				),
				'description' => array(
					'type'        => 'string',
					'maxLength'   => 500,
					'description' => 'Optional description.',
				),
			),
			array( 'name' ),
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
	 * Never auto-approvable.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the new menu. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the name is empty or taken.
	 */
	public function assess( array $args ): Assessment {
		$name = trim( (string) $args['name'] );
		if ( '' === $name ) {
			throw new ToolError( 'The menu name must not be empty.' );
		}
		$refusal = Menus::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$names = self::names();
		if ( isset( $names[ strtolower( $name ) ] ) ) {
			throw new ToolError( sprintf( 'A menu named "%s" already exists (id %d).', esc_textarea( $name ), (int) $names[ strtolower( $name ) ] ) );
		}

		return Assessment::reversible(
			sprintf( 'Create an empty menu "%s".', $name ),
			array(
				'before' => array( 'menu' => '(none)' ),
				'after'  => array(
					'menu'        => $name,
					'description' => isset( $args['description'] ) ? (string) $args['description'] : '',
				),
			),
			array( 'fingerprint' => Canonical::hash( $names ) ),
			false
		);
	}

	/**
	 * Creates the menu.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the menus changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		if ( Canonical::hash( self::names() ) !== ( $assessment->preconditions()['fingerprint'] ?? '' ) ) {
			throw new ToolError( 'The menus changed after the request was assessed; nothing was created.' );
		}
		$menu = Rest::write(
			'POST',
			'/wp/v2/menus',
			array(
				'name'        => trim( (string) $args['name'] ),
				'description' => isset( $args['description'] ) ? (string) $args['description'] : '',
			)
		);
		$id   = isset( $menu['id'] ) ? (int) $menu['id'] : 0;
		if ( $id <= 0 ) {
			throw new ToolError( 'WordPress did not return the new menu.' );
		}
		return new Outcome(
			array( 'menu_id' => $id ),
			Rollback::make( Rollback::SNAPSHOT, 'menu', $id, array(), array( 'exists' => false ) ),
			array( 'fingerprint' => Menus::menu_fingerprint( $id ) )
		);
	}

	/**
	 * Reads the menu back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$menu = Menus::menu( (int) $outcome->result()['menu_id'] );
		if ( null === $menu ) {
			return $outcome->with_verification_failure( 'The new menu could not be read back.' );
		}
		return trim( (string) $args['name'] ) === $menu['name'] ? $outcome : $outcome->with_warning( 'stored_differently', sprintf( 'WordPress stored the menu name as "%s".', $menu['name'] ) );
	}

	/**
	 * Assesses deleting the menu again. Read-only.
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
		$menu = Menus::menu( $id );
		if ( null === $menu ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Menu %d no longer exists.', $id ) );
		}
		$items = (array) Menus::items( $id );
		return Assessment::reversible(
			sprintf( 'Delete menu "%s" (%d), which the write created (%s now).', $menu['name'], $id, Summary::count( count( $items ), 'item', 'items' ) ),
			array(
				'current'  => array(
					'menu'  => (string) $menu['name'],
					'items' => (string) count( $items ),
				),
				'restored' => array( 'menu' => '(none)' ),
			),
			array( 'fingerprint' => Menus::menu_fingerprint( $id ) ),
			false
		);
	}

	/**
	 * Deletes the menu, if it is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the menu changed or WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$id = (int) $rollback['target']['id'];
		if ( Menus::menu_fingerprint( $id ) !== ( $expected_state['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Menu %d changed after the rollback was assessed; nothing was deleted.', (int) $id ) );
		}
		Rest::write( 'DELETE', '/wp/v2/menus/' . $id, array( 'force' => true ) );
		return new Outcome( array( 'deleted_menu_id' => $id ), Rollback::none() );
	}

	/**
	 * Lower-case menu name => id.
	 *
	 * @return array<string, int>
	 */
	private static function names(): array {
		$names = array();
		foreach ( Menus::all() as $menu ) {
			$names[ strtolower( (string) $menu['name'] ) ] = (int) $menu['id'];
		}
		ksort( $names );
		return $names;
	}
}
