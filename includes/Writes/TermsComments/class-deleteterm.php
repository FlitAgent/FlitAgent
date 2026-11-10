<?php
/**
 * Write tool: delete_term.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\TermsComments;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `delete_term`: permanently deletes one term, with `delete_term` on it. Always destructive (WordPress has no term
 * trash): the preview shows the attached objects, the child terms that move up, the default term objects left without
 * a term receive, and the menu items that are removed. The taxonomy's default term is refused.
 */
final class DeleteTerm implements WriteTool {

	/**
	 * Most child terms listed in a preview.
	 */
	const MAX_CHILDREN = 50;

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_term';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete a term',
			'Permanently deletes one term from a taxonomy shown in the REST API (not menus or post formats). In a hierarchical taxonomy, child terms move to the deleted term\'s parent; posts and other items left with no term in the taxonomy get its default term, and menu items linking to the term are removed. The default term cannot be deleted. Always returns approval_required: an administrator must approve it in wp-admin. There is no rollback.',
			array(
				'term_id'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the term to delete.',
				),
				'taxonomy' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 32,
					'description' => 'Taxonomy of the term, e.g. category.',
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
		return array( Risk::DESTRUCTIVE );
	}

	/**
	 * Never auto-approved.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the deletion. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$term_id  = (int) $args['term_id'];
		$taxonomy = (string) $args['taxonomy'];
		$object   = Terms::taxonomy( $taxonomy );
		if ( $object instanceof Assessment ) {
			return $object;
		}

		// Checked before the capability: core denies delete_term on the default term, which would hide the reason.
		$default = Terms::default_term_id( $taxonomy );
		if ( $default === $term_id && null !== Terms::find( $term_id, $taxonomy ) ) {
			return Assessment::prohibited( 'default_term', sprintf( 'Term %d is the default %s term, which WordPress gives to content left without one, so it cannot be deleted. Set another default first.', $term_id, Terms::name( $object ) ) );
		}
		$term = Terms::allowed( $term_id, $taxonomy, 'delete_term' );
		if ( $term instanceof Assessment ) {
			return $term;
		}

		$objects    = Terms::objects( $term );
		$children   = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $term_id,
				'hide_empty' => false,
			)
		);
		$children   = is_array( $children ) ? $children : array();
		$child_list = array();
		$child_ids  = array();
		foreach ( $children as $child ) {
			$child_ids[] = (int) $child->term_id;
			if ( count( $child_list ) < self::MAX_CHILDREN ) {
				$child_list[] = array(
					'term_id' => (int) $child->term_id,
					'name'    => (string) $child->name,
				);
			}
		}
		$default_term = 0 === $default ? null : Terms::find( $default, $taxonomy );
		$menu_items   = wp_get_associated_nav_menu_items( $term_id, 'taxonomy', $taxonomy );
		$menu_items   = is_array( $menu_items ) ? count( $menu_items ) : 0;

		$effects = array( Summary::count( count( $objects ), Terms::item_noun( $object, false ) . ' loses', Terms::item_noun( $object, true ) . ' lose' ) . ' it' );
		if ( ! empty( $object->hierarchical ) ) {
			$effects[] = sprintf( '%s to %s', Summary::count( count( $child_ids ), 'child term moves', 'child terms move' ), 0 === (int) $term->parent ? 'the top level' : sprintf( 'term %d', (int) $term->parent ) );
		}
		$left      = Terms::item_noun( $object, true ) . ' left without a term';
		$effects[] = null === $default_term ? $left . ' keep none' : sprintf( '%s get "%s"', $left, $default_term->name );
		if ( 0 !== $menu_items ) {
			$effects[] = Summary::count( $menu_items, 'menu item is', 'menu items are' ) . ' removed';
		}

		return Assessment::destructive(
			sprintf( 'Permanently delete the %s "%s" (term %d): %s. This cannot be undone.', Terms::label( $object ), $term->name, $term_id, implode( ', ', $effects ) ),
			array(
				'term_id'             => $term_id,
				'taxonomy'            => $taxonomy,
				'before'              => Terms::snapshot( $term ),
				'after'               => array(),
				'attached_objects'    => count( $objects ),
				'children_reparented' => $child_list,
				'children_new_parent' => (int) $term->parent,
				'default_term'        => null === $default_term ? null : array(
					'term_id' => (int) $default_term->term_id,
					'name'    => (string) $default_term->name,
				),
				'menu_items_removed'  => $menu_items,
			),
			array(
				'fingerprint' => Canonical::hash( array( Terms::fingerprint( $term ), $objects, $child_ids, $default, $menu_items ) ),
			)
		);
	}

	/**
	 * Deletes the term.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the term changed or WordPress could not delete it.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$term_id  = (int) $args['term_id'];
		$taxonomy = (string) $args['taxonomy'];
		Recheck::unchanged( $this, $args, $assessment, sprintf( 'Term %d', $term_id ) );

		if ( true !== wp_delete_term( $term_id, $taxonomy ) ) {
			throw new ToolError( sprintf( 'WordPress could not delete term %d.', (int) $term_id ) );
		}
		return new Outcome(
			array(
				'term_id'  => $term_id,
				'taxonomy' => $taxonomy,
				'deleted'  => true,
			),
			Rollback::none()
		);
	}

	/**
	 * Confirms the term is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return null === Terms::find( (int) $args['term_id'], (string) $args['taxonomy'] ) ? $outcome : $outcome->with_verification_failure( 'The term still exists after the delete.' );
	}
}
