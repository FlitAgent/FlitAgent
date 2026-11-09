<?php
/**
 * Write tool: update_option.
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
 * `update_option`: sets one allowlisted option (WritableOptions) to a value converted and validated against the
 * setting's schema. Reversible from a snapshot of the previous value; never auto-approvable, because options are
 * site-wide.
 */
final class UpdateOption implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'update_option';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: update a site option',
			'Sets one WordPress option to a new value. Only settings registered with show_in_rest that hold a single value, and a fixed list of core reading, discussion and media options (blogname, blogdescription, timezone_string, date_format, posts_per_page, show_on_front, page_on_front, comment settings, image sizes, ...), can be changed; site URLs, admin email, registration, roles, plugins, themes, cron, keys and salts, and secret-looking options are refused. Send the value as text; it is converted to the setting\'s type ("10", "true", "4.5"). Always returns approval_required; undo with rollback_change.',
			array(
				'name'  => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_.:-]{1,191}$',
					'description' => 'Exact option name, e.g. blogdescription.',
				),
				'value' => array(
					'type'        => 'string',
					'maxLength'   => 10000,
					'description' => 'New value as text.',
				),
			),
			array( 'name', 'value' ),
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
	 * Never auto-approvable: options are site-wide.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the update. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the value does not fit the setting or would not change anything.
	 */
	public function assess( array $args ): Assessment {
		$name   = (string) $args['name'];
		$schema = Options::writable( $name );
		if ( $schema instanceof Assessment ) {
			return $schema;
		}

		$value = WritableOptions::convert( $name, (string) $args['value'], $schema );
		$state = Options::read( $name );
		if ( Options::holds( $state, $value ) ) {
			throw new ToolError( sprintf( 'The option "%s" already has that value; nothing to change.', esc_textarea( $name ) ) );
		}

		$after = Options::display(
			array(
				'exists' => true,
				'value'  => $value,
			)
		);
		return Assessment::reversible(
			sprintf( 'Change the site option "%s" from "%s" to "%s".', $name, self::short( Options::display( $state ) ), self::short( $after ) ),
			array(
				'option' => $name,
				'before' => Options::display( $state ),
				'after'  => $after,
			),
			array( 'fingerprint' => Options::fingerprint( $state ) ),
			false
		);
	}

	/**
	 * Writes the option.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the option changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$name   = (string) $args['name'];
		$before = Options::unchanged( $name, $assessment->preconditions(), 'changed' );
		$value  = self::value( $args );

		// update_option() returns false without creating a missing option whose value equals its registered default.
		if ( ! update_option( $name, $value ) && ( $before['exists'] || ! add_option( $name, $value ) ) ) {
			throw new ToolError( sprintf( 'WordPress could not update the option "%s".', esc_textarea( $name ) ) );
		}

		return new Outcome(
			array(
				'option'  => $name,
				'updated' => true,
			),
			Rollback::make( Rollback::SNAPSHOT, 'option', $name, array(), Options::snapshot( $before ) ),
			array( 'fingerprint' => Options::fingerprint( Options::read( $name ) ) )
		);
	}

	/**
	 * Reads the option back. A sanitize callback may store it differently.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$name  = (string) $args['name'];
		$state = Options::read( $name );
		if ( ! $state['exists'] ) {
			return $outcome->with_verification_failure( sprintf( 'The option "%s" could not be read back after the update.', $name ) );
		}
		if ( Options::holds( $state, self::value( $args ) ) ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result(
			array(
				'stored_verbatim' => false,
				'stored_value'    => Options::display( $state ),
			)
		)->with_warning( 'stored_differently', sprintf( 'WordPress stored the option "%s" differently from the request (its sanitize callback changed it).', $name ) );
	}

	/**
	 * Assesses restoring the previous value. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		return self::assess_restore( $rollback, false );
	}

	/**
	 * Restores the previous value, or deletes an option the write created.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the option changed or WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		return self::restore_snapshot( $rollback, $expected_state );
	}

	/**
	 * Shared by update_option and delete_option: assesses putting the snapshot back.
	 *
	 * @param array<string, mixed> $rollback    Rollback reference.
	 * @param bool                 $destructive Whether restoring is destructive.
	 * @return Assessment
	 */
	public static function assess_restore( array $rollback, bool $destructive ): Assessment {
		$name   = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		$schema = Options::writable( $name );
		if ( $schema instanceof Assessment ) {
			return $schema;
		}

		$before   = isset( $rollback['before'] ) && is_array( $rollback['before'] ) ? $rollback['before'] : array();
		$restored = array(
			'exists' => ! empty( $before['exists'] ),
			'value'  => Options::snapshot_value( $before ),
		);
		$current  = Options::read( $name );
		$summary  = sprintf( 'Restore the site option "%s" to "%s" (now "%s").', $name, self::short( Options::display( $restored ) ), self::short( Options::display( $current ) ) );
		$preview  = array(
			'option'   => $name,
			'current'  => Options::display( $current ),
			'restored' => Options::display( $restored ),
		);
		$state    = array( 'fingerprint' => Options::fingerprint( $current ) );

		return $destructive ? Assessment::destructive( $summary, $preview, $state ) : Assessment::reversible( $summary, $preview, $state, false );
	}

	/**
	 * Shared by update_option and delete_option: puts the snapshot back if the option is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public static function restore_snapshot( array $rollback, array $expected_state ): Outcome {
		$name    = (string) $rollback['target']['id'];
		$current = Options::unchanged( $name, $expected_state, 'restored' );
		$before  = isset( $rollback['before'] ) && is_array( $rollback['before'] ) ? $rollback['before'] : array();
		Options::restore( $name, $before, $current );

		$outcome  = new Outcome(
			array(
				'option'   => $name,
				'restored' => true,
			),
			Rollback::none()
		);
		$after    = Options::read( $name );
		$expected = ! empty( $before['exists'] );
		if ( $expected !== $after['exists'] ) {
			return $outcome->with_verification_failure( sprintf( 'The option "%s" could not be read back as restored.', $name ) );
		}
		return $outcome;
	}

	/**
	 * The requested value, converted.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return string|int|float|bool
	 * @throws ToolError When the option is no longer writable.
	 */
	private static function value( array $args ) {
		$name   = (string) $args['name'];
		$schema = WritableOptions::refusal( $name ) === null ? WritableOptions::schema( $name ) : null;
		if ( null === $schema ) {
			throw new ToolError( sprintf( 'The option "%s" is no longer writable.', esc_textarea( $name ) ) );
		}
		return WritableOptions::convert( $name, (string) $args['value'], $schema );
	}

	/**
	 * A value shortened for a summary.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function short( string $text ): string {
		preg_match( '/^.{0,80}/su', $text, $match );
		$short = isset( $match[0] ) ? $match[0] : '';
		return $short === $text ? $text : $short . '...';
	}
}
