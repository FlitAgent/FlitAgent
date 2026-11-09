<?php
/**
 * Write tool: flush_object_cache.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * `flush_object_cache`: empties the object cache with core's `wp_cache_flush()`.
 */
final class FlushObjectCache extends RegenTool {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'flush_object_cache';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: flush the object cache',
			'Empties the WordPress object cache (wp_cache_flush). With a persistent object cache (Redis, Memcached) every cached value is rebuilt on demand, so the site may be slower for a moment; a cache server shared with other sites may be emptied for them too. Without one it only affects the current request. Applied at once if an administrator enabled auto-approve for it; otherwise returns approval_required. Nothing to roll back: the cache regenerates.',
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
		$persistent = (bool) wp_using_ext_object_cache();
		return Assessment::reversible(
			$persistent ? 'Flush the persistent object cache; cached values are rebuilt on demand.' : 'Flush the object cache (no persistent object cache is in use, so this only affects the current request).',
			array( 'persistent_object_cache' => $persistent ),
			array(),
			true
		);
	}

	/**
	 * Flushes.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When the cache could not be flushed.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		if ( false === wp_cache_flush() ) {
			throw new ToolError( 'The object cache could not be flushed.' );
		}
		return new Outcome(
			array(
				'flushed'                 => true,
				'persistent_object_cache' => (bool) wp_using_ext_object_cache(),
			),
			self::regen( 'cache', 'object' ),
			self::no_fingerprint()
		);
	}
}
