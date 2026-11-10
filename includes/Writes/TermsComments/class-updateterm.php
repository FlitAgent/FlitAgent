<?php
/**
 * Write tool: update_term.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\TermsComments;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `update_term`: changes the name, slug, description and/or parent of one term, with `edit_term` on that term.
 * Reversible: the previous name, slug, description and parent are stored and restored by rollback. Never auto-approved,
 * nor is its rollback: the term may be in menus, archives and URLs visitors already see.
 */
final class UpdateTerm implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'update_term';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: update a term',
			'Changes the name, slug, description and/or parent of one term in a taxonomy shown in the REST API (not menus or post formats). A slug used by another term is refused with that term_id. Rollback (rollback_change) restores the previous name, slug, description and parent. Always returns approval_required: an administrator approves it in wp-admin.',
			array(
				'term_id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the term to change.',
				),
				'taxonomy'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 32,
					'description' => 'Taxonomy of the term, e.g. category.',
				),
				'name'        => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => 'New name.',
				),
				'slug'        => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => 'New URL slug.',
				),
				'description' => array(
					'type'        => 'string',
					'maxLength'   => 10000,
					'description' => 'New description.',
				),
				'parent'      => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'New parent term_id in the same hierarchical taxonomy (0 for top level).',
				),
			),
			array( 'term_id', 'taxonomy' ),
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
	 * Never auto-approved: the term may already be live.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the update. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When nothing is given or would change, or the parent is not allowed.
	 */
	public function assess( array $args ): Assessment {
		$changes = self::changes( $args );
		if ( array() === $changes ) {
			throw new ToolError( 'Give at least one of name, slug, description, parent.' );
		}
		if ( isset( $changes['name'] ) && '' === $changes['name'] ) {
			throw new ToolError( 'name must not be empty.' );
		}

		$term_id  = (int) $args['term_id'];
		$taxonomy = (string) $args['taxonomy'];
		$object   = Terms::taxonomy( $taxonomy );
		if ( $object instanceof Assessment ) {
			return $object;
		}
		$term = Terms::allowed( $term_id, $taxonomy, 'edit_term' );
		if ( $term instanceof Assessment ) {
			return $term;
		}
		if ( isset( $changes['parent'] ) ) {
			Terms::check_parent( $object, $changes['parent'], $term_id );
		}

		$before = array_intersect_key( Terms::snapshot( $term ), $changes );
		// The summary names only the fields that change; one sent with its current value stays in the preview.
		$changing = array();
		foreach ( $changes as $field => $value ) {
			if ( ! array_key_exists( $field, $before ) || $before[ $field ] !== $value ) {
				$changing[] = $field;
			}
		}
		if ( array() === $changing ) {
			throw new ToolError( sprintf( 'Term %d already has that %s; nothing to change.', (int) $term_id, esc_textarea( implode( ', ', array_keys( $changes ) ) ) ) );
		}
		if ( isset( $changes['slug'] ) && $changes['slug'] !== $before['slug'] ) {
			$by_slug = get_term_by( 'slug', $changes['slug'], $taxonomy );
			if ( $by_slug && ! is_wp_error( $by_slug ) && (int) $by_slug->term_id !== $term_id ) {
				return Assessment::prohibited( 'term_exists', sprintf( 'The slug "%s" is already used in %s (term_id %d).', $changes['slug'], Terms::named_taxonomy( $object ), (int) $by_slug->term_id ) );
			}
		}

		return Assessment::reversible(
			sprintf( 'Update the %s of the %s %s.', Summary::fields( $changing ), Terms::label( $object ), Summary::named( (string) $term->name, sprintf( '%d', $term_id ) ) ),
			array(
				'term_id'  => $term_id,
				'taxonomy' => $taxonomy,
				'before'   => $before,
				'after'    => $changes,
			),
			array( 'fingerprint' => Terms::fingerprint( $term ) ),
			false
		);
	}

	/**
	 * Updates the term.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the term changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$term_id  = (int) $args['term_id'];
		$taxonomy = (string) $args['taxonomy'];
		Recheck::unchanged( $this, $args, $assessment, sprintf( 'Term %d', $term_id ) );
		$term = Terms::find( $term_id, $taxonomy );
		if ( null === $term ) {
			throw new ToolError( sprintf( 'Term %d disappeared; nothing was changed.', (int) $term_id ) );
		}
		$before = Terms::snapshot( $term );

		$updated = wp_update_term( $term_id, $taxonomy, wp_slash( self::changes( $args ) ) );
		if ( is_wp_error( $updated ) || ! is_array( $updated ) ) {
			throw new ToolError( sprintf( 'WordPress could not update term %d%s.', (int) $term_id, is_wp_error( $updated ) ? ': ' . esc_textarea( $updated->get_error_message() ) : '' ) );
		}

		$after = Terms::find( $term_id, $taxonomy );
		return new Outcome(
			array(
				'term_id'  => $term_id,
				'taxonomy' => $taxonomy,
			),
			Rollback::make( Rollback::SNAPSHOT, 'term', $term_id, array( 'taxonomy' => $taxonomy ), $before ),
			array( 'fingerprint' => null === $after ? '' : Terms::fingerprint( $after ) )
		);
	}

	/**
	 * Reads the term back and compares the changed fields with the request.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$term = Terms::find( (int) $args['term_id'], (string) $args['taxonomy'] );
		if ( null === $term ) {
			return $outcome->with_verification_failure( 'The term could not be read back after the update.' );
		}
		$differs = Terms::differs( $term, self::changes( $args ) );
		if ( array() === $differs ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result(
			array(
				'stored_verbatim' => false,
				'differs'         => $differs,
				'stored'          => Terms::snapshot( $term ),
			)
		)->with_warning( 'stored_differently', sprintf( 'WordPress stored the %s differently from the request (for example sanitized).', implode( ', ', $differs ) ) );
	}

	/**
	 * Assesses restoring the previous fields. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$term_id  = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$taxonomy = isset( $rollback['refs']['taxonomy'] ) ? (string) $rollback['refs']['taxonomy'] : '';
		$term     = Terms::allowed( $term_id, $taxonomy, 'edit_term' );
		if ( $term instanceof Assessment ) {
			return $term;
		}
		$restored = self::before( $rollback );
		if ( null === $restored ) {
			return Assessment::prohibited( 'no_rollback', 'The rollback reference has no stored term fields.' );
		}
		if ( $restored['parent'] > 0 && null === Terms::find( $restored['parent'], $taxonomy ) ) {
			return Assessment::prohibited( 'parent_missing', sprintf( 'The previous parent term %d no longer exists, so term %d cannot be restored.', $restored['parent'], $term_id ) );
		}

		$current = Terms::snapshot( $term );
		$object  = get_taxonomy( $taxonomy );
		$fields  = self::changing( $object, $current, $restored );
		if ( array() === $fields ) {
			return Assessment::prohibited( 'already_restored', sprintf( 'The %s already has its previous fields; nothing to roll back.', self::rollback_name( $term, $object ) ) );
		}
		return Assessment::reversible(
			self::rollback_summary( $term, $object, $fields, $restored ),
			array(
				'term_id'  => $term_id,
				'current'  => $current,
				'restored' => $restored,
			),
			array( 'fingerprint' => Terms::fingerprint( $term ) ),
			false
		);
	}

	/**
	 * The fields the rollback changes: those that differ from the stored ones, never the parent on a flat taxonomy.
	 *
	 * @param object|false         $taxonomy Taxonomy object.
	 * @param array<string, mixed> $current  Fields now.
	 * @param array<string, mixed> $restored Fields the rollback restores.
	 * @return string[]
	 */
	private static function changing( $taxonomy, array $current, array $restored ): array {
		$fields = array();
		foreach ( Terms::FIELDS as $field ) {
			if ( $current[ $field ] !== $restored[ $field ] && ( 'parent' !== $field || ! empty( $taxonomy->hierarchical ) ) ) {
				$fields[] = $field;
			}
		}
		return $fields;
	}

	/**
	 * The term as the rollback names it: `tag "News" (term 5)`.
	 *
	 * @param object       $term     Term now.
	 * @param object|false $taxonomy Taxonomy object.
	 * @return string
	 */
	private static function rollback_name( $term, $taxonomy ): string {
		return sprintf( '%s "%s" (term %d)', $taxonomy ? Terms::label( $taxonomy ) : 'term', $term->name, (int) $term->term_id );
	}

	/**
	 * What the rollback changes: the changing fields, and the name it goes back to when the name changes.
	 *
	 * @param object               $term     Term now.
	 * @param object|false         $taxonomy Taxonomy object.
	 * @param string[]             $fields   Changing fields.
	 * @param array<string, mixed> $restored Fields the rollback restores.
	 * @return string
	 */
	private static function rollback_summary( $term, $taxonomy, array $fields, array $restored ): string {
		return sprintf( 'Restore the previous %s of the %s', Summary::fields( $fields ), self::rollback_name( $term, $taxonomy ) )
			. ( in_array( 'name', $fields, true ) ? sprintf( '; the name goes back to "%s".', $restored['name'] ) : '.' );
	}

	/**
	 * Restores the previous fields if the term is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the term changed or WordPress could not restore it.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$term_id  = (int) $rollback['target']['id'];
		$taxonomy = (string) $rollback['refs']['taxonomy'];
		$term     = Terms::find( $term_id, $taxonomy );
		$restored = self::before( $rollback );
		if ( null === $term || null === $restored || ! isset( $expected_state['fingerprint'] ) || Terms::fingerprint( $term ) !== $expected_state['fingerprint'] ) {
			throw new ToolError( sprintf( 'Term %d changed after the rollback was assessed; nothing was restored.', (int) $term_id ) );
		}

		$updated = wp_update_term( $term_id, $taxonomy, wp_slash( $restored ) );
		if ( is_wp_error( $updated ) || ! is_array( $updated ) ) {
			throw new ToolError( sprintf( 'WordPress could not restore term %d%s.', (int) $term_id, is_wp_error( $updated ) ? ': ' . esc_textarea( $updated->get_error_message() ) : '' ) );
		}

		$outcome = new Outcome(
			array(
				'term_id'  => $term_id,
				'taxonomy' => $taxonomy,
			),
			Rollback::none()
		);
		$after   = Terms::find( $term_id, $taxonomy );
		if ( null === $after ) {
			return $outcome->with_verification_failure( 'The term could not be read back after the rollback.' );
		}
		return Terms::snapshot( $after ) === $restored ? $outcome : $outcome->with_warning( 'restored_differently', 'WordPress stored the restored fields differently.' );
	}

	/**
	 * Requested changes, in Terms::FIELDS order.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, string|int>
	 */
	private static function changes( array $args ): array {
		$changes = array();
		foreach ( Terms::FIELDS as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$changes[ $field ] = 'parent' === $field ? (int) $args[ $field ] : ( 'description' === $field ? (string) $args[ $field ] : trim( (string) $args[ $field ] ) );
			}
		}
		return $changes;
	}

	/**
	 * Stored before-values of a rollback reference, or null.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return array{name: string, slug: string, description: string, parent: int}|null
	 */
	private static function before( array $rollback ): ?array {
		$before = isset( $rollback['before'] ) && is_array( $rollback['before'] ) ? $rollback['before'] : array();
		foreach ( Terms::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $before ) ) {
				return null;
			}
		}
		return array(
			'name'        => (string) $before['name'],
			'slug'        => (string) $before['slug'],
			'description' => (string) $before['description'],
			'parent'      => (int) $before['parent'],
		);
	}
}
