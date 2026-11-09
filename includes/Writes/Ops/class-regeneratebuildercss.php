<?php
/**
 * Write tool: regenerate_builder_css.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\System\CacheEngines;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * `regenerate_builder_css`: clears the generated CSS of every active page builder that has an API for it (Elementor,
 * Beaver Builder, Divi), so it is regenerated on the next page view.
 */
final class RegenerateBuilderCss extends EngineTool {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'regenerate_builder_css';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: regenerate page-builder CSS',
			'Clears the generated CSS files of each active page builder through its own API (Elementor "Regenerate CSS & Data", Beaver Builder asset cache, Divi static CSS), so they are rebuilt on the next page view; fixes stale styling after a builder change. Reports each builder as purged or failed. Bricks and Breakdance are not supported. Applied at once if an administrator enabled auto-approve for it; otherwise returns approval_required. Nothing to roll back: the CSS regenerates.',
			array(),
			array(),
			$this->tiers(),
			false
		);
	}

	/**
	 * Assesses the regeneration. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When no supported builder is active.
	 */
	public function assess( array $args ): Assessment {
		$refusal = self::refuse_without_manage_options();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$engines = $this->engines->detected( CacheEngines::BUILDER );
		if ( array() === $engines ) {
			throw new ToolError( 'No supported page builder is active (supported: Elementor, Beaver Builder, Divi).' );
		}
		return Assessment::reversible(
			sprintf( 'Clear and regenerate the generated CSS of: %s.', implode( ', ', array_map( array( CacheEngines::class, 'label' ), $engines ) ) ),
			array( 'engines' => $engines ),
			array( 'engines' => $engines ),
			true
		);
	}

	/**
	 * Clears each builder's CSS.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		return new Outcome(
			array( 'engines' => $this->run( $assessment->preconditions()['engines'], null ) ),
			self::regen( 'cache', 'builder_css' ),
			self::no_fingerprint()
		);
	}
}
