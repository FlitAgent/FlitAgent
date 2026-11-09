<?php
/**
 * Write tool: delete_plugin.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Plugins;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `delete_plugin`: deletes one inactive plugin with core's `delete_plugins()`, which runs the plugin's uninstall
 * routine and removes its files. Always destructive and never reversible: the uninstall routine may remove the plugin's
 * data, so there is no rollback.
 */
final class DeletePlugin implements WriteTool {

	/**
	 * Upgrader.
	 *
	 * @var Upgrader
	 */
	private $upgrader;

	/**
	 * Constructor.
	 *
	 * @param Upgrader|null $upgrader Upgrader; core's by default.
	 */
	public function __construct( ?Upgrader $upgrader = null ) {
		$this->upgrader = null === $upgrader ? new Upgrader() : $upgrader;
	}

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_plugin';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete a plugin',
			'Deletes one inactive plugin by its file as list_plugins shows it (e.g. "hello-dolly/hello.php"): WordPress runs its uninstall routine, which may remove its settings and data, and deletes its files. This cannot be rolled back. Always needs an administrator\'s approval (returns approval_required). Refused for FlitAgent itself, active plugins (deactivate_plugin first), when this site disallows file changes or needs FTP credentials, and on multisite.',
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
	 * Assesses the delete. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$plugin  = (string) $args['plugin'];
		$refusal = Site::preflight( 'delete', false );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$data = Site::plugin( $plugin );
		if ( null === $data || ! current_user_can( 'delete_plugins' ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Plugin %s is not installed or you cannot delete it.', $plugin ) );
		}
		if ( Site::is_self( $plugin ) ) {
			return Assessment::prohibited( 'self', 'FlitAgent does not install, update or delete itself; do that in wp-admin.' );
		}
		if ( Site::is_active( $plugin ) ) {
			return Assessment::prohibited( 'active', sprintf( 'Plugin %s is active; deactivate it first (deactivate_plugin), then delete it.', $plugin ) );
		}
		$name    = Site::name( $plugin, $data );
		$version = Site::text( $data, 'Version' );

		return Assessment::destructive(
			sprintf( 'Delete plugin %s %s (%s): its uninstall routine runs and may remove its settings and data, and its files are deleted. This cannot be rolled back.', $name, $version, $plugin ),
			array(
				'plugin'      => $plugin,
				'name'        => $name,
				'before'      => array(
					'installed' => 'yes',
					'version'   => $version,
				),
				'after'       => array(
					'installed' => 'no',
					'version'   => 'none',
				),
				'backup'      => Backups::status(),
				'cannot_undo' => 'This cannot be rolled back. A WordPress.org plugin can be installed again with install_plugin, but settings and data its uninstall routine removes do not come back, and a plugin from elsewhere has to be reinstalled by hand.',
			),
			array(
				'plugin'  => $plugin,
				'version' => $version,
				'active'  => false,
			)
		);
	}

	/**
	 * Deletes the plugin.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When the plugin changed meanwhile or core failed.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$expected = $assessment->preconditions();
		$plugin   = (string) $expected['plugin'];
		if ( null === Site::plugin( $plugin ) || Site::version( $plugin ) !== $expected['version'] || Site::is_active( $plugin ) ) {
			throw new ToolError( sprintf( 'Plugin %s changed while the change was being applied; nothing was deleted.', esc_textarea( $plugin ) ) );
		}

		$this->upgrader->delete( $plugin );

		return new Outcome(
			array(
				'plugin'  => $plugin,
				'deleted' => true,
			),
			Rollback::none()
		);
	}

	/**
	 * Checks the plugin is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return null === Site::plugin( (string) $args['plugin'] ) ? $outcome : $outcome->with_verification_failure( 'WordPress still lists the plugin after deleting it.' );
	}
}
