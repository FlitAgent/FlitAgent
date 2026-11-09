<?php
/**
 * Write tool: create_term.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\TermsComments;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Markup;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `create_term`: adds a term to a taxonomy shown in the REST API (not menus or post formats), with the taxonomy's own
 * `edit_terms` capability. Reversible and auto-approvable: a new term is linked nowhere until something uses it. Its
 * rollback deletes the term and always waits for a person: by then it may be in a menu or have child terms, which the
 * fingerprint does not see; it is destructive once the term was changed or attached to content.
 */
final class CreateTerm implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'create_term';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: create a term',
			'Creates a term (category, tag or custom taxonomy term) in any taxonomy shown in the REST API, except menus and post formats. A term with the same name (under the same parent) or slug is refused with its term_id; use update_term to change it. Rollback (rollback_change) deletes the new term and always returns approval_required. May be applied at once if an administrator enabled auto-approve; otherwise returns approval_required.',
			array(
				'taxonomy'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 32,
					'description' => 'Taxonomy name, e.g. category, post_tag, product_cat.',
				),
				'name'        => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => 'Term name.',
				),
				'slug'        => array(
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => 'URL slug. Default: generated from the name.',
				),
				'description' => array(
					'type'        => 'string',
					'maxLength'   => 10000,
					'description' => 'Term description.',
				),
				'parent'      => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Parent term_id in the same hierarchical taxonomy. Default 0 (top level).',
				),
			),
			array( 'taxonomy', 'name' ),
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
	 * May auto-approve (without code).
	 */
	public function may_auto_approve(): bool {
		return true;
	}

	/**
	 * Assesses the new term. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the name is empty or the parent is not allowed.
	 */
	public function assess( array $args ): Assessment {
		$fields = self::fields( $args );
		if ( '' === $fields['name'] ) {
			throw new ToolError( 'name must not be empty.' );
		}

		$taxonomy = Terms::taxonomy( $fields['taxonomy'] );
		if ( $taxonomy instanceof Assessment ) {
			return $taxonomy;
		}
		if ( ! current_user_can( (string) $taxonomy->cap->edit_terms ) ) {
			return Assessment::prohibited( 'forbidden', sprintf( 'You cannot create terms in %s.', $fields['taxonomy'] ) );
		}
		Terms::check_parent( $taxonomy, $fields['parent'], 0 );

		$existing = empty( $taxonomy->hierarchical ) ? term_exists( $fields['name'], $fields['taxonomy'] ) : term_exists( $fields['name'], $fields['taxonomy'], $fields['parent'] );
		if ( is_array( $existing ) && ! empty( $existing['term_id'] ) ) {
			return self::exists( sprintf( 'A term named "%s" already exists in %s', $fields['name'], $fields['taxonomy'] ), (int) $existing['term_id'] );
		}
		if ( '' !== $fields['slug'] ) {
			$by_slug = get_term_by( 'slug', $fields['slug'], $fields['taxonomy'] );
			if ( $by_slug && ! is_wp_error( $by_slug ) ) {
				return self::exists( sprintf( 'The slug "%s" is already used in %s', $fields['slug'], $fields['taxonomy'] ), (int) $by_slug->term_id );
			}
		}

		$parent = 0 === $fields['parent'] ? null : Terms::find( $fields['parent'], $fields['taxonomy'] );
		return Assessment::reversible(
			sprintf( 'Create the %s "%s"%s.', Terms::label( $taxonomy ), $fields['name'], null === $parent ? '' : sprintf( ' under "%s" (term %d)', $parent->name, $fields['parent'] ) ),
			array(
				'before' => array(),
				'after'  => $fields,
			),
			array(
				'taxonomy' => $fields['taxonomy'],
				'parent'   => null === $parent ? '' : Terms::fingerprint( $parent ),
			),
			Markup::is_safe_term( $fields, $fields['taxonomy'] )
		);
	}

	/**
	 * Creates the term.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the state changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		Recheck::unchanged( $this, $args, $assessment, 'The taxonomy' );
		$fields = self::fields( $args );

		$extra = array(
			'description' => $fields['description'],
			'parent'      => $fields['parent'],
		);
		if ( '' !== $fields['slug'] ) {
			$extra['slug'] = $fields['slug'];
		}
		$created = wp_insert_term( wp_slash( $fields['name'] ), $fields['taxonomy'], wp_slash( $extra ) );
		if ( is_wp_error( $created ) ) {
			if ( 'term_exists' === $created->get_error_code() && is_numeric( $created->get_error_data() ) ) {
				throw new ToolError( sprintf( 'A matching term already exists in %s (term_id %d); nothing was created.', esc_textarea( $fields['taxonomy'] ), (int) $created->get_error_data() ) );
			}
			throw new ToolError( sprintf( 'WordPress could not create the term in %s.', esc_textarea( $fields['taxonomy'] ) ) );
		}
		if ( ! is_array( $created ) || empty( $created['term_id'] ) ) {
			throw new ToolError( sprintf( 'WordPress could not create the term in %s.', esc_textarea( $fields['taxonomy'] ) ) );
		}

		$term_id = (int) $created['term_id'];
		$term    = Terms::find( $term_id, $fields['taxonomy'] );
		return new Outcome(
			array(
				'term_id'          => $term_id,
				'term_taxonomy_id' => (int) $created['term_taxonomy_id'],
				'taxonomy'         => $fields['taxonomy'],
			),
			Rollback::make( Rollback::SNAPSHOT, 'term', $term_id, array( 'taxonomy' => $fields['taxonomy'] ), array( 'exists' => false ) ),
			array( 'fingerprint' => null === $term ? '' : Terms::usage_fingerprint( $term ) )
		);
	}

	/**
	 * Reads the term back and compares it with the request.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$fields = self::fields( $args );
		$term   = Terms::find( (int) $outcome->result()['term_id'], $fields['taxonomy'] );
		if ( null === $term ) {
			return $outcome->with_verification_failure( 'The new term could not be read back.' );
		}
		$wanted = array(
			'name'        => $fields['name'],
			'description' => $fields['description'],
			'parent'      => $fields['parent'],
		);
		if ( '' !== $fields['slug'] ) {
			$wanted['slug'] = $fields['slug'];
		}
		$differs = Terms::differs( $term, $wanted );
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
	 * Assesses deleting the created term. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$term_id  = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$taxonomy = isset( $rollback['refs']['taxonomy'] ) ? (string) $rollback['refs']['taxonomy'] : '';
		$term     = Terms::find( $term_id, $taxonomy );
		if ( null !== $term && Terms::default_term_id( $taxonomy ) === $term_id ) {
			return Assessment::prohibited( 'default_term', sprintf( 'Term %d is now the default %s term, so it cannot be deleted.', $term_id, $taxonomy ) );
		}
		$term = Terms::allowed( $term_id, $taxonomy, 'delete_term' );
		if ( $term instanceof Assessment ) {
			return $term;
		}

		$objects = count( Terms::objects( $term ) );
		return Assessment::reversible(
			sprintf( 'Delete the term "%s" (term %d in %s) created by that write%s.', $term->name, $term_id, $taxonomy, 0 === $objects ? '' : sprintf( '; %d objects lose it', $objects ) ),
			array(
				'term_id'  => $term_id,
				'current'  => Terms::snapshot( $term ) + array( 'objects' => $objects ),
				'restored' => array( 'term' => 'deleted' ),
			),
			array( 'fingerprint' => Terms::usage_fingerprint( $term ) ),
			false
		);
	}

	/**
	 * Deletes the created term if it is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the term changed or WordPress could not delete it.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$term_id  = (int) $rollback['target']['id'];
		$taxonomy = (string) $rollback['refs']['taxonomy'];
		$term     = Terms::find( $term_id, $taxonomy );
		if ( null === $term || ! isset( $expected_state['fingerprint'] ) || Terms::usage_fingerprint( $term ) !== $expected_state['fingerprint'] ) {
			throw new ToolError( sprintf( 'Term %d changed after the rollback was assessed; nothing was deleted.', (int) $term_id ) );
		}
		if ( true !== wp_delete_term( $term_id, $taxonomy ) ) {
			throw new ToolError( sprintf( 'WordPress could not delete term %d.', (int) $term_id ) );
		}

		$outcome = new Outcome(
			array(
				'term_id'  => $term_id,
				'taxonomy' => $taxonomy,
				'deleted'  => true,
			),
			Rollback::none()
		);
		return null === Terms::find( $term_id, $taxonomy ) ? $outcome : $outcome->with_verification_failure( 'The term still exists after the rollback.' );
	}

	/**
	 * Normalized fields from the arguments.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array{taxonomy: string, name: string, slug: string, description: string, parent: int}
	 */
	private static function fields( array $args ): array {
		return array(
			'taxonomy'    => (string) $args['taxonomy'],
			'name'        => trim( (string) $args['name'] ),
			'slug'        => isset( $args['slug'] ) ? trim( (string) $args['slug'] ) : '',
			'description' => isset( $args['description'] ) ? (string) $args['description'] : '',
			'parent'      => isset( $args['parent'] ) ? (int) $args['parent'] : 0,
		);
	}

	/**
	 * Refusal for a duplicate, naming the existing term.
	 *
	 * @param string $what    What exists.
	 * @param int    $term_id Existing term ID.
	 * @return Assessment
	 */
	private static function exists( string $what, int $term_id ): Assessment {
		return Assessment::prohibited( 'term_exists', sprintf( '%s (term_id %d). Use update_term to change it.', $what, $term_id ) );
	}
}
