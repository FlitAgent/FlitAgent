<?php
/**
 * Shared checks for the taxonomy write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\TermsComments;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;

defined( 'ABSPATH' ) || exit;

/**
 * Taxonomy and term lookups, refusals and fingerprints used by create_term, update_term and delete_term. Read-only.
 */
final class Terms {

	/**
	 * Taxonomies whose terms are managed by other features: menus (nav_menu) and the fixed post formats.
	 */
	const RESERVED = array( 'nav_menu', 'post_format' );

	/**
	 * Term fields the tools change and restore.
	 */
	const FIELDS = array( 'name', 'slug', 'description', 'parent' );

	/**
	 * The taxonomy object, or a refusal when it does not exist, is not in the REST API, or is reserved.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return object|Assessment
	 */
	public static function taxonomy( string $taxonomy ) {
		if ( in_array( $taxonomy, self::RESERVED, true ) ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'Terms of %s are managed by menus or are fixed post formats, so FlitAgent does not change them with term tools.', self::named_taxonomy( $taxonomy ) ) );
		}
		$object = get_taxonomy( $taxonomy );
		if ( ! $object || empty( $object->show_in_rest ) ) {
			return Assessment::prohibited( 'not_supported', sprintf( '%s does not exist or is not available over the REST API, so FlitAgent does not change its terms.', ucfirst( self::named_taxonomy( is_object( $object ) ? $object : (object) array( 'name' => $taxonomy ) ) ) ) );
		}
		return $object;
	}

	/**
	 * The term if it exists in the taxonomy, or null.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return object|null
	 */
	public static function find( int $term_id, string $taxonomy ) {
		$term = $term_id > 0 ? get_term( $term_id, $taxonomy ) : null;
		if ( ! $term || is_wp_error( $term ) || ! is_object( $term ) || (string) $term->taxonomy !== $taxonomy ) {
			return null;
		}
		return $term;
	}

	/**
	 * The term if it exists in the taxonomy and the user has the meta capability on it, or one refusal for all cases.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $cap      `edit_term` or `delete_term`.
	 * @return object|Assessment
	 */
	public static function allowed( int $term_id, string $taxonomy, string $cap ) {
		$term = self::find( $term_id, $taxonomy );
		if ( null === $term || ! current_user_can( $cap, $term_id ) ) {
			return self::not_found( $term_id, $taxonomy, 'delete_term' === $cap ? 'delete' : 'edit' );
		}
		return $term;
	}

	/**
	 * The refusal for a missing term or one the user may not change (same message for both).
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $verb     `edit` or `delete`.
	 * @return Assessment
	 */
	public static function not_found( int $term_id, string $taxonomy, string $verb ): Assessment {
		return Assessment::prohibited( 'not_found', sprintf( 'Term %d was not found in %s or you cannot %s it.', $term_id, self::named_taxonomy( $taxonomy ), $verb ) );
	}

	/**
	 * Throws when a parent is not allowed: non-hierarchical taxonomy, missing parent, the term itself or a descendant.
	 *
	 * @param object $taxonomy Taxonomy object.
	 * @param int    $parent_id   Parent term ID (0 for none).
	 * @param int    $term_id  Term being changed, or 0 for a new term.
	 * @throws ToolError When the parent is not allowed.
	 */
	public static function check_parent( $taxonomy, int $parent_id, int $term_id ): void {
		if ( 0 === $parent_id ) {
			return;
		}
		if ( empty( $taxonomy->hierarchical ) ) {
			throw new ToolError( sprintf( '%s is not hierarchical, so its terms cannot have a parent.', esc_textarea( ucfirst( self::named_taxonomy( $taxonomy ) ) ) ) );
		}
		if ( $parent_id === $term_id ) {
			throw new ToolError( 'A term cannot be its own parent.' );
		}
		if ( null === self::find( $parent_id, (string) $taxonomy->name ) ) {
			throw new ToolError( sprintf( 'Parent term %d was not found in %s.', (int) $parent_id, esc_textarea( self::named_taxonomy( $taxonomy ) ) ) );
		}
		if ( $term_id > 0 && in_array( $term_id, array_map( 'intval', (array) get_ancestors( $parent_id, (string) $taxonomy->name, 'taxonomy' ) ), true ) ) {
			throw new ToolError( sprintf( 'Term %d is a descendant of term %d, so it cannot become its parent.', (int) $parent_id, (int) $term_id ) );
		}
	}

	/**
	 * Name, slug, description and parent of a term.
	 *
	 * @param object $term Term.
	 * @return array{name: string, slug: string, description: string, parent: int}
	 */
	public static function snapshot( $term ): array {
		return array(
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => (int) $term->parent,
		);
	}

	/**
	 * Fingerprint of a term's fields.
	 *
	 * @param object $term Term.
	 * @return string
	 */
	public static function fingerprint( $term ): string {
		return Canonical::hash( array( (string) $term->taxonomy, (int) $term->term_id, self::snapshot( $term ) ) );
	}

	/**
	 * IDs of the objects (posts of any status) attached to a term, sorted.
	 *
	 * @param object $term Term.
	 * @return int[]
	 */
	public static function objects( $term ): array {
		$ids = get_objects_in_term( (int) $term->term_id, (string) $term->taxonomy );
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		sort( $ids );
		return $ids;
	}

	/**
	 * Fingerprint of a term's fields and the objects attached to it.
	 *
	 * @param object $term Term.
	 * @return string
	 */
	public static function usage_fingerprint( $term ): string {
		return Canonical::hash( array( self::fingerprint( $term ), self::objects( $term ) ) );
	}

	/**
	 * The term WordPress gives objects left without a term in the taxonomy when a term is deleted, or 0.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return int
	 */
	public static function default_term_id( string $taxonomy ): int {
		return (int) get_option( 'category' === $taxonomy ? 'default_category' : 'default_term_' . $taxonomy, 0 );
	}

	/**
	 * Fields of `$wanted` that the term stores differently.
	 *
	 * @param object               $term   Term read back.
	 * @param array<string, mixed> $wanted Field => requested value.
	 * @return string[]
	 */
	public static function differs( $term, array $wanted ): array {
		$stored  = self::snapshot( $term );
		$differs = array();
		foreach ( $wanted as $field => $value ) {
			if ( (string) $stored[ $field ] !== (string) $value ) {
				$differs[] = $field;
			}
		}
		return $differs;
	}

	/**
	 * A taxonomy as messages name it: its singular label in lower case, with the slug in parentheses when the two
	 * differ ("tag (post_tag)", "category"), or the slug when it has no label.
	 *
	 * @param object|string $taxonomy Taxonomy object or name.
	 * @return string
	 */
	public static function name( $taxonomy ): string {
		$object = is_object( $taxonomy ) ? $taxonomy : get_taxonomy( (string) $taxonomy );
		$slug   = is_object( $taxonomy ) ? (string) $taxonomy->name : (string) $taxonomy;
		$label  = is_object( $object ) && isset( $object->labels->singular_name ) ? strtolower( trim( (string) $object->labels->singular_name ) ) : '';
		return '' === $label || $label === $slug ? $slug : sprintf( '%s (%s)', $label, $slug );
	}

	/**
	 * A taxonomy as a place terms are in: "the tag (post_tag) taxonomy".
	 *
	 * @param object|string $taxonomy Taxonomy object or name.
	 * @return string
	 */
	public static function named_taxonomy( $taxonomy ): string {
		return sprintf( 'the %s taxonomy', self::name( $taxonomy ) );
	}

	/**
	 * Singular label of a taxonomy, lower case.
	 *
	 * @param object $taxonomy Taxonomy object.
	 * @return string
	 */
	public static function label( $taxonomy ): string {
		return isset( $taxonomy->labels->singular_name ) ? strtolower( (string) $taxonomy->labels->singular_name ) : (string) $taxonomy->name;
	}

	/**
	 * What a taxonomy's terms are attached to, as summaries say it: posts when it is only on posts and pages, items
	 * otherwise (products, custom types, users).
	 *
	 * @param object|false $taxonomy Taxonomy object.
	 * @param bool         $plural   Plural.
	 * @return string
	 */
	public static function item_noun( $taxonomy, bool $plural ): string {
		$types = $taxonomy && isset( $taxonomy->object_type ) ? (array) $taxonomy->object_type : array();
		$posts = array() !== $types && array() === array_diff( $types, array( 'post', 'page' ) );
		if ( $posts ) {
			return $plural ? 'posts' : 'post';
		}
		return $plural ? 'items' : 'item';
	}
}
