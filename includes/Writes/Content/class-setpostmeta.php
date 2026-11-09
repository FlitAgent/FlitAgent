<?php
/**
 * Write tool: set_post_meta.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Content;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `set_post_meta`: gives one meta key of one post exactly one plain-text value (PostMeta rules). Reversible: every
 * previous value is snapshotted and restored by rollback. Never auto-approved.
 */
final class SetPostMeta implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'set_post_meta';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: set a post custom field',
			'Sets one custom field (meta key) of one post to a single plain-text value, replacing every existing value of that key. Keys starting with "_" only when registered for the REST API; page-builder layout, code-snippet, secret-looking keys, structured values and serialized values are refused. The previous values are kept so rollback_change can restore them. Every change needs an administrator\'s approval (returns approval_required).',
			array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the post.',
				),
				'key'     => PostMeta::key_property(),
				'value'   => array(
					'type'        => 'string',
					'maxLength'   => 1000000,
					'description' => 'Value. For registered integer, number or boolean keys use "12", "1.5", "1" or "".',
				),
			),
			array( 'post_id', 'key', 'value' ),
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
	 * Never: a theme or plugin may print the value anywhere, so nothing here can tell it is safe to store without a person.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the change. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the value is invalid or already set.
	 */
	public function assess( array $args ): Assessment {
		$post_id = (int) $args['post_id'];
		$key     = (string) $args['key'];
		$value   = (string) $args['value'];
		$target  = PostMeta::target( $post_id, $key, 'edit_post_meta' );
		if ( $target instanceof Assessment ) {
			return $target;
		}
		list( $post, $values, $registered ) = $target;

		$refusal = PostMeta::value_refusal( $key, $value, $registered );
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( array( $value ) === $values ) {
			throw new ToolError( sprintf( 'Post %d already has that value for %s; nothing to change.', (int) $post_id, esc_textarea( $key ) ) );
		}

		return Assessment::reversible(
			sprintf( 'Set meta key %s of %s%s.', $key, Posts::name( $post ), count( $values ) > 1 ? sprintf( ', replacing its %d values', count( $values ) ) : '' ) . Posts::live_note( $post ),
			array(
				'post_id' => $post_id,
				'key'     => $key,
				'before'  => PostMeta::display( $key, $values ),
				'after'   => array( $key => $value ),
			),
			PostMeta::state( $post, $key, $values ),
			false
		);
	}

	/**
	 * Writes the value.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$post_id = (int) $args['post_id'];
		$key     = (string) $args['key'];
		$before  = PostMeta::expect( $post_id, $key, $assessment->preconditions() );
		PostMeta::replace( $post_id, $key, $before, array( (string) $args['value'] ) );
		return PostMeta::outcome( $post_id, $key, $before );
	}

	/**
	 * Reads the key back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		if ( PostMeta::values( (int) $args['post_id'], (string) $args['key'] ) === array( (string) $args['value'] ) ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result( array( 'stored_verbatim' => false ) )
			->with_warning( 'stored_differently', sprintf( 'WordPress stored %s differently from the request (for example a sanitize callback changed it). Read the meta to see what was saved.', $args['key'] ) );
	}

	/**
	 * Assesses restoring the previous values. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		return PostMeta::assess_restore( $rollback );
	}

	/**
	 * Restores the previous values.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		return PostMeta::restore( $rollback, $expected_state );
	}
}
