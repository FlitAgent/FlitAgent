<?php
/**
 * Write tool: update_plugin.
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
 * `update_plugin`: updates one installed WordPress.org plugin to the latest release through core's
 * `Plugin_Upgrader::bulk_upgrade()`, keeping its active state. Always destructive. After updating an active plugin it
 * checks the home page. Rollback reinstalls the previous version from WordPress.org; what the update changed in the
 * database stays.
 */
final class UpdatePlugin implements WriteTool, Reversible {

	/**
	 * Hosts an `Update URI` header may name for a plugin updated from WordPress.org.
	 */
	const WPORG_HOSTS = array( 'wordpress.org', 'w.org' );

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
		return 'update_plugin';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: update a plugin from WordPress.org',
			'Updates one installed plugin to its latest WordPress.org release, by its file as list_plugins shows it (e.g. "akismet/akismet.php"). An active plugin stays active (the site is in maintenance mode for the seconds its files are replaced) and the home page is checked afterwards. Always needs an administrator\'s approval (returns approval_required with current and new version, requirements, changelog link and backup status). Refused for FlitAgent itself, plugins not from WordPress.org, when no newer version exists, when the release needs a newer WordPress or PHP, when this site disallows file changes or needs FTP credentials, and on multisite. rollback_change reinstalls the previous version; database changes the update made are not undone.',
			array(
				'plugin' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 255,
					'description' => 'Plugin file relative to the plugins directory, e.g. "akismet/akismet.php".',
				),
			),
			array( 'plugin' ),
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
	 * Assesses the update. Read-only (it asks WordPress.org for the latest release).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$plugin  = (string) $args['plugin'];
		$refusal = Site::preflight( 'update', false );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$data = Site::plugin( $plugin );
		if ( null === $data || ! current_user_can( 'update_plugins' ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Plugin %s is not installed or you cannot update it.', $plugin ) );
		}
		if ( Site::is_self( $plugin ) ) {
			return Assessment::prohibited( 'self', 'FlitAgent does not install, update or delete itself; do that in wp-admin.' );
		}
		$name    = Site::name( $plugin, $data );
		$slug    = Site::slug_of( $plugin );
		$foreign = self::foreign_update_host( $data );
		if ( '' === $slug ) {
			return Assessment::prohibited( 'not_wordpress_org', sprintf( 'Plugin %s is a single file in the plugins folder, so FlitAgent cannot match it to a WordPress.org plugin; update it in wp-admin.', $plugin ) );
		}
		if ( '' !== $foreign ) {
			return Assessment::prohibited( 'not_wordpress_org', sprintf( 'Plugin %s gets its updates from %s (its Update URI header), not WordPress.org; FlitAgent only updates from WordPress.org.', $plugin, $foreign ) );
		}
		$release = Directory::latest( $slug );
		if ( $release instanceof Assessment ) {
			return 'not_found' === $release->refusal()['code'] ? Assessment::prohibited( 'not_wordpress_org', $release->refusal()['message'] ) : $release;
		}
		$current = Site::text( $data, 'Version' );
		if ( version_compare( $release['version'], $current, '<=' ) ) {
			return Assessment::prohibited( 'up_to_date', sprintf( 'Plugin %s is at %s; the latest version on WordPress.org is %s, so there is nothing to update.', $plugin, $current, $release['version'] ) );
		}
		$incompatible = Directory::incompatibility( $name, $release['version'], $release['requires'], $release['requires_php'] );
		if ( '' !== $incompatible ) {
			return Assessment::prohibited( 'incompatible', $incompatible . ' FlitAgent does not update to it.' );
		}

		$active  = Site::is_active( $plugin );
		$preview = array(
			'plugin'      => $plugin,
			'name'        => $name,
			'slug'        => $slug,
			'before'      => array(
				'version' => $current,
				'active'  => $active ? 'yes' : 'no',
			),
			'after'       => array(
				'version' => $release['version'],
				'active'  => $active ? 'yes' : 'no',
			),
			'requires'    => Directory::requirements( $release['requires'], $release['requires_php'] ),
			'links'       => Directory::links( $slug ),
			'backup'      => Backups::status(),
			'cannot_undo' => sprintf( 'rollback_change can reinstall %s, but changes the update makes to the database or options (for example data migrations) are not undone, and the old version may not work with them.', $current ),
		);
		if ( 0 !== strcasecmp( $release['name'], $name ) ) {
			$preview['wordpress_org_name'] = $release['name'];
		}

		return Assessment::destructive(
			sprintf(
				'Update plugin %s (%s) from %s to %s from WordPress.org.%s Rolling back reinstalls %s, but database changes made by the update stay.',
				$name,
				$plugin,
				$current,
				$release['version'],
				$active ? ' It is active: the site is in maintenance mode while its files are replaced, and its new code runs on the next request.' : '',
				$current
			),
			$preview,
			array(
				'plugin'  => $plugin,
				'slug'    => $slug,
				'from'    => $current,
				'to'      => $release['version'],
				'package' => $release['package'],
				'active'  => $active,
			)
		);
	}

	/**
	 * Replaces the files.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When the plugin changed meanwhile or core failed.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$expected = $assessment->preconditions();
		$plugin   = (string) $expected['plugin'];
		if ( Site::version( $plugin ) !== $expected['from'] || Site::is_active( $plugin ) !== $expected['active'] ) {
			throw new ToolError( sprintf( 'Plugin %s changed while the change was being applied; nothing was changed.', esc_textarea( $plugin ) ) );
		}

		$warnings = $this->upgrader->replace( $plugin, (string) $expected['package'] );

		$outcome = new Outcome(
			array(
				'plugin'  => $plugin,
				'from'    => (string) $expected['from'],
				'to'      => (string) $expected['to'],
				'version' => Site::version( $plugin ),
				'active'  => Site::is_active( $plugin ),
			),
			Rollback::make(
				Rollback::SNAPSHOT,
				'plugin',
				$plugin,
				array( 'slug' => (string) $expected['slug'] ),
				array(
					'version' => (string) $expected['from'],
					'active'  => (bool) $expected['active'],
				)
			),
			array( 'fingerprint' => Site::fingerprint( $plugin ) )
		);
		foreach ( $warnings as $code => $message ) {
			$outcome = $outcome->with_warning( $code, $message );
		}
		return $outcome;
	}

	/**
	 * Reads back the version and active state, and checks the home page after updating an active plugin.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$result = $outcome->result();
		$plugin = (string) $result['plugin'];
		return self::checked( $outcome, $plugin, (string) $result['to'], (bool) ( $result['active'] ?? false ), 'Updating ' . $plugin, 'rollback_change can reinstall the previous version.' );
	}

	/**
	 * Assesses reinstalling the previous version. Read-only (one HEAD request to WordPress.org).
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$plugin   = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		$slug     = isset( $rollback['refs']['slug'] ) ? (string) $rollback['refs']['slug'] : '';
		$previous = isset( $rollback['before']['version'] ) ? (string) $rollback['before']['version'] : '';
		if ( '' === $plugin || '' === $slug || '' === $previous ) {
			return Assessment::prohibited( 'no_rollback', 'This receipt does not record the previous version.' );
		}
		$refusal = Site::preflight( 'update', false );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$data = Site::plugin( $plugin );
		if ( null === $data || ! current_user_can( 'update_plugins' ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Plugin %s is not installed or you cannot update it, so its previous version cannot be reinstalled.', $plugin ) );
		}
		$current = Site::text( $data, 'Version' );
		if ( $current === $previous ) {
			return Assessment::prohibited( 'already_restored', sprintf( 'Plugin %s is already at %s; nothing to roll back.', $plugin, $previous ) );
		}
		$package = Directory::version_package( $slug, $previous );
		if ( '' === $package || ! Directory::exists( $package ) ) {
			return Assessment::prohibited( 'unavailable', sprintf( 'WordPress.org no longer offers %s version %s for download, so FlitAgent cannot reinstall it.', $slug, $previous ) );
		}
		$active = Site::is_active( $plugin );
		$name   = Site::name( $plugin, $data );

		return Assessment::destructive(
			sprintf( 'Reinstall %s %s over %s from WordPress.org, keeping it %s. Changes the update to %s made in the database or options are not undone, and %s may not work with them.', $name, $previous, $current, $active ? 'active' : 'inactive', $current, $previous ),
			array(
				'plugin'   => $plugin,
				'package'  => $package,
				'current'  => array(
					'version' => $current,
					'active'  => $active ? 'yes' : 'no',
				),
				'restored' => array(
					'version' => $previous,
					'active'  => $active ? 'yes' : 'no',
				),
			),
			array(
				'fingerprint' => Site::fingerprint( $plugin ),
				'package'     => $package,
			)
		);
	}

	/**
	 * Reinstalls the previous version.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the plugin changed since the assessment, or core failed.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$plugin   = (string) $rollback['target']['id'];
		$previous = (string) $rollback['before']['version'];
		if ( ! isset( $expected_state['fingerprint'], $expected_state['package'] ) || Site::fingerprint( $plugin ) !== $expected_state['fingerprint'] ) {
			throw new ToolError( sprintf( 'Plugin %s changed after the rollback was assessed; nothing was changed.', esc_textarea( $plugin ) ) );
		}
		$active   = Site::is_active( $plugin );
		$warnings = $this->upgrader->replace( $plugin, (string) $expected_state['package'] );

		$outcome = new Outcome(
			array(
				'plugin'  => $plugin,
				'version' => Site::version( $plugin ),
				'active'  => Site::is_active( $plugin ),
			),
			Rollback::none()
		);
		foreach ( $warnings as $code => $message ) {
			$outcome = $outcome->with_warning( $code, $message );
		}
		return self::checked( $outcome, $plugin, $previous, $active, 'Reinstalling ' . $plugin . ' ' . $previous, '' );
	}

	/**
	 * Host named by a plugin's Update URI header when it is not WordPress.org, or ''.
	 *
	 * @param array<string, mixed> $data Header data.
	 * @return string
	 */
	private static function foreign_update_host( array $data ): string {
		$uri = Site::text( $data, 'UpdateURI' );
		if ( '' === $uri ) {
			return '';
		}
		$host = strtolower( (string) wp_parse_url( false === strpos( $uri, '://' ) ? 'https://' . $uri : $uri, PHP_URL_HOST ) );
		return in_array( $host, self::WPORG_HOSTS, true ) ? '' : ( '' === $host ? $uri : $host );
	}

	/**
	 * Read-back: version and active state, then the home page for an active plugin.
	 *
	 * @param Outcome $outcome Outcome.
	 * @param string  $plugin  Plugin file.
	 * @param string  $version Expected version.
	 * @param bool    $active  Expected active state.
	 * @param string  $what    What changed, for warnings.
	 * @param string  $hint    Hint for a broken home page.
	 * @return Outcome
	 */
	private static function checked( Outcome $outcome, string $plugin, string $version, bool $active, string $what, string $hint ): Outcome {
		$current = Site::version( $plugin );
		if ( $current !== $version ) {
			return $outcome->with_verification_failure( sprintf( 'WordPress reports %s at version %s, not %s.', $plugin, '' === $current ? 'no' : $current, $version ) );
		}
		if ( Site::is_active( $plugin ) !== $active ) {
			return $outcome->with_verification_failure( sprintf( 'Plugin %s was %s before and is %s now.', $plugin, $active ? 'active' : 'inactive', $active ? 'inactive' : 'active' ) );
		}
		if ( $active ) {
			foreach ( Loopback::check( $what, $hint ) as $code => $message ) {
				$outcome = $outcome->with_warning( $code, $message );
			}
		}
		return $outcome;
	}
}
