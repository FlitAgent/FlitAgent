<?php
/**
 * Write tool: install_plugin.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Plugins;

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
 * `install_plugin`: installs the latest release of one WordPress.org plugin by slug, inactive, through core's
 * Plugin_Upgrader. Always destructive. Only the WordPress.org directory is a source. Rollback deletes the plugin again
 * while it is still inactive and the installed version.
 */
final class InstallPlugin implements WriteTool, Reversible {

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
		return 'install_plugin';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: install a plugin from WordPress.org',
			'Installs the latest release of one plugin from the WordPress.org directory by its slug (e.g. "classic-editor", as search_plugin_directory shows it), and leaves it inactive (use activate_plugin afterwards). Only WordPress.org is a source: no ZIP URLs or uploads. As in wp-admin, WordPress then also installs available translation updates and checks WordPress.org for updates. Always needs an administrator\'s approval (returns approval_required with the version, requirements, links and backup status). Refused when the plugin is already installed, the release needs a newer WordPress or PHP, this site disallows file changes or needs FTP credentials, or on multisite. rollback_change deletes it again while it is inactive and still at the installed version (its uninstall routine runs).',
			array(
				'slug' => array(
					'type'        => 'string',
					'pattern'     => '^[a-z0-9][a-z0-9_-]{0,199}$',
					'description' => 'WordPress.org plugin slug, e.g. "classic-editor".',
				),
			),
			array( 'slug' ),
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
	 * Assesses the install. Read-only (it asks WordPress.org about the slug).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the slug is malformed.
	 */
	public function assess( array $args ): Assessment {
		$slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? $args['slug'] : '';
		if ( 1 !== preg_match( Directory::SLUG_PATTERN, $slug ) ) {
			throw new ToolError( 'slug must be a WordPress.org plugin slug: lowercase letters, digits, hyphens and underscores, e.g. "classic-editor".' );
		}
		$refusal = Site::preflight( 'install', true );
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( Site::is_self_slug( $slug ) ) {
			return Assessment::prohibited( 'self', 'FlitAgent does not install, update or delete itself; do that in wp-admin.' );
		}
		$installed = Site::find_by_slug( $slug );
		if ( null !== $installed ) {
			return Assessment::prohibited( 'installed', sprintf( 'A plugin is already installed as %s; use update_plugin to update it.', $installed ) );
		}
		$release = Directory::latest( $slug );
		if ( $release instanceof Assessment ) {
			return $release;
		}
		$incompatible = Directory::incompatibility( $release['name'], $release['version'], $release['requires'], $release['requires_php'] );
		if ( '' !== $incompatible ) {
			return Assessment::prohibited( 'incompatible', $incompatible . ' FlitAgent does not install it.' );
		}

		return Assessment::destructive(
			sprintf( 'Install plugin %s %s (%s) from WordPress.org, inactive. Its files are added to the site; no plugin code runs until it is activated. Rolling back deletes it again while it stays inactive.', $release['name'], $release['version'], $slug ),
			array(
				'name'        => $release['name'],
				'slug'        => $slug,
				'before'      => array(
					'installed' => 'no',
					'version'   => 'none',
				),
				'after'       => array(
					'installed' => 'yes',
					'version'   => $release['version'],
					'active'    => 'no',
				),
				'requires'    => Directory::requirements( $release['requires'], $release['requires_php'] ),
				'links'       => Directory::links( $slug ),
				'backup'      => Backups::status(),
				'cannot_undo' => 'Rolling back deletes the plugin, which runs its uninstall routine. FlitAgent refuses that rollback while the plugin is active, or once its version is no longer the one installed here.',
			),
			array(
				'slug'    => $slug,
				'version' => $release['version'],
				'package' => $release['package'],
			)
		);
	}

	/**
	 * Installs the package.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When the plugin appeared meanwhile or core failed.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$expected = $assessment->preconditions();
		$slug     = (string) $expected['slug'];
		if ( null !== Site::find_by_slug( $slug ) ) {
			throw new ToolError( sprintf( 'Plugin %s was installed while the change was being applied; nothing was changed.', esc_textarea( $slug ) ) );
		}

		$plugin = $this->upgrader->install( (string) $expected['package'] );

		return new Outcome(
			array(
				'plugin'  => $plugin,
				'slug'    => $slug,
				'version' => (string) $expected['version'],
				'active'  => Site::is_active( $plugin ),
			),
			Rollback::make(
				Rollback::SNAPSHOT,
				'plugin',
				$plugin,
				array(
					'slug'    => $slug,
					'version' => Site::version( $plugin ),
				),
				array( 'installed' => false )
			),
			array( 'fingerprint' => Site::fingerprint( $plugin ) )
		);
	}

	/**
	 * Reads the plugin back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$result = $outcome->result();
		$plugin = (string) ( $result['plugin'] ?? '' );
		if ( '' === $plugin || null === Site::plugin( $plugin ) ) {
			return $outcome->with_verification_failure( 'WordPress does not list the plugin after the install.' );
		}
		$version  = Site::version( $plugin );
		$expected = (string) ( $result['version'] ?? '' );
		if ( $expected !== $version ) {
			return $outcome->with_warning( 'version_mismatch', sprintf( 'WordPress reports version %s after installing %s.', $version, $expected ) );
		}
		return $outcome;
	}

	/**
	 * Assesses deleting the plugin again. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$plugin  = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		$version = isset( $rollback['refs']['version'] ) ? (string) $rollback['refs']['version'] : '';
		if ( '' === $plugin || '' === $version ) {
			return Assessment::prohibited( 'no_rollback', 'This receipt does not record which plugin was installed.' );
		}
		$refusal = Site::preflight( 'delete', true );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$data = Site::plugin( $plugin );
		if ( null === $data ) {
			return Assessment::prohibited( 'already_restored', sprintf( 'Plugin %s is no longer installed; nothing to roll back.', $plugin ) );
		}
		if ( Site::is_active( $plugin ) ) {
			return Assessment::prohibited( 'active', sprintf( 'Plugin %s is active now; FlitAgent only removes an install that is still inactive. Deactivate it first (deactivate_plugin), or use delete_plugin.', $plugin ) );
		}
		$current = Site::text( $data, 'Version' );
		if ( $current !== $version ) {
			return Assessment::prohibited( 'changed', sprintf( 'Plugin %s is now version %s, not the %s that was installed; FlitAgent does not delete it as a rollback. Use delete_plugin.', $plugin, $current, $version ) );
		}

		return Assessment::destructive(
			sprintf( 'Delete plugin %s (%s %s) again. Its uninstall routine runs and its files are removed.', Site::name( $plugin, $data ), $plugin, $version ),
			array(
				'plugin'   => $plugin,
				'current'  => array(
					'installed' => 'yes',
					'version'   => $version,
				),
				'restored' => array(
					'installed' => 'no',
					'version'   => 'none',
				),
			),
			array( 'fingerprint' => Site::fingerprint( $plugin ) )
		);
	}

	/**
	 * Deletes the plugin again.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the plugin changed since the assessment, or core failed.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$plugin = (string) $rollback['target']['id'];
		if ( ! isset( $expected_state['fingerprint'] ) || Site::fingerprint( $plugin ) !== $expected_state['fingerprint'] ) {
			throw new ToolError( sprintf( 'Plugin %s changed after the rollback was assessed; nothing was changed.', esc_textarea( $plugin ) ) );
		}
		$this->upgrader->delete( $plugin );
		$outcome = new Outcome(
			array(
				'plugin'  => $plugin,
				'deleted' => true,
			),
			Rollback::none()
		);
		return null === Site::plugin( $plugin ) ? $outcome : $outcome->with_verification_failure( 'WordPress still lists the plugin after deleting it.' );
	}
}
