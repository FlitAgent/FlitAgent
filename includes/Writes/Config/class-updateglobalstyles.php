<?php
/**
 * Write tool: update_global_styles.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `update_global_styles`: replaces the `styles` and/or `settings` section of the active theme's user global styles
 * (Site Editor > Styles) through the core global styles controller, after saving a revision of the current styles.
 * Rollback restores that revision. Never auto-approvable: styles are public.
 */
final class UpdateGlobalStyles implements WriteTool, Reversible {

	/**
	 * Sections the tool can replace.
	 */
	const SECTIONS = array( 'styles', 'settings' );

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'update_global_styles';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: update the global styles',
			'Replaces the styles and/or settings section of the active theme\'s custom global styles (Site Editor > Styles), as theme.json-shaped objects; each given section replaces the whole current section. WordPress drops values theme.json does not allow (reported as stored_verbatim: false). Always returns approval_required; undo with rollback_change.',
			array(
				'styles'   => array(
					'type'        => 'object',
					'description' => 'The complete new styles section, e.g. {"color": {"background": "#fff"}}.',
				),
				'settings' => array(
					'type'        => 'object',
					'description' => 'The complete new settings section.',
				),
			),
			array(),
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
	 * Never auto-approvable: styles are public.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the change. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When no section is given or nothing would change.
	 */
	public function assess( array $args ): Assessment {
		$changes = self::changes( $args );
		if ( array() === $changes ) {
			throw new ToolError( 'Give styles and/or settings.' );
		}
		$post = self::post();
		if ( $post instanceof Assessment ) {
			return $post;
		}
		$current = self::sections( (int) $post->ID );
		if ( null === $current ) {
			return Assessment::prohibited( 'not_found', 'The global styles could not be read; you may not be allowed to edit them.' );
		}
		$before = array();
		$after  = array();
		foreach ( $changes as $section => $value ) {
			$before[ $section ] = Canonical::json( $current[ $section ] );
			$after[ $section ]  = Canonical::json( $value );
		}
		if ( $before === $after ) {
			throw new ToolError( 'The global styles already have those values; nothing to change.' );
		}
		return Assessment::reversible(
			sprintf( 'Replace the %s of the global styles of theme %s.', implode( ' and ', array_keys( $changes ) ), get_stylesheet() ),
			array(
				'before' => $before,
				'after'  => $after,
			),
			array( 'fingerprint' => Revisions::fingerprint( $post ) ),
			false
		);
	}

	/**
	 * Saves a revision of the current styles, then writes through the global styles controller.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the styles changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$post = self::post();
		if ( $post instanceof Assessment || Revisions::fingerprint( $post ) !== ( $assessment->preconditions()['fingerprint'] ?? '' ) ) {
			throw new ToolError( 'The global styles changed after the request was assessed; nothing was changed.' );
		}
		$id       = (int) $post->ID;
		$revision = Revisions::save_current( $id );
		Rest::write( 'POST', '/wp/v2/global-styles/' . $id, self::changes( $args ) );

		return new Outcome(
			array(
				'post_id'            => $id,
				'before_revision_id' => $revision,
			),
			Rollback::make( Rollback::REVISION, 'global_styles', $id, array( 'revision_id' => $revision ) ),
			array( 'fingerprint' => Revisions::fingerprint( get_post( $id ) ) )
		);
	}

	/**
	 * Reads the sections back; theme.json sanitization drops what it does not allow.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$current = self::sections( (int) $outcome->result()['post_id'] );
		if ( null === $current ) {
			return $outcome->with_verification_failure( 'The global styles could not be read back.' );
		}
		$differs = array();
		foreach ( self::changes( $args ) as $section => $value ) {
			if ( Canonical::json( $current[ $section ] ) !== Canonical::json( $value ) ) {
				$differs[] = $section;
			}
		}
		if ( array() === $differs ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result(
			array(
				'stored_verbatim' => false,
				'differs'         => $differs,
			)
		)->with_warning( 'stored_differently', sprintf( 'WordPress stored the %s differently (theme.json sanitization removed values it does not allow).', implode( ' and ', $differs ) ) );
	}

	/**
	 * Assesses restoring the revision saved before the write. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$id      = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$refusal = Menus::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$post = get_post( $id );
		if ( ! $post || 'wp_global_styles' !== $post->post_type ) {
			return Assessment::prohibited( 'not_found', sprintf( 'The global styles post %d no longer exists.', $id ) );
		}
		$revision = Revisions::revision( isset( $rollback['refs']['revision_id'] ) ? (int) $rollback['refs']['revision_id'] : 0, $id );
		if ( null === $revision ) {
			return Assessment::prohibited( 'revision_missing', 'The revision of the global styles that this rollback would restore no longer exists.' );
		}
		return Assessment::reversible(
			sprintf( 'Restore the global styles from revision %d.', $revision->ID ),
			array(
				'current'  => (string) $post->post_content,
				'restored' => (string) $revision->post_content,
			),
			array( 'fingerprint' => Revisions::fingerprint( $post ) ),
			false
		);
	}

	/**
	 * Restores the revision, if the styles are still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the styles changed or WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$id = (int) $rollback['target']['id'];
		if ( Revisions::fingerprint( get_post( $id ) ) !== ( $expected_state['fingerprint'] ?? '' ) ) {
			throw new ToolError( 'The global styles changed after the rollback was assessed; nothing was restored.' );
		}
		Revisions::restore( (int) $rollback['refs']['revision_id'] );
		return new Outcome(
			array(
				'post_id'              => $id,
				'restored_revision_id' => (int) $rollback['refs']['revision_id'],
			),
			Rollback::none()
		);
	}

	/**
	 * The active theme's user global styles post, or a refusal.
	 *
	 * @return object|Assessment
	 */
	private static function post() {
		$refusal = Menus::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$theme = (string) get_stylesheet();
		$posts = get_posts(
			array(
				'post_type'      => 'wp_global_styles',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'desc',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the lookup core uses (WP_Theme_JSON_Resolver).
				'tax_query'      => array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $theme,
					),
				),
			)
		);
		if ( ! isset( $posts[0] ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Theme %s has no custom global styles yet; open Styles in the Site Editor and save once to create them.', $theme ) );
		}
		$refusal = Revisions::refusal( $posts[0], 'the global styles' );
		return null === $refusal ? $posts[0] : $refusal;
	}

	/**
	 * Current styles and settings sections, or null when the controller refused.
	 *
	 * @param int $id Post id.
	 * @return array{styles: mixed, settings: mixed}|null
	 */
	private static function sections( int $id ): ?array {
		$data = Rest::read( '/wp/v2/global-styles/' . $id );
		if ( null === $data ) {
			return null;
		}
		return array(
			'styles'   => isset( $data['styles'] ) ? $data['styles'] : array(),
			'settings' => isset( $data['settings'] ) ? $data['settings'] : array(),
		);
	}

	/**
	 * Given sections in SECTIONS order.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private static function changes( array $args ): array {
		$changes = array();
		foreach ( self::SECTIONS as $section ) {
			if ( array_key_exists( $section, $args ) ) {
				$changes[ $section ] = $args[ $section ];
			}
		}
		return $changes;
	}
}
