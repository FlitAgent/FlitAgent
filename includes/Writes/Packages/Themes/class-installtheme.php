<?php
/**
 * Write tool: install_theme.
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
 * `install_theme`: installs a theme from the WordPress.org directory by slug with core's Theme_Upgrader, without
 * activating it. A child theme whose parent is missing gets the parent too (core's upgrader fetches it, as in
 * wp-admin); the preview lists it. Destructive (new code on the server), so always approved by a human. Rollback
 * deletes the theme (and a parent it installed) again while it is inactive and still the installed version.
 */
final class InstallTheme implements WriteTool, Reversible {

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
		return 'install_theme';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: install a theme from WordPress.org',
			'Installs a theme from the WordPress.org theme directory by its slug (e.g. "twentytwentyone"; no other source, ZIP URL or upload). It is installed but not activated (use switch_theme). As in wp-admin, WordPress then also installs available translation updates and checks WordPress.org for updates. Always needs an administrator\'s approval (returns approval_required) with a preview of the version, compatibility, theme page, any parent theme that is installed with it, and backup status. Refused when the theme is already installed, not in the directory, needs a newer WordPress or PHP, or the site does not allow file changes. rollback_change deletes it again while it is inactive.',
			array( 'slug' => self::slug_property() ),
			array( 'slug' ),
			$this->tiers(),
			true
		);
	}

	/**
	 * The `slug` property shared by the theme package tools.
	 *
	 * @return array<string, mixed>
	 */
	public static function slug_property(): array {
		return array(
			'type'        => 'string',
			'minLength'   => 1,
			'maxLength'   => 200,
			'pattern'     => '^[a-z0-9-]+$',
			'description' => 'Theme slug: its WordPress.org slug, which is also its directory name, e.g. "twentytwentyone".',
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
	 * Assesses the install. Read-only (one WordPress.org lookup per theme).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$slug    = (string) $args['slug'];
		$refusal = Site::preflight( 'install_themes', 'flitagent_install_theme' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( null !== Site::theme( $slug ) ) {
			return Assessment::prohibited( 'already_installed', sprintf( 'Theme %s is already installed (version %s). Use update_theme to update it.', $slug, Site::version( $slug ) ) );
		}
		$info = Directory::checked( $slug, 'not_found' );
		if ( $info instanceof Assessment ) {
			return $info;
		}

		$preview = array(
			'before'   => array(
				'installed' => 'no',
				'version'   => '',
			),
			'after'    => array(
				'installed' => 'yes',
				'version'   => $info['version'],
			),
			'theme'    => self::theme_preview( $info ),
			'backup'   => Backups::status(),
			'rollback' => 'rollback_change deletes the theme again (and a parent theme installed with it) while it is not active and still this version. Nothing else on the site changes.',
		);
		$parent  = '';
		$extra   = '';
		if ( '' !== $info['parent'] ) {
			$installed = null !== Site::theme( $info['parent'] );
			if ( $installed ) {
				$preview['parent'] = array(
					'slug'    => $info['parent'],
					'version' => Site::version( $info['parent'] ),
					'status'  => 'installed',
				);
			} else {
				$parent_info = Directory::checked( $info['parent'], 'parent_not_found' );
				if ( $parent_info instanceof Assessment ) {
					return $parent_info;
				}
				$preview['parent'] = self::theme_preview( $parent_info ) + array( 'status' => 'will be installed too' );
				$parent            = $info['parent'];
				$extra             = sprintf( ' WordPress also installs its parent theme %s %s (%s).', $parent_info['name'], $parent_info['version'], $parent );
			}
		}

		return Assessment::destructive(
			sprintf( 'Install theme %s %s (%s) from WordPress.org. It will not be activated.%s rollback_change can delete it again while it is inactive.', $info['name'], $info['version'], $slug, $extra ),
			$preview,
			array(
				'fingerprint' => Site::fingerprint( $slug ),
				'version'     => $info['version'],
				'package'     => $info['package'],
				'parent'      => $parent,
			)
		);
	}

	/**
	 * Installs the package.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When the theme appeared meanwhile or the upgrader fails.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$slug          = (string) $args['slug'];
		$preconditions = $assessment->preconditions();
		if ( Site::fingerprint( $slug ) !== $preconditions['fingerprint'] ) {
			throw new ToolError( sprintf( 'Theme %s was installed while the change was being applied; nothing was changed.', esc_textarea( $slug ) ) );
		}
		$this->upgrader->install( (string) $preconditions['package'], false, false );

		$parent           = (string) $preconditions['parent'];
		$parent_installed = '' !== $parent && null !== Site::theme( $parent ) ? $parent : '';
		$theme            = Site::theme( $slug );
		return new Outcome(
			array(
				'slug'             => $slug,
				'name'             => null === $theme ? '' : wp_strip_all_tags( (string) $theme->get( 'Name' ) ),
				'version'          => Site::version( $slug ),
				'installed'        => null !== $theme,
				'active'           => false,
				'parent_installed' => '' === $parent_installed ? null : $parent_installed,
			),
			Rollback::make( Rollback::SNAPSHOT, 'theme', $slug, array( 'parent_installed' => $parent_installed ), array( 'installed' => false ) ),
			array( 'fingerprint' => Site::fingerprint( $slug ) )
		);
	}

	/**
	 * Reads back the installed version.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$slug   = (string) $args['slug'];
		$result = $outcome->result();
		if ( null === Site::theme( $slug ) ) {
			return $outcome->with_verification_failure( sprintf( 'Theme %s is not installed after the install.', $slug ) );
		}
		$version = Site::version( $slug );
		$wanted  = isset( $result['version'] ) ? (string) $result['version'] : '';
		return $version === $wanted ? $outcome : $outcome->with_verification_failure( sprintf( 'Theme %s reports version %s after the install.', $slug, $version ) );
	}

	/**
	 * Assesses deleting the installed theme again. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$slug = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		if ( '' === $slug ) {
			return Assessment::prohibited( 'no_rollback', 'This receipt does not record the theme.' );
		}
		$refusal = Site::preflight( 'delete_themes', 'flitagent_delete_theme' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( null === Site::theme( $slug ) ) {
			return Assessment::prohibited( 'already_restored', sprintf( 'Theme %s is no longer installed; nothing to roll back.', $slug ) );
		}
		$current = Site::fingerprint( $slug );
		$after   = isset( $rollback['after']['fingerprint'] ) ? (string) $rollback['after']['fingerprint'] : '';
		if ( $current !== $after ) {
			return Assessment::prohibited( 'changed', sprintf( 'Theme %s is now version %s, not the version this receipt installed, so it is not deleted.', $slug, Site::version( $slug ) ) );
		}
		$in_use = Site::in_use( $slug, 'deleted' );
		if ( null !== $in_use ) {
			return $in_use;
		}
		$parent = isset( $rollback['refs']['parent_installed'] ) ? (string) $rollback['refs']['parent_installed'] : '';
		$also   = '' !== $parent && null !== Site::theme( $parent ) ? sprintf( ' and its parent theme %s if nothing else uses it', $parent ) : '';
		return Assessment::destructive(
			sprintf( 'Delete theme %s %s again%s. Its files are removed.', $slug, Site::version( $slug ), $also ),
			array(
				'current'  => array(
					'installed' => 'yes',
					'version'   => Site::version( $slug ),
				),
				'restored' => array(
					'installed' => 'no',
					'version'   => '',
				),
			),
			array( 'fingerprint' => $current )
		);
	}

	/**
	 * Deletes the theme, then the parent it brought if nothing else uses it.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the theme changed since the assessment or cannot be deleted.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$slug = (string) $rollback['target']['id'];
		if ( ! isset( $expected_state['fingerprint'] ) || Site::fingerprint( $slug ) !== $expected_state['fingerprint'] || null !== Site::in_use( $slug, 'deleted' ) ) {
			throw new ToolError( sprintf( 'Theme %s changed after the rollback was assessed; nothing was changed.', esc_textarea( $slug ) ) );
		}
		$this->upgrader->delete( $slug );
		$deleted = array( $slug );
		$parent  = isset( $rollback['refs']['parent_installed'] ) ? (string) $rollback['refs']['parent_installed'] : '';
		$kept    = '';
		if ( '' !== $parent && null !== Site::theme( $parent ) ) {
			$in_use = Site::in_use( $parent, 'deleted' );
			if ( null === $in_use ) {
				$this->upgrader->delete( $parent );
				$deleted[] = $parent;
			} else {
				$kept = (string) $in_use->summary();
			}
		}
		$outcome = new Outcome( array( 'deleted' => $deleted ), Rollback::none() );
		foreach ( $deleted as $theme ) {
			if ( null !== Site::theme( $theme ) ) {
				return $outcome->with_verification_failure( sprintf( 'Theme %s is still installed after the rollback.', $theme ) );
			}
		}
		return '' === $kept ? $outcome : $outcome->with_warning( 'parent_kept', sprintf( 'The parent theme %s installed with it was kept: %s', $parent, $kept ) );
	}

	/**
	 * Preview of a directory entry.
	 *
	 * @param array<string, string> $info Directory entry.
	 * @return array<string, string>
	 */
	public static function theme_preview( array $info ): array {
		return array(
			'name'         => $info['name'],
			'slug'         => $info['slug'],
			'version'      => $info['version'],
			'requires_wp'  => $info['requires'],
			'requires_php' => $info['requires_php'],
			'compatible'   => 'yes',
			'page'         => $info['page'],
			'package'      => $info['package'],
		);
	}
}
