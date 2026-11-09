<?php
/**
 * Write tool: flush_rewrite_rules.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * `flush_rewrite_rules`: a soft flush (`flush_rewrite_rules( false )`), which rebuilds the `rewrite_rules` option and
 * never writes `.htaccess` or `web.config`. A hard flush is not offered (it writes server files).
 */
final class FlushRewriteRules extends RegenTool {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'flush_rewrite_rules';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: flush rewrite rules',
			'Rebuilds the rewrite rules (soft flush, like saving Settings > Permalinks without changes), which fixes 404s after a post type or rewrite change. Never writes .htaccess or web.config. Applied at once if an administrator enabled auto-approve for it; otherwise returns approval_required. Nothing to roll back: the rules are regenerated from the registered rewrites.',
			array(),
			array(),
			$this->tiers(),
			false
		);
	}

	/**
	 * Assesses the flush. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$refusal = self::refuse_without_manage_options();
		if ( null !== $refusal ) {
			return $refusal;
		}
		return Assessment::reversible( 'Rebuild the rewrite rules (soft flush; server files are not touched).', array( 'hard' => false ), array(), true );
	}

	/**
	 * Flushes softly.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- this tool is an explicit, gated soft flush.
		flush_rewrite_rules( false );
		return new Outcome(
			array(
				'flushed' => true,
				'hard'    => false,
			),
			self::regen( 'option', 'rewrite_rules' ),
			self::no_fingerprint()
		);
	}
}
