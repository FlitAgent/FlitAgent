<?php
/**
 * Write tool: switch_theme.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

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
 * `switch_theme`: activates an installed theme with core's `switch_theme()`. Destructive (the whole front end changes;
 * menus and widgets are remapped), so always approved by a human. Themes that are missing, broken, fail the files
 * `validate_current_theme()` checks, or do not meet their WordPress/PHP requirements are refused. Rollback switches
 * back to the previous stylesheet; theme mods are kept per theme, but core's menu and widget remapping is not undone.
 * Both warn about the widgets WordPress will move to other areas on the next request (WidgetForecast).
 */
final class SwitchTheme implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'switch_theme';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: switch the active theme',
			'Activates an installed theme by its stylesheet (directory name, as list_themes shows it). The whole front end changes, so this always needs an administrator\'s approval (returns approval_required). Themes that are missing, broken (missing parent, style.css or index template) or need a newer WordPress or PHP are refused. rollback_change switches back to the previous theme. Both report widgets WordPress will move to other widget areas as warnings (widgets_moved, or widgets_unpredicted when the new theme\'s widget areas are not known yet); menu assignments may need checking. To install, update or delete themes use install_theme, update_theme and delete_theme.',
			array(
				'stylesheet' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 255,
					'description' => 'Theme directory name, e.g. "twentytwentyfive".',
				),
			),
			array( 'stylesheet' ),
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
	 * Assesses the switch. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the theme is already active.
	 */
	public function assess( array $args ): Assessment {
		$stylesheet = (string) $args['stylesheet'];
		$theme      = self::checked_theme( $stylesheet );
		if ( $theme instanceof Assessment ) {
			return $theme;
		}
		$current = self::state();
		if ( $current['stylesheet'] === $stylesheet ) {
			throw new ToolError( sprintf( 'Theme %s is already active; nothing to change.', esc_textarea( $stylesheet ) ) );
		}

		$name = wp_strip_all_tags( (string) $theme->get( 'Name' ) );
		return Assessment::destructive(
			sprintf( 'Switch the active theme from %s to %s (%s). The whole site\'s design changes; menus and widgets may be reassigned.', $current['stylesheet'], $name, $stylesheet ),
			array(
				'before' => $current,
				'after'  => array(
					'stylesheet' => $stylesheet,
					'template'   => (string) $theme->get_template(),
				),
			),
			$current
		);
	}

	/**
	 * Switches the theme.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When the active theme changed meanwhile.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$before = self::state();
		if ( $before !== $assessment->preconditions() ) {
			throw new ToolError( 'The active theme changed while the switch was being applied; nothing was changed.' );
		}
		$widgets = WidgetForecast::placement();
		switch_theme( (string) $args['stylesheet'] );

		$after   = self::state();
		$outcome = new Outcome(
			$after,
			Rollback::make( Rollback::SNAPSHOT, 'theme', $before['stylesheet'], array(), $before + array( 'widgets' => $widgets ) ),
			array( 'fingerprint' => self::fingerprint( $after ) )
		);
		return $after['stylesheet'] === (string) $args['stylesheet'] ? WidgetForecast::warn( $outcome, $widgets ) : $outcome;
	}

	/**
	 * Reads back the active theme.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return get_stylesheet() === (string) $args['stylesheet'] ? $outcome : $outcome->with_verification_failure( 'WordPress did not activate the theme.' );
	}

	/**
	 * Assesses switching back. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$previous = isset( $rollback['before']['stylesheet'] ) ? (string) $rollback['before']['stylesheet'] : '';
		if ( '' === $previous ) {
			return Assessment::prohibited( 'no_rollback', 'This receipt does not record the previous theme.' );
		}
		$theme = self::checked_theme( $previous );
		if ( $theme instanceof Assessment ) {
			return $theme;
		}
		$current = self::state();
		if ( $current['stylesheet'] === $previous ) {
			return Assessment::prohibited( 'already_restored', sprintf( 'Theme %s is already active; nothing to roll back.', $previous ) );
		}
		return Assessment::destructive(
			sprintf( 'Switch the active theme from %s back to %s.', $current['stylesheet'], $previous ),
			array(
				'current'  => $current,
				'restored' => array(
					'stylesheet' => $previous,
					'template'   => (string) $theme->get_template(),
				),
			),
			array( 'fingerprint' => self::fingerprint( $current ) )
		);
	}

	/**
	 * Switches back.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the active theme changed since the assessment.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		if ( ! isset( $expected_state['fingerprint'] ) || self::fingerprint( self::state() ) !== $expected_state['fingerprint'] ) {
			throw new ToolError( 'The active theme changed after the rollback was assessed; nothing was changed.' );
		}
		$previous = (string) $rollback['before']['stylesheet'];
		$recorded = isset( $rollback['before']['widgets'] ) && is_array( $rollback['before']['widgets'] );
		$widgets  = $recorded ? array_map( 'strval', $rollback['before']['widgets'] ) : WidgetForecast::placement();
		switch_theme( $previous );
		$after   = self::state();
		$outcome = new Outcome( $after, Rollback::none() );
		if ( $after['stylesheet'] !== $previous ) {
			return $outcome->with_verification_failure( 'WordPress did not switch back to the previous theme.' );
		}
		return WidgetForecast::warn( $outcome, $widgets, $recorded ? ' before the switch' : '' );
	}

	/**
	 * The theme, or a refusal: not allowed, not installed, broken, missing files or unmet requirements.
	 *
	 * @param string $stylesheet Stylesheet.
	 * @return object|Assessment
	 */
	private static function checked_theme( string $stylesheet ) {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return Assessment::prohibited( 'forbidden', 'Switching themes needs the switch_themes capability.' );
		}
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Theme %s is not installed.', $stylesheet ) );
		}
		$errors = $theme->errors();
		if ( is_wp_error( $errors ) ) {
			return Assessment::prohibited( 'broken', sprintf( 'Theme %s is broken: %s', $stylesheet, ErrorText::clean( $errors->get_error_message() ) ) );
		}
		if ( ! self::has_required_files( $theme ) ) {
			return Assessment::prohibited( 'invalid', sprintf( 'Theme %s is missing style.css or an index template (templates/index.html or index.php), so WordPress would switch away from it.', $stylesheet ) );
		}
		$requirements = validate_theme_requirements( $stylesheet );
		if ( is_wp_error( $requirements ) ) {
			return Assessment::prohibited( 'requirements', ErrorText::clean( $requirements->get_error_message() ) );
		}
		return $theme;
	}

	/**
	 * The file checks of core's validate_current_theme(), for a theme that is not active yet.
	 *
	 * @param object $theme Theme.
	 * @return bool
	 */
	private static function has_required_files( $theme ): bool {
		$template_dir = (string) $theme->get_template_directory();
		$index        = file_exists( $template_dir . '/templates/index.html' ) || file_exists( $template_dir . '/block-templates/index.html' ) || file_exists( $template_dir . '/index.php' );
		if ( ! $index || ! file_exists( $template_dir . '/style.css' ) ) {
			return false;
		}
		return $theme->get_stylesheet() === $theme->get_template() || file_exists( (string) $theme->get_stylesheet_directory() . '/style.css' );
	}

	/**
	 * Active stylesheet and template.
	 *
	 * @return array{stylesheet: string, template: string}
	 */
	private static function state(): array {
		return array(
			'stylesheet' => (string) get_stylesheet(),
			'template'   => (string) get_template(),
		);
	}

	/**
	 * Fingerprint of a theme state.
	 *
	 * @param array{stylesheet: string, template: string} $state State.
	 * @return string
	 */
	private static function fingerprint( array $state ): string {
		return $state['stylesheet'] . '|' . $state['template'];
	}
}
