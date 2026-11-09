<?php
/**
 * Write tool: assign_menu_location.
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
 * `assign_menu_location`: shows a menu in one of the theme's registered menu locations (or empties it), through the
 * core menus controller's `locations` field. Reversible: rollback gives the location back to the menu that had it.
 */
final class AssignMenuLocation implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'assign_menu_location';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: assign a menu location',
			'Shows a menu in one of the active theme\'s registered menu locations (see the theme\'s menu locations), replacing the menu shown there; menu_id 0 empties the location. Always returns approval_required; undo with rollback_change.',
			array(
				'location' => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_-]{1,100}$',
					'description' => 'Location slug, e.g. primary.',
				),
				'menu_id'  => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Menu id, or 0 to empty the location.',
				),
			),
			array( 'location', 'menu_id' ),
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
	 * Assesses the assignment. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the location is unknown or already shows that menu.
	 */
	public function assess( array $args ): Assessment {
		$location = (string) $args['location'];
		$menu_id  = (int) $args['menu_id'];
		$refusal  = Menus::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$owner = self::owner( $location );
		if ( $menu_id > 0 && null === Menus::menu( $menu_id ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Menu %d was not found or you cannot edit it.', $menu_id ) );
		}
		if ( $owner === $menu_id ) {
			throw new ToolError( sprintf( 'Location %s already shows %s; nothing to change.', esc_textarea( $location ), esc_textarea( Menus::label( $menu_id ) ) ) );
		}
		return Assessment::reversible(
			sprintf( 'Show %s in menu location %s (now %s).', Menus::label( $menu_id ), $location, Menus::label( $owner ) ),
			array(
				'before' => array(
					'location' => $location,
					'menu'     => Menus::label( $owner ),
				),
				'after'  => array(
					'location' => $location,
					'menu'     => Menus::label( $menu_id ),
				),
			),
			array( 'fingerprint' => self::fingerprint( $location ) ),
			false
		);
	}

	/**
	 * Assigns the location.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the location changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$location = (string) $args['location'];
		if ( self::fingerprint( $location ) !== ( $assessment->preconditions()['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Location %s changed after the request was assessed; nothing was changed.', esc_textarea( $location ) ) );
		}
		$owner = self::owner( $location );
		Menus::assign( $location, (int) $args['menu_id'], $owner );
		return new Outcome(
			array(
				'location' => $location,
				'menu_id'  => (int) $args['menu_id'],
			),
			Rollback::make( Rollback::SNAPSHOT, 'menu_location', $location, array(), array( 'menu_id' => $owner ) ),
			array( 'fingerprint' => self::fingerprint( $location ) )
		);
	}

	/**
	 * Reads the location back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return self::owner( (string) $args['location'] ) === (int) $args['menu_id'] ? $outcome : $outcome->with_verification_failure( 'The menu location could not be read back as assigned.' );
	}

	/**
	 * Assesses giving the location back. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$location = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		$previous = isset( $rollback['before']['menu_id'] ) ? (int) $rollback['before']['menu_id'] : 0;
		$refusal  = Menus::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( ! array_key_exists( $location, Menus::locations() ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Location %s is no longer registered by the active theme.', $location ) );
		}
		if ( $previous > 0 && null === Menus::menu( $previous ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Menu %d, which had location %s, no longer exists.', $previous, $location ) );
		}
		$owner = self::owner( $location );
		return Assessment::reversible(
			sprintf( 'Show %s in menu location %s again (now %s).', Menus::label( $previous ), $location, Menus::label( $owner ) ),
			array(
				'current'  => array(
					'location' => $location,
					'menu'     => Menus::label( $owner ),
				),
				'restored' => array(
					'location' => $location,
					'menu'     => Menus::label( $previous ),
				),
			),
			array( 'fingerprint' => self::fingerprint( $location ) ),
			false
		);
	}

	/**
	 * Gives the location back, if it is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the location changed or WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$location = (string) $rollback['target']['id'];
		if ( self::fingerprint( $location ) !== ( $expected_state['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Location %s changed after the rollback was assessed; nothing was restored.', esc_textarea( $location ) ) );
		}
		$previous = (int) $rollback['before']['menu_id'];
		Menus::assign( $location, $previous, self::owner( $location ) );
		return new Outcome(
			array(
				'location' => $location,
				'menu_id'  => $previous,
			),
			Rollback::none()
		);
	}

	/**
	 * Menu shown in a location (0 for none).
	 *
	 * @param string $location Location slug.
	 * @return int
	 * @throws ToolError When the location is not registered.
	 */
	private static function owner( string $location ): int {
		$locations = Menus::locations();
		if ( ! array_key_exists( $location, $locations ) ) {
			throw new ToolError( sprintf( '%s is not a registered menu location of the active theme. Registered: %s.', esc_textarea( $location ), array() === $locations ? '(none)' : esc_textarea( implode( ', ', array_keys( $locations ) ) ) ) );
		}
		return $locations[ $location ];
	}

	/**
	 * Fingerprint of a location's assignment.
	 *
	 * @param string $location Location slug.
	 * @return string
	 */
	private static function fingerprint( string $location ): string {
		$locations = Menus::locations();
		return Canonical::hash( array( $location, isset( $locations[ $location ] ) ? $locations[ $location ] : null ) );
	}
}
