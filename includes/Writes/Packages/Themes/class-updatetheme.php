<?php
/**
 * Write tool: update_theme.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Themes;

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
 * `update_theme`: updates an installed WordPress.org theme to the directory's latest version. The exact previewed
 * package is installed over the old copy with core's Theme_Upgrader (`overwrite_package`), not whatever the update
 * transient holds, so the approved version is the installed one; the active theme (or its parent) is swapped in
 * maintenance mode and the home page is checked after. Rollback reinstalls the previous version's package from
 * WordPress.org; what the update changed in the database (theme mods, options, migrations) is not undone.
 */
final class UpdateTheme implements WriteTool, Reversible {

	/**
	 * What a rollback cannot undo.
	 */
	const DATA_NOTE = 'Rolling back restores the previous version\'s files; theme mods, options or other data the new version changed are not undone.';

	/**
	 * Upgrader.
	 *
	 * @var Upgrader
	 */
	private $upgrader;

	/**
	 * Constructor.
	 *
	 * @param Upgrader|null $upgrader Upgrader, for tests.
	 */
	public function __construct( ?Upgrader $upgrader = null ) {
		$this->upgrader = $upgrader ? $upgrader : new Upgrader();
	}

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'update_theme';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: update a theme from WordPress.org',
			'Updates an installed theme to the latest version in the WordPress.org theme directory, by slug (its directory name). As in wp-admin, WordPress then also installs available translation updates and checks WordPress.org for updates. Always needs an administrator\'s approval (returns approval_required) with a preview of current and new version, compatibility, whether it is the active theme, theme page and backup status. Refused when the theme is not installed, not from WordPress.org, already up to date, needs a newer WordPress or PHP, or the site does not allow file changes. Updating the active theme puts the site in maintenance mode for the swap and checks the home page after (warning home_page_error). rollback_change reinstalls the previous version; data the update changed is not undone.',
			array( 'slug' => InstallTheme::slug_property() ),
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
	 * Assesses the update. Read-only (one WordPress.org lookup).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$slug    = (string) $args['slug'];
		$refusal = Site::preflight( 'update_themes', 'flitagent_update_theme' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$theme = Site::theme( $slug );
		if ( null === $theme ) {
			return Assessment::prohibited( 'not_installed', sprintf( 'Theme %s is not installed. Use install_theme to install it.', $slug ) );
		}
		$update_uri = trim( (string) $theme->get( 'UpdateURI' ) );
		if ( '' !== $update_uri && ! Directory::is_wporg_uri( $update_uri ) ) {
			return Assessment::prohibited( 'not_wporg', sprintf( 'Theme %s declares updates from %s (Update URI), not WordPress.org, so it is not updated here.', $slug, Site::clean( $update_uri ) ) );
		}
		$info = Directory::checked( $slug, 'not_wporg' );
		if ( $info instanceof Assessment ) {
			return $info;
		}
		$installed_name = wp_strip_all_tags( (string) $theme->get( 'Name' ) );
		if ( 0 !== strcasecmp( $installed_name, $info['name'] ) ) {
			return Assessment::prohibited( 'not_wporg', sprintf( 'The installed theme %s is named "%s", but WordPress.org has "%s" under that slug; it may not come from WordPress.org, so it is not replaced.', $slug, $installed_name, $info['name'] ) );
		}
		$current = (string) $theme->get( 'Version' );
		if ( version_compare( $info['version'], $current, '<=' ) ) {
			return Assessment::prohibited( 'up_to_date', sprintf( 'Theme %s is at version %s; WordPress.org has %s, so there is no update.', $slug, $current, $info['version'] ) );
		}

		$use    = Site::use_of( $slug );
		$labels = array(
			''       => 'no',
			'active' => 'yes',
			'parent' => 'yes (parent of the active theme)',
		);
		$note   = '' === $use ? '' : ' It is in use on the front end: the site is in maintenance mode for the swap and the home page is checked after.';
		return Assessment::destructive(
			sprintf( 'Update theme %s (%s) from %s to %s from WordPress.org.%s %s', $info['name'], $slug, $current, $info['version'], $note, self::DATA_NOTE ),
			array(
				'before'   => array( 'version' => $current ),
				'after'    => array( 'version' => $info['version'] ),
				'theme'    => InstallTheme::theme_preview( $info ) + array( 'active' => $labels[ $use ] ),
				'backup'   => Backups::status(),
				'rollback' => 'rollback_change reinstalls version ' . $current . ' from WordPress.org. ' . self::DATA_NOTE,
			),
			array(
				'fingerprint' => Site::fingerprint( $slug ),
				'version'     => $info['version'],
				'package'     => $info['package'],
				'in_use'      => '' !== $use,
			)
		);
	}

	/**
	 * Installs the new version over the old one.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When the installed version changed meanwhile or the upgrader fails.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$slug          = (string) $args['slug'];
		$preconditions = $assessment->preconditions();
		if ( Site::fingerprint( $slug ) !== $preconditions['fingerprint'] ) {
			throw new ToolError( sprintf( 'Theme %s changed while the update was being applied; nothing was changed.', esc_textarea( $slug ) ) );
		}
		$previous = Site::version( $slug );
		$this->upgrader->install( (string) $preconditions['package'], true, (bool) $preconditions['in_use'] );
		return new Outcome(
			array(
				'slug'             => $slug,
				'previous_version' => $previous,
				'version'          => Site::version( $slug ),
				'target_version'   => (string) $preconditions['version'],
				'active'           => (bool) $preconditions['in_use'],
			),
			Rollback::make( Rollback::SNAPSHOT, 'theme', $slug, array(), array( 'version' => $previous ) ),
			array( 'fingerprint' => Site::fingerprint( $slug ) )
		);
	}

	/**
	 * Reads back the version; checks the home page when the theme is in use.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$slug    = (string) $args['slug'];
		$result  = $outcome->result();
		$version = Site::version( $slug );
		if ( $version !== (string) $result['target_version'] ) {
			return $outcome->with_verification_failure( sprintf( 'Theme %s reports version %s after the update, not %s.', $slug, '' === $version ? '(not installed)' : $version, $result['target_version'] ) );
		}
		return ! empty( $result['active'] ) || '' !== Site::use_of( $slug ) ? Loopback::check( $outcome ) : $outcome;
	}

	/**
	 * Assesses reinstalling the previous version. Read-only (one HEAD request to WordPress.org).
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$slug     = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		$previous = isset( $rollback['before']['version'] ) ? (string) $rollback['before']['version'] : '';
		$package  = Directory::version_package( $slug, $previous );
		if ( '' === $slug || '' === $package ) {
			return Assessment::prohibited( 'no_rollback', 'This receipt does not record the previous version.' );
		}
		$refusal = Site::preflight( 'update_themes', 'flitagent_update_theme' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( null === Site::theme( $slug ) ) {
			return Assessment::prohibited( 'not_installed', sprintf( 'Theme %s is no longer installed, so the previous version is not reinstalled.', $slug ) );
		}
		$current = Site::version( $slug );
		if ( $current === $previous ) {
			return Assessment::prohibited( 'already_restored', sprintf( 'Theme %s is already at version %s; nothing to roll back.', $slug, $previous ) );
		}
		if ( ! Directory::package_exists( $package ) ) {
			return Assessment::prohibited( 'no_package', sprintf( 'WordPress.org no longer serves %s version %s (%s), so it cannot be reinstalled.', $slug, $previous, $package ) );
		}
		$use = Site::use_of( $slug );
		return Assessment::destructive(
			sprintf( 'Reinstall theme %s version %s from WordPress.org, replacing version %s. %s', $slug, $previous, $current, self::DATA_NOTE ),
			array(
				'current'  => array( 'version' => $current ),
				'restored' => array( 'version' => $previous ),
				'package'  => $package,
			),
			array(
				'fingerprint' => Site::fingerprint( $slug ),
				'package'     => $package,
				'in_use'      => '' !== $use,
			)
		);
	}

	/**
	 * Reinstalls the previous version.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the theme changed since the assessment or the upgrader fails.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$slug = (string) $rollback['target']['id'];
		if ( ! isset( $expected_state['fingerprint'], $expected_state['package'] ) || Site::fingerprint( $slug ) !== $expected_state['fingerprint'] ) {
			throw new ToolError( sprintf( 'Theme %s changed after the rollback was assessed; nothing was changed.', esc_textarea( $slug ) ) );
		}
		$previous = (string) $rollback['before']['version'];
		$this->upgrader->install( (string) $expected_state['package'], true, ! empty( $expected_state['in_use'] ) );
		$version = Site::version( $slug );
		$outcome = new Outcome(
			array(
				'slug'    => $slug,
				'version' => $version,
			),
			Rollback::none()
		);
		if ( $version !== $previous ) {
			return $outcome->with_verification_failure( sprintf( 'Theme %s reports version %s after the rollback, not %s.', $slug, $version, $previous ) );
		}
		$outcome = $outcome->with_warning( 'data_not_restored', self::DATA_NOTE );
		return ! empty( $expected_state['in_use'] ) || '' !== Site::use_of( $slug ) ? Loopback::check( $outcome ) : $outcome;
	}
}
