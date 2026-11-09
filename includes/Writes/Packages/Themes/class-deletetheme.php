<?php
/**
 * Write tool: delete_theme.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Themes;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `delete_theme`: deletes an installed, unused theme with core's delete_theme(). Destructive with no rollback (the
 * files are gone; a WordPress.org theme can be installed again with install_theme, a custom one cannot). Refuses the
 * active theme, the parent of the active theme and a theme other installed child themes depend on.
 */
final class DeleteTheme implements WriteTool {

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
		return 'delete_theme';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete a theme',
			'Deletes an installed theme\'s files by slug (its directory name). Always needs an administrator\'s approval (returns approval_required) and cannot be rolled back; the preview says whether the theme can be installed again from WordPress.org. Refused for the active theme, the parent of the active theme, a theme other installed child themes depend on, or when the site does not allow file changes.',
			array( 'slug' => InstallTheme::slug_property() ),
			array( 'slug' ),
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
	 * Assesses the delete. Read-only (one WordPress.org lookup, to say whether it can be reinstalled).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$slug    = (string) $args['slug'];
		$refusal = Site::preflight( 'delete_themes', 'flitagent_delete_theme' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$theme = Site::theme( $slug );
		if ( null === $theme ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Theme %s is not installed.', $slug ) );
		}
		$in_use = Site::in_use( $slug, 'deleted' );
		if ( null !== $in_use ) {
			return $in_use;
		}

		$name    = wp_strip_all_tags( (string) $theme->get( 'Name' ) );
		$version = (string) $theme->get( 'Version' );
		$wporg   = self::on_wporg( $slug, $name, (string) $theme->get( 'UpdateURI' ) );
		$again   = $wporg ? 'install_theme can install it from WordPress.org again (the latest version, not necessarily this one).' : 'It is not on WordPress.org, so FlitAgent cannot install it again.';
		return Assessment::destructive(
			sprintf( 'Delete theme %s %s (%s) and its files. This cannot be rolled back. %s Its customizer settings stay in the database.', $name, $version, $slug, $again ),
			array(
				'before'   => array(
					'installed' => 'yes',
					'version'   => $version,
				),
				'after'    => array(
					'installed' => 'no',
					'version'   => '',
				),
				'theme'    => array(
					'name'             => $name,
					'slug'             => $slug,
					'version'          => $version,
					'on_wordpress_org' => $wporg ? 'yes' : 'no',
					'page'             => $wporg ? Directory::page( $slug ) : '',
				),
				'backup'   => Backups::status(),
				'rollback' => 'This cannot be rolled back: rollback_change is not available for a deleted theme. ' . $again,
			),
			array(
				'fingerprint' => Site::fingerprint( $slug ),
				'in_use'      => false,
			)
		);
	}

	/**
	 * Deletes the theme.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When the theme changed meanwhile or cannot be deleted.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$slug = (string) $args['slug'];
		if ( Site::fingerprint( $slug ) !== $assessment->preconditions()['fingerprint'] || null !== Site::in_use( $slug, 'deleted' ) ) {
			throw new ToolError( sprintf( 'Theme %s changed while the delete was being applied; nothing was changed.', esc_textarea( $slug ) ) );
		}
		$version = Site::version( $slug );
		$this->upgrader->delete( $slug );
		return new Outcome(
			array(
				'slug'    => $slug,
				'deleted' => true,
				'version' => $version,
			),
			Rollback::none(),
			array( 'fingerprint' => Site::fingerprint( $slug ) )
		);
	}

	/**
	 * Confirms the theme is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$slug = (string) $args['slug'];
		return null === Site::theme( $slug ) ? $outcome : $outcome->with_verification_failure( sprintf( 'Theme %s is still installed after the delete.', $slug ) );
	}

	/**
	 * Whether the installed theme is the WordPress.org theme of that slug (same name, no foreign Update URI).
	 *
	 * @param string $slug       Slug.
	 * @param string $name       Installed name.
	 * @param string $update_uri Update URI header.
	 * @return bool
	 */
	private static function on_wporg( string $slug, string $name, string $update_uri ): bool {
		if ( ! Directory::is_wporg_uri( $update_uri ) ) {
			return false;
		}
		try {
			$info = Directory::info( $slug );
		} catch ( DirectoryError $e ) {
			return false;
		}
		return 0 === strcasecmp( $info['name'], $name );
	}
}
