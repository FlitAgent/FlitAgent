<?php
/**
 * Write tool: run_ability.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Abilities;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\AbilityRunner;
use FlitAgent\Tools\Redactor;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `run_ability`: runs one WordPress ability that is not declared read-only. FlitAgent cannot know what an ability
 * changes, so this is always destructive (approval in wp-admin, never auto-approved) with no rollback. The assessment
 * only validates the input and runs the ability's permission check; the ability runs once, on apply, through
 * `WP_Ability::execute()`. Read-only abilities go through `run_readonly_ability`; FlitAgent's own namespace is refused.
 */
final class RunAbility implements WriteTool {

	/**
	 * Returns whether the Abilities API exists; null checks for wp_get_abilities().
	 *
	 * @var callable|null
	 */
	private $available;

	/**
	 * Constructor.
	 *
	 * @param callable|null $available Availability check, for tests; null detects wp_get_abilities().
	 */
	public function __construct( ?callable $available = null ) {
		$this->available = $available;
	}

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'run_ability';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: run a WordPress ability',
			'Runs one ability registered with the WordPress Abilities API (WordPress 6.9+) that is not declared read-only, e.g. one a plugin such as WooCommerce registers, with input matching its input schema (get_ability shows it). Always needs an administrator\'s approval (returns approval_required): the approval screen shows the ability and the exact input. The ability\'s input validation and permission check run before approval; it runs once, after approval, via apply_change. Cannot be rolled back. Read-only abilities are refused here: use run_readonly_ability. Output secrets are masked; output over 512 KB is replaced by an excerpt.',
			array(
				'name'  => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 255,
					'description' => 'Ability name, e.g. "my-plugin/sync-orders". list_abilities lists them.',
				),
				'input' => array(
					'type'        => 'object',
					'description' => 'Input for the ability, matching its input schema. Leave out for abilities that take none.',
				),
			),
			array( 'name' ),
			$this->tiers(),
			true
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
	 * Assesses running the ability: input validation and permission check only, never execution.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the Abilities API is missing or input is not an object.
	 */
	public function assess( array $args ): Assessment {
		$name    = (string) $args['name'];
		$input   = AbilityRunner::input( $args );
		$ability = AbilityRunner::find( $name, $this->available );
		if ( null === $ability ) {
			return Assessment::prohibited( 'not_found', sprintf( 'No ability named "%s". Use list_abilities to see the registered abilities.', $name ) );
		}
		if ( 0 === strpos( $name, 'flitagent/' ) ) {
			return Assessment::prohibited( 'flitagent_ability', 'FlitAgent\'s own abilities are not run through run_ability.' );
		}
		if ( AbilityRunner::is_readonly( $ability ) ) {
			return Assessment::prohibited( 'readonly', sprintf( 'The ability "%s" is read-only; run it with run_readonly_ability, which needs no approval.', $name ) );
		}

		$normalized = $ability->normalize_input( $input );
		$valid      = is_wp_error( $normalized ) ? $normalized : $ability->validate_input( $normalized );
		if ( true !== $valid ) {
			return Assessment::prohibited( 'invalid_input', is_wp_error( $valid ) ? AbilityRunner::message( $valid ) : sprintf( 'The input is not valid for the ability "%s".', $name ) );
		}
		$allowed = $ability->check_permissions( $normalized );
		if ( true !== $allowed ) {
			return Assessment::prohibited( 'forbidden', is_wp_error( $allowed ) ? AbilityRunner::message( $allowed ) : sprintf( 'You do not have permission to run the ability "%s".', $name ) );
		}

		$label = (string) $ability->get_label();
		return Assessment::destructive(
			sprintf( 'Run the ability %s%s. FlitAgent cannot see what it changes, and it cannot be rolled back.', $name, '' === $label ? '' : ' ("' . $label . '")' ),
			array(
				'ability'     => $name,
				'label'       => $label,
				'category'    => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
				'annotations' => Redactor::redact( AbilityRunner::annotations( $ability ) ),
				'input'       => Redactor::redact( $input ),
			),
			array(
				'ability' => $name,
				'input'   => Canonical::hash( array( 'input' => $input ) ),
			)
		);
	}

	/**
	 * Runs the ability with the approved input.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When the ability is gone or fails.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$name    = (string) $args['name'];
		$ability = AbilityRunner::find( $name, $this->available );
		if ( null === $ability ) {
			throw new ToolError( sprintf( 'The ability "%s" is no longer registered; nothing was run.', esc_textarea( $name ) ) );
		}
		$output = $ability->execute( AbilityRunner::input( $args ) );
		if ( is_wp_error( $output ) ) {
			throw new ToolError( sprintf( 'The ability "%s" failed: %s', esc_textarea( $name ), esc_textarea( AbilityRunner::message( $output ) ) ) );
		}
		return new Outcome( array_merge( array( 'ability' => $name ), AbilityRunner::result( $output ) ), Rollback::none() );
	}

	/**
	 * Nothing to read back: the ability's effects are its own.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return $outcome;
	}
}
