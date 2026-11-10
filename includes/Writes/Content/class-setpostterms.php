<?php
/**
 * Write tool: set_post_terms.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Content;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\TermsComments\Terms;

defined( 'ABSPATH' ) || exit;

/**
 * `set_post_terms`: replaces, appends or removes existing terms of one taxonomy on one post. Terms are given by id and
 * passed to WordPress as integers, so none is ever created. Reversible: the previous term ids are snapshotted and
 * restored by rollback. Auto-approvable only on drafts and pending posts.
 */
final class SetPostTerms implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'set_post_terms';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: set a post\'s terms',
			'Replaces (default), appends or removes the terms of one taxonomy (categories, tags or a custom taxonomy shown in the REST API) on one post, by term id. Terms must already exist; none is created. The previous terms are kept so rollback_change can restore them. Changes to drafts and pending posts may be applied at once if an administrator enabled auto-approve.',
			array(
				'post_id'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the post.',
				),
				'taxonomy' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 32,
					'description' => 'Taxonomy, e.g. category or post_tag.',
				),
				'term_ids' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'maxItems'    => 100,
					'description' => 'Term ids.',
				),
				'mode'     => array(
					'type'        => 'string',
					'enum'        => array( 'replace', 'append', 'remove' ),
					'description' => 'replace (default) sets exactly these terms; append adds them; remove takes them off.',
				),
			),
			array( 'post_id', 'taxonomy', 'term_ids' ),
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
	 * May auto-approve (drafts and pending posts).
	 */
	public function may_auto_approve(): bool {
		return true;
	}

	/**
	 * Assesses the change. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When a term does not exist or nothing would change.
	 */
	public function assess( array $args ): Assessment {
		$post_id  = (int) $args['post_id'];
		$taxonomy = (string) $args['taxonomy'];
		$target   = self::target( $post_id, $taxonomy );
		if ( $target instanceof Assessment ) {
			return $target;
		}
		list( $post, $current ) = $target;

		$ids = array();
		foreach ( $args['term_ids'] as $id ) {
			$term = get_term( (int) $id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				throw new ToolError( sprintf( 'Term %d does not exist in %s. Terms are never created here; create it first.', (int) $id, esc_textarea( self::in_taxonomy( $taxonomy ) ) ) );
			}
			$ids[] = (int) $id;
		}
		$result = self::result( $current, $ids, isset( $args['mode'] ) ? (string) $args['mode'] : 'replace' );
		if ( $result === $current ) {
			throw new ToolError( sprintf( 'Post %d already has those terms in %s; nothing to change.', (int) $post_id, esc_textarea( self::in_taxonomy( $taxonomy ) ) ) );
		}

		return Assessment::reversible(
			sprintf( 'Set %s of %s to %s.', self::taxonomy_name( $taxonomy ), Posts::name( $post ), self::term_names( $taxonomy, $result ) ) . Posts::live_note( $post ),
			array(
				'post_id'  => $post_id,
				'taxonomy' => $taxonomy,
				'before'   => self::display( $taxonomy, $current ),
				'after'    => self::display( $taxonomy, $result ),
			),
			self::state( $post, $taxonomy, $current ),
			Posts::is_private( (string) $post->post_status )
		);
	}

	/**
	 * Sets the terms.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$post_id  = (int) $args['post_id'];
		$taxonomy = (string) $args['taxonomy'];
		$before   = self::expect( $post_id, $taxonomy, $assessment->preconditions() );
		$result   = self::result( $before, array_map( 'intval', $args['term_ids'] ), isset( $args['mode'] ) ? (string) $args['mode'] : 'replace' );
		self::write( $post_id, $taxonomy, $result );

		return new Outcome(
			array(
				'post_id'  => $post_id,
				'taxonomy' => $taxonomy,
				'term_ids' => $result,
			),
			Rollback::make( Rollback::SNAPSHOT, 'post_terms', $post_id, array( 'taxonomy' => $taxonomy ), array( 'term_ids' => $before ) ),
			array( 'fingerprint' => self::fingerprint( $taxonomy, self::ids( $post_id, $taxonomy ) ) )
		);
	}

	/**
	 * Reads the terms back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$stored = self::ids( (int) $args['post_id'], (string) $args['taxonomy'] );
		return $stored === $outcome->result()['term_ids'] ? $outcome : $outcome->with_warning( 'stored_differently', ( array() === $stored ? sprintf( 'The post has no terms in %s, not the requested ones.', self::in_taxonomy( (string) $args['taxonomy'] ) ) : sprintf( 'The post has terms %s in %s, not the requested ones.', implode( ', ', $stored ), self::in_taxonomy( (string) $args['taxonomy'] ) ) ) );
	}

	/**
	 * Assesses restoring the previous terms. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$post_id  = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$taxonomy = isset( $rollback['refs']['taxonomy'] ) ? (string) $rollback['refs']['taxonomy'] : '';
		$target   = self::target( $post_id, $taxonomy );
		if ( $target instanceof Assessment ) {
			return $target;
		}
		list( $post, $current ) = $target;
		$restored               = self::snapshot( $rollback );

		return Assessment::reversible(
			sprintf( 'Restore %s of %s to %s.', self::taxonomy_name( $taxonomy ), Posts::name( $post ), self::term_names( $taxonomy, $restored ) ) . Posts::live_note( $post ),
			array(
				'post_id'  => $post_id,
				'taxonomy' => $taxonomy,
				'current'  => self::display( $taxonomy, $current ),
				'restored' => self::display( $taxonomy, $restored ),
			),
			self::state( $post, $taxonomy, $current ),
			Posts::is_private( (string) $post->post_status )
		);
	}

	/**
	 * Restores the previous terms.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$post_id  = (int) $rollback['target']['id'];
		$taxonomy = (string) $rollback['refs']['taxonomy'];
		self::expect( $post_id, $taxonomy, $expected_state );
		$restored = self::snapshot( $rollback );
		self::write( $post_id, $taxonomy, $restored );

		$outcome = new Outcome(
			array(
				'post_id'  => $post_id,
				'taxonomy' => $taxonomy,
				'term_ids' => $restored,
			),
			Rollback::none()
		);
		return self::ids( $post_id, $taxonomy ) === $restored ? $outcome : $outcome->with_warning( 'restored_differently', 'Some previous terms no longer exist, so they were not restored.' );
	}

	/**
	 * The post and its current term ids, or a refusal. Read-only.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return array{0: object, 1: int[]}|Assessment
	 */
	private static function target( int $post_id, string $taxonomy ) {
		$post = Posts::find_untrashed( $post_id, 'edit_post' );
		if ( $post instanceof Assessment ) {
			return $post;
		}
		$object = get_taxonomy( $taxonomy );
		if ( ! $object || empty( $object->show_in_rest ) || ! is_object_in_taxonomy( (string) $post->post_type, $taxonomy ) ) {
			return Assessment::prohibited( 'not_supported', sprintf( '%s is not available for %s items over the REST API.', ucfirst( self::in_taxonomy( $taxonomy ) ), $post->post_type ) );
		}
		if ( ! current_user_can( $object->cap->assign_terms ) ) {
			return Assessment::prohibited( 'forbidden', sprintf( 'You cannot assign terms in %s.', self::in_taxonomy( $taxonomy ) ) );
		}
		return array( $post, self::ids( $post_id, $taxonomy ) );
	}

	/**
	 * The resulting term ids, sorted.
	 *
	 * @param int[]  $current Current ids.
	 * @param int[]  $ids     Requested ids.
	 * @param string $mode    replace, append or remove.
	 * @return int[]
	 */
	private static function result( array $current, array $ids, string $mode ): array {
		if ( 'append' === $mode ) {
			$ids = array_merge( $current, $ids );
		} elseif ( 'remove' === $mode ) {
			$ids = array_diff( $current, $ids );
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Current term ids, sorted.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	private static function ids( int $post_id, string $taxonomy ): array {
		$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		sort( $ids );
		return $ids;
	}

	/**
	 * Sets exactly these term ids, as integers (WordPress creates terms for strings).
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $ids      Term ids.
	 * @throws ToolError When WordPress refused.
	 */
	private static function write( int $post_id, string $taxonomy, array $ids ): void {
		$set = wp_set_object_terms( $post_id, array_map( 'intval', $ids ), $taxonomy, false );
		if ( is_wp_error( $set ) ) {
			throw new ToolError( sprintf( 'WordPress could not set the terms of post %d in %s.', (int) $post_id, esc_textarea( self::in_taxonomy( $taxonomy ) ) ) );
		}
	}

	/**
	 * Throws unless the post still has the assessed terms.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param string               $taxonomy Taxonomy.
	 * @param array<string, mixed> $expected Preconditions.
	 * @return int[] Current ids.
	 * @throws ToolError When they changed.
	 */
	private static function expect( int $post_id, string $taxonomy, array $expected ): array {
		$post    = get_post( $post_id );
		$current = self::ids( $post_id, $taxonomy );
		if ( ! $post || self::state( $post, $taxonomy, $current ) !== $expected ) {
			throw new ToolError( sprintf( 'The terms of post %d in %s changed after they were assessed; nothing was changed.', (int) $post_id, esc_textarea( self::in_taxonomy( $taxonomy ) ) ) );
		}
		return $current;
	}

	/**
	 * Preconditions: the term ids and the post's status.
	 *
	 * @param object $post     Post.
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $ids      Term ids.
	 * @return array<string, string>
	 */
	private static function state( $post, string $taxonomy, array $ids ): array {
		return array(
			'fingerprint' => self::fingerprint( $taxonomy, $ids ),
			'status'      => (string) $post->post_status,
		);
	}

	/**
	 * Fingerprint of a post's terms in one taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $ids      Sorted term ids.
	 * @return string
	 */
	private static function fingerprint( string $taxonomy, array $ids ): string {
		return Canonical::hash( array( $taxonomy, $ids ) );
	}

	/**
	 * Preview map: `taxonomy => "3 (News), 4 (Tech)"`, nothing for no terms.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $ids      Term ids.
	 * @return array<string, string>
	 */
	private static function display( string $taxonomy, array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}
		$labels = array();
		foreach ( $ids as $id ) {
			$term     = get_term( $id, $taxonomy );
			$labels[] = $term && ! is_wp_error( $term ) ? sprintf( '%d (%s)', $id, $term->name ) : (string) $id;
		}
		return array( $taxonomy => implode( ', ', $labels ) );
	}

	/**
	 * The taxonomy as a summary names it: its label with its slug ("the Tags (post_tag)"), or "the post_tag terms"
	 * without a label.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private static function taxonomy_name( string $taxonomy ): string {
		$object = get_taxonomy( $taxonomy );
		$label  = $object && isset( $object->labels->name ) ? trim( (string) $object->labels->name ) : '';
		return '' === $label ? sprintf( 'the %s terms', $taxonomy ) : sprintf( 'the %s (%s)', $label, $taxonomy );
	}

	/**
	 * The taxonomy as refusals and errors name it, like the term tools: "the tag (post_tag) taxonomy".
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private static function in_taxonomy( string $taxonomy ): string {
		$object = get_taxonomy( $taxonomy );
		return Terms::named_taxonomy( is_object( $object ) ? $object : (object) array( 'name' => $taxonomy ) );
	}

	/**
	 * Terms as a summary lists them: quoted name and id (`"News" (3)`), the bare id when the term has no name, or "none".
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $ids      Term ids.
	 * @return string
	 */
	private static function term_names( string $taxonomy, array $ids ): string {
		if ( array() === $ids ) {
			return 'none';
		}
		$names = array();
		foreach ( $ids as $id ) {
			$term    = get_term( $id, $taxonomy );
			$name    = $term && ! is_wp_error( $term ) ? (string) $term->name : '';
			$names[] = '' === trim( $name ) ? (string) $id : sprintf( '%s (%d)', Summary::named( $name, '' ), $id );
		}
		return Summary::join( $names );
	}

	/**
	 * Snapshotted term ids that still exist.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return int[]
	 */
	private static function snapshot( array $rollback ): array {
		$taxonomy = (string) $rollback['refs']['taxonomy'];
		$ids      = isset( $rollback['before']['term_ids'] ) && is_array( $rollback['before']['term_ids'] ) ? $rollback['before']['term_ids'] : array();
		$existing = array();
		foreach ( $ids as $id ) {
			$term = is_int( $id ) ? get_term( $id, $taxonomy ) : null;
			if ( $term && ! is_wp_error( $term ) ) {
				$existing[] = $id;
			}
		}
		sort( $existing );
		return $existing;
	}
}
