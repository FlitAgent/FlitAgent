<?php
/**
 * Write tools: activate_plugin and deactivate_plugin.
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
 * `activate_plugin` / `deactivate_plugin`: switches one installed plugin on or off with core's `activate_plugin()` /
 * `deactivate_plugins()`, which run the plugin's activation or deactivation code. Always destructive (that code can
 * change data or break the site). An error thrown while activating is caught and reported, and the plugin stays
 * inactive. FlitAgent itself and network-wide plugins are refused. Rollback switches the plugin back, best effort: it
 * runs the hooks again and cannot undo what they did.
 */
final class PluginActivation implements WriteTool, Reversible {

	/**
	 * True for activate_plugin, false for deactivate_plugin.
	 *
	 * @var bool
	 */
	private $activate;

	/**
	 * Constructor.
	 *
	 * @param bool $activate True to activate, false to deactivate.
	 */
	public function __construct( bool $activate ) {
		$this->activate = $activate;
	}

	/**
	 * Tool name.
	 */
	public function name(): string {
		return $this->activate ? 'activate_plugin' : 'deactivate_plugin';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		$verb = $this->activate ? 'Activates' : 'Deactivates';
		return Definition::write(
			$this->name(),
			$this->activate ? 'FlitAgent: activate a plugin' : 'FlitAgent: deactivate a plugin',
			sprintf(
				'%s one installed plugin by its file as list_plugins shows it (e.g. "akismet/akismet.php"). Its %s code runs and can change data or break the site, so this always needs an administrator\'s approval (returns approval_required). %sFlitAgent itself and network-activated plugins are refused. rollback_change switches it back, best effort: it cannot undo what the plugin\'s hooks did. To install, update or delete plugins use install_plugin, update_plugin and delete_plugin.',
				$verb,
				$this->activate ? 'activation' : 'deactivation',
				$this->activate ? 'A fatal error while activating is caught and reported, and the plugin stays inactive. ' : ''
			),
			array(
				'plugin' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 255,
					'description' => 'Plugin file relative to the plugins directory, e.g. "hello-dolly/hello.php".',
				),
			),
			array( 'plugin' ),
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
	 * @throws ToolError When the plugin is already in the requested state.
	 */
	public function assess( array $args ): Assessment {
		$plugin = (string) $args['plugin'];
		$name   = $this->checked_name( $plugin, $this->activate );
		if ( $name instanceof Assessment ) {
			return $name;
		}
		$active = (bool) is_plugin_active( $plugin );
		if ( $active === $this->activate ) {
			throw new ToolError( sprintf( 'Plugin %s is already %s; nothing to change.', esc_textarea( $plugin ), $active ? 'active' : 'inactive' ) );
		}

		return Assessment::destructive(
			sprintf(
				'%s plugin %s (%s). Its %s code runs on this site and can change data or break it; rolling back switches it %s again but cannot undo what that code did.',
				$this->activate ? 'Activate' : 'Deactivate',
				$name,
				$plugin,
				$this->activate ? 'activation' : 'deactivation',
				$this->activate ? 'off' : 'on'
			),
			array(
				'plugin' => $plugin,
				'name'   => $name,
				'before' => array( 'active' => $active ? 'yes' : 'no' ),
				'after'  => array( 'active' => $this->activate ? 'yes' : 'no' ),
			),
			array(
				'plugin' => $plugin,
				'active' => $active,
			)
		);
	}

	/**
	 * Switches the plugin.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When the state changed, or WordPress or the plugin failed.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$plugin = (string) $args['plugin'];
		if ( (bool) is_plugin_active( $plugin ) !== $assessment->preconditions()['active'] ) {
			throw new ToolError( sprintf( 'Plugin %s changed state while the change was being applied; nothing was changed.', esc_textarea( $plugin ) ) );
		}

		$warnings = $this->set_active( $plugin, $this->activate );
		$outcome  = new Outcome(
			array(
				'plugin' => $plugin,
				'active' => (bool) is_plugin_active( $plugin ),
			),
			Rollback::make( Rollback::SNAPSHOT, 'plugin', $plugin, array(), array( 'active' => ! $this->activate ) ),
			array( 'fingerprint' => self::fingerprint( $plugin ) )
		);
		foreach ( $warnings as $code => $message ) {
			$outcome = $outcome->with_warning( $code, $message );
		}
		return $outcome;
	}

	/**
	 * Reads back the plugin's state.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		if ( (bool) is_plugin_active( (string) $args['plugin'] ) === $this->activate ) {
			return $outcome;
		}
		return $outcome->with_verification_failure( sprintf( 'WordPress reports the plugin as %s after the change.', $this->activate ? 'inactive' : 'active' ) );
	}

	/**
	 * Assesses switching the plugin back. Read-only. Destructive: it runs the plugin's hooks again.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$plugin = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		if ( ! isset( $rollback['before']['active'] ) || '' === $plugin ) {
			return Assessment::prohibited( 'no_rollback', 'This receipt does not record the plugin\'s previous state.' );
		}
		$restore = (bool) $rollback['before']['active'];
		$name    = $this->checked_name( $plugin, $restore );
		if ( $name instanceof Assessment ) {
			return $name;
		}
		$active = (bool) is_plugin_active( $plugin );
		if ( $active === $restore ) {
			return Assessment::prohibited( 'already_restored', sprintf( 'Plugin %s is already %s; nothing to roll back.', $plugin, $active ? 'active' : 'inactive' ) );
		}
		return Assessment::destructive(
			sprintf( '%s plugin %s (%s) again. Its hooks run again; what they did before is not undone.', $restore ? 'Activate' : 'Deactivate', $name, $plugin ),
			array(
				'plugin'   => $plugin,
				'current'  => array( 'active' => $active ? 'yes' : 'no' ),
				'restored' => array( 'active' => $restore ? 'yes' : 'no' ),
			),
			array( 'fingerprint' => self::fingerprint( $plugin ) )
		);
	}

	/**
	 * Switches the plugin back.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the state changed since the assessment, or WordPress or the plugin failed.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$plugin = (string) $rollback['target']['id'];
		if ( ! isset( $expected_state['fingerprint'] ) || self::fingerprint( $plugin ) !== $expected_state['fingerprint'] ) {
			throw new ToolError( sprintf( 'Plugin %s changed state after the rollback was assessed; nothing was changed.', esc_textarea( $plugin ) ) );
		}
		$restore  = (bool) $rollback['before']['active'];
		$warnings = $this->set_active( $plugin, $restore );
		$active   = (bool) is_plugin_active( $plugin );
		$outcome  = new Outcome(
			array(
				'plugin' => $plugin,
				'active' => $active,
			),
			Rollback::none()
		);
		foreach ( $warnings as $code => $message ) {
			$outcome = $outcome->with_warning( $code, $message );
		}
		return $active === $restore ? $outcome : $outcome->with_verification_failure( 'WordPress did not switch the plugin back.' );
	}

	/**
	 * The plugin's name, or a refusal: not installed or not allowed (same message), FlitAgent itself, or network-wide.
	 *
	 * @param string $plugin   Plugin file.
	 * @param bool   $activate Whether the plugin would be activated.
	 * @return string|Assessment
	 */
	private function checked_name( string $plugin, bool $activate ) {
		self::load_admin_functions();
		$verb    = $activate ? 'activate' : 'deactivate';
		$plugins = get_plugins();
		if ( ! current_user_can( $verb . '_plugin', $plugin ) || ! isset( $plugins[ $plugin ] ) || is_wp_error( validate_plugin( $plugin ) ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Plugin %s is not installed or you cannot %s it.', $plugin, $verb ) );
		}
		if ( defined( 'FLITAGENT_FILE' ) && plugin_basename( FLITAGENT_FILE ) === $plugin ) {
			return Assessment::prohibited( 'self', 'FlitAgent does not activate or deactivate itself; do that in wp-admin.' );
		}
		if ( is_plugin_active_for_network( $plugin ) || is_network_only_plugin( $plugin ) ) {
			return Assessment::prohibited( 'network', sprintf( 'Plugin %s is network-wide; FlitAgent does not change network activation.', $plugin ) );
		}
		$name = isset( $plugins[ $plugin ]['Name'] ) && '' !== (string) $plugins[ $plugin ]['Name'] ? (string) $plugins[ $plugin ]['Name'] : $plugin;
		return wp_strip_all_tags( $name );
	}

	/**
	 * Activates or deactivates through core, catching errors thrown by the plugin's code. Returns warnings.
	 *
	 * @param string $plugin   Plugin file.
	 * @param bool   $activate Target state.
	 * @return array<string, string>
	 * @throws ToolError When the plugin's code threw or WordPress refused.
	 */
	private function set_active( string $plugin, bool $activate ): array {
		$level = ob_get_level();
		try {
			$result = $activate ? activate_plugin( $plugin, '', false, false ) : deactivate_plugins( $plugin, false, false );
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			throw new ToolError(
				sprintf(
					'%s plugin %s failed with %s: %s. The plugin %s; code that ran before the error may have changed data.',
					$activate ? 'Activating' : 'Deactivating',
					esc_textarea( $plugin ),
					$e instanceof \Error ? 'a fatal error' : 'an error',
					esc_textarea( rtrim( ErrorText::of( $e ), '.' ) ),
					is_plugin_active( $plugin ) ? 'is still active' : 'was left inactive'
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			if ( 'unexpected_output' === $result->get_error_code() ) {
				return array( 'unexpected_output' => 'The plugin printed output while activating (often a PHP notice or warning); it is active. Check the site for errors.' );
			}
			throw new ToolError( sprintf( 'WordPress did not %s plugin %s: %s', $activate ? 'activate' : 'deactivate', esc_textarea( $plugin ), esc_textarea( ErrorText::clean( $result->get_error_message() ) ) ) );
		}
		return array();
	}

	/**
	 * Fingerprint of the plugin's state.
	 *
	 * @param string $plugin Plugin file.
	 * @return string
	 */
	private static function fingerprint( string $plugin ): string {
		return $plugin . ':' . ( is_plugin_active( $plugin ) ? 'active' : 'inactive' );
	}

	/**
	 * Loads wp-admin/includes/plugin.php when a REST request has not.
	 */
	private static function load_admin_functions(): void {
		if ( ! function_exists( 'activate_plugin' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
