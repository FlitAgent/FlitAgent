<?php
/**
 * Write tool: delete_option.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Redactor;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `delete_option`: deletes one allowlisted option (WritableOptions). Always destructive, because plugins react to a
 * missing option in ways a restore cannot undo; the value and autoload flag are kept so rollback_change can re-create
 * it (also with approval).
 */
final class DeleteOption implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_option';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete a site option',
			'Deletes one WordPress option. Only options update_option may change can be deleted (registered show_in_rest settings with a single value and a fixed list of core options); protected and secret-looking options are refused. Always needs an administrator\'s approval (returns approval_required with the current value, secrets masked). rollback_change re-creates it with its value and autoload flag, also with approval.',
			array(
				'name' => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_.:-]{1,191}$',
					'description' => 'Exact option name.',
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
	 * @throws ToolError When the option does not exist.
	 */
	public function assess( array $args ): Assessment {
		$name   = (string) $args['name'];
		$schema = Options::writable( $name );
		if ( $schema instanceof Assessment ) {
			return $schema;
		}

		$state = Options::read( $name );
		if ( ! $state['exists'] ) {
			throw new ToolError( sprintf( 'The option "%s" does not exist; nothing to delete.', esc_textarea( $name ) ) );
		}

		$shown = Options::display(
			array(
				'exists' => true,
				'value'  => Redactor::redact( array( 'value' => $state['value'] ) )['value'],
			)
		);
		return Assessment::destructive(
			sprintf( 'Delete the site option "%s" (now "%s"). Plugins and themes then fall back to their defaults.', $name, UpdateOption::short( $shown ) ),
			array(
				'option' => $name,
				'before' => $shown,
				'after'  => '(deleted)',
			),
			array( 'fingerprint' => Options::fingerprint( $state ) )
		);
	}

	/**
	 * Deletes the option, keeping its value and autoload flag for rollback.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the option changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$name     = (string) $args['name'];
		$before   = Options::unchanged( $name, $assessment->preconditions(), 'deleted' );
		$autoload = array_key_exists( $name, (array) wp_load_alloptions() );

		if ( ! delete_option( $name ) ) {
			throw new ToolError( sprintf( 'WordPress could not delete the option "%s".', esc_textarea( $name ) ) );
		}

		return new Outcome(
			array(
				'option'  => $name,
				'deleted' => true,
			),
			Rollback::make( Rollback::SNAPSHOT, 'option', $name, array(), Options::snapshot( $before ) + array( 'autoload' => $autoload ) ),
			array( 'fingerprint' => Options::fingerprint( Options::read( $name ) ) )
		);
	}

	/**
	 * Checks the option is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$name = (string) $args['name'];
		return Options::read( $name )['exists']
			? $outcome->with_verification_failure( sprintf( 'The option "%s" still exists after the delete.', $name ) )
			: $outcome;
	}

	/**
	 * Assesses re-creating the option. Destructive like the delete. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		return UpdateOption::assess_restore( $rollback, true );
	}

	/**
	 * Re-creates the option with its value and autoload flag.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		return UpdateOption::restore_snapshot( $rollback, $expected_state );
	}
}
