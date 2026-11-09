<?php
/**
 * Write tool: update_custom_css.
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
 * `update_custom_css`: replaces the active theme's Additional CSS through core's wp_update_custom_css_post(), which
 * keeps a revision of every save. Rollback puts the previous CSS back, or deletes the CSS post this write created and
 * restores the `custom_css_post_id` theme mod exactly. Never auto-approvable, because the CSS is public.
 */
final class UpdateCustomCss implements WriteTool, Reversible {

	/**
	 * Theme mod core uses to cache the CSS post id (-1 means none).
	 */
	const POINTER = 'custom_css_post_id';

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'update_custom_css';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: update the custom CSS',
			'Replaces the active theme\'s Additional CSS (Customizer / Site Editor custom CSS) with the given CSS. Markup such as </style> is refused. WordPress keeps a revision of every save. Always returns approval_required; undo with rollback_change.',
			array(
				'css' => array(
					'type'        => 'string',
					'maxLength'   => 500000,
					'description' => 'The complete new CSS (replaces the current CSS; "" clears it).',
				),
			),
			array( 'css' ),
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
	 * Never auto-approvable: the CSS is public.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the change. Read-only (unlike wp_get_custom_css_post(), it never caches the post id).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the CSS contains markup or would not change anything.
	 */
	public function assess( array $args ): Assessment {
		$css = self::css( $args );
		if ( ! current_user_can( 'edit_css' ) ) {
			return Assessment::prohibited( 'forbidden', 'Changing custom CSS needs the edit_css capability.' );
		}

		$state = self::read();
		if ( $state['css'] === $css ) {
			throw new ToolError( 'The custom CSS is already exactly that; nothing to change.' );
		}

		return Assessment::reversible(
			sprintf( 'Replace the custom CSS of theme %s (%d characters now, %d after).', $state['theme'], self::length( $state['css'] ), self::length( $css ) ),
			array(
				'theme'  => $state['theme'],
				'before' => $state['css'],
				'after'  => $css,
			),
			array( 'fingerprint' => self::fingerprint( $state ) ),
			false
		);
	}

	/**
	 * Saves the CSS.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the CSS changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$css    = self::css( $args );
		$before = self::unchanged( $assessment->preconditions(), 'changed' );
		$mods   = get_theme_mods();
		$exists = is_array( $mods ) && array_key_exists( self::POINTER, $mods );

		$post = wp_update_custom_css_post( $css );
		if ( ! $post || is_wp_error( $post ) ) {
			throw new ToolError( 'WordPress could not save the custom CSS.' );
		}

		$created = 0 === $before['post_id'];
		return new Outcome(
			array(
				'theme'   => $before['theme'],
				'post_id' => (int) $post->ID,
				'created' => $created,
			),
			Rollback::make(
				Rollback::SNAPSHOT,
				'custom_css',
				(int) $post->ID,
				array(
					'theme'   => $before['theme'],
					'created' => $created,
					'pointer' => array(
						'exists' => $exists,
						'value'  => $exists ? $mods[ self::POINTER ] : null,
					),
				),
				array( 'css' => $before['css'] )
			),
			array( 'fingerprint' => self::fingerprint( self::read() ) )
		);
	}

	/**
	 * Reads the CSS back; a filter on `update_custom_css_data` may store it differently.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$state = self::read();
		if ( 0 === $state['post_id'] ) {
			return $outcome->with_verification_failure( 'The custom CSS could not be read back after the update.' );
		}
		if ( self::css( $args ) === $state['css'] ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result( array( 'stored_verbatim' => false ) )
			->with_warning( 'stored_differently', 'WordPress stored the custom CSS differently from the request (a filter changed it).' );
	}

	/**
	 * Assesses restoring the previous CSS. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$refusal = self::rollback_refusal( $rollback );
		if ( null !== $refusal ) {
			return $refusal;
		}

		$current  = self::read();
		$restored = self::restored( $rollback );
		return Assessment::reversible(
			! empty( $rollback['refs']['created'] )
				? sprintf( 'Remove the custom CSS of theme %s that the write created (%d characters).', $current['theme'], self::length( $current['css'] ) )
				: sprintf( 'Restore the previous custom CSS of theme %s (%d characters now, %d after).', $current['theme'], self::length( $current['css'] ), self::length( $restored ) ),
			array(
				'theme'    => $current['theme'],
				'current'  => $current['css'],
				'restored' => $restored,
			),
			array( 'fingerprint' => self::fingerprint( $current ) ),
			false
		);
	}

	/**
	 * Restores the previous CSS, or deletes the post the write created and restores the theme mod.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the CSS changed or WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		if ( null !== self::rollback_refusal( $rollback ) ) {
			throw new ToolError( 'The custom CSS can no longer be restored; nothing was restored.' );
		}
		$current = self::unchanged( $expected_state, 'restored' );
		$post_id = (int) $rollback['target']['id'];

		if ( ! empty( $rollback['refs']['created'] ) ) {
			if ( $current['post_id'] !== $post_id || ! wp_delete_post( $post_id, true ) ) {
				throw new ToolError( 'WordPress could not delete the custom CSS post the write created.' );
			}
			$pointer = isset( $rollback['refs']['pointer'] ) && is_array( $rollback['refs']['pointer'] ) ? $rollback['refs']['pointer'] : array();
			if ( ! empty( $pointer['exists'] ) ) {
				set_theme_mod( self::POINTER, $pointer['value'] );
			} else {
				remove_theme_mod( self::POINTER );
			}
		} else {
			$post = wp_update_custom_css_post( self::restored( $rollback ) );
			if ( ! $post || is_wp_error( $post ) ) {
				throw new ToolError( 'WordPress could not restore the custom CSS.' );
			}
		}

		$outcome = new Outcome(
			array(
				'theme'    => $current['theme'],
				'restored' => true,
			),
			Rollback::none()
		);
		return self::restored( $rollback ) === self::read()['css']
			? $outcome
			: $outcome->with_warning( 'restored_differently', 'WordPress stored the restored CSS differently.' );
	}

	/**
	 * The CSS argument, refusing markup like core's Customizer validation.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return string
	 * @throws ToolError When the CSS contains `</style`.
	 */
	private static function css( array $args ): string {
		$css = (string) $args['css'];
		if ( false !== stripos( $css, '</style' ) ) {
			throw new ToolError( 'Markup is not allowed in CSS (it contains </style).' );
		}
		return $css;
	}

	/**
	 * Refusal for a rollback: no `edit_css`, or another theme is active. Null when allowed.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment|null
	 */
	private static function rollback_refusal( array $rollback ): ?Assessment {
		if ( ! current_user_can( 'edit_css' ) ) {
			return Assessment::prohibited( 'forbidden', 'Changing custom CSS needs the edit_css capability.' );
		}
		$theme = isset( $rollback['refs']['theme'] ) ? (string) $rollback['refs']['theme'] : '';
		if ( get_stylesheet() !== $theme ) {
			return Assessment::prohibited( 'theme_changed', sprintf( 'Theme %s is no longer the active theme, so its custom CSS cannot be restored.', $theme ) );
		}
		return null;
	}

	/**
	 * CSS the rollback restores: '' when the write created the post.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return string
	 */
	private static function restored( array $rollback ): string {
		return ! empty( $rollback['refs']['created'] ) || ! isset( $rollback['before']['css'] ) ? '' : (string) $rollback['before']['css'];
	}

	/**
	 * The active theme's CSS post and content, found like wp_get_custom_css_post() but without caching the id.
	 *
	 * @return array{theme: string, post_id: int, css: string}
	 */
	private static function read(): array {
		$theme   = (string) get_stylesheet();
		$pointer = get_theme_mod( self::POINTER );
		$post    = null;
		if ( is_numeric( $pointer ) && (int) $pointer > 0 ) {
			$found = get_post( (int) $pointer );
			$post  = $found && 'custom_css' === $found->post_type ? $found : null;
		}
		if ( null === $post && ( ! is_numeric( $pointer ) || -1 !== (int) $pointer ) ) {
			$found = get_posts(
				array(
					'post_type'              => 'custom_css',
					'post_status'            => 'any',
					'name'                   => sanitize_title( $theme ),
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$post  = isset( $found[0] ) ? $found[0] : null;
		}
		return array(
			'theme'   => $theme,
			'post_id' => $post ? (int) $post->ID : 0,
			'css'     => $post ? (string) $post->post_content : '',
		);
	}

	/**
	 * Fingerprint of the CSS state.
	 *
	 * @param array{theme: string, post_id: int, css: string} $state State.
	 * @return string
	 */
	private static function fingerprint( array $state ): string {
		return Canonical::hash( array( $state['theme'], $state['post_id'], $state['css'] ) );
	}

	/**
	 * Throws unless the CSS is still as assessed.
	 *
	 * @param array<string, mixed> $expected Preconditions.
	 * @param string               $what     What would have been done.
	 * @return array{theme: string, post_id: int, css: string}
	 * @throws ToolError When it changed.
	 */
	private static function unchanged( array $expected, string $what ): array {
		$state = self::read();
		if ( ! isset( $expected['fingerprint'] ) || self::fingerprint( $state ) !== $expected['fingerprint'] ) {
			throw new ToolError( sprintf( 'The custom CSS changed after the request was assessed; nothing was %s.', esc_textarea( $what ) ) );
		}
		return $state;
	}

	/**
	 * Length in characters.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( string $text ): int {
		$count = preg_match_all( '/./su', $text );
		return false === $count ? strlen( $text ) : $count;
	}
}
