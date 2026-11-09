<?php
/**
 * Block markup checks.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Reads block delimiters (`<!-- wp:name {attrs} -->`, `<!-- /wp:name -->`, `<!-- wp:name /-->`) without WordPress, to
 * refuse text edits that remove blocks or break block markup. Stricter than core's parser, which tolerates some broken
 * markup by turning it into freeform HTML: an edit is refused only when it makes valid markup invalid.
 */
final class Blocks {

	/**
	 * Any block delimiter, including malformed attributes.
	 */
	const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<name>(?:[a-z][a-z0-9_-]*\/)?[a-z][a-z0-9_-]*)(?P<attrs>.*?)\s*(?P<void>\/)?-->/s';

	/**
	 * Number of blocks (openers and void blocks).
	 *
	 * @param string $content Content.
	 * @return int
	 */
	public static function count( string $content ): int {
		$count = 0;
		foreach ( self::delimiters( $content ) as $delimiter ) {
			if ( '' === $delimiter['closer'] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Whether the markup is valid: attributes are JSON objects, and every opener has a matching closer in order.
	 *
	 * @param string $content Content.
	 * @return bool
	 */
	public static function is_valid( string $content ): bool {
		$open = array();
		foreach ( self::delimiters( $content ) as $delimiter ) {
			$attrs = trim( $delimiter['attrs'] );
			if ( '' !== $attrs ) {
				$decoded = json_decode( $attrs, true );
				if ( '{' !== $attrs[0] || ! is_array( $decoded ) ) {
					return false;
				}
			}
			if ( '' !== $delimiter['closer'] ) {
				if ( '' !== $attrs || array_pop( $open ) !== $delimiter['name'] ) {
					return false;
				}
			} elseif ( '' === $delimiter['void'] ) {
				$open[] = $delimiter['name'];
			}
		}
		return array() === $open;
	}

	/**
	 * Why replacing `$before` with `$after` would damage the blocks, or null.
	 *
	 * @param string $before Content before.
	 * @param string $after  Content after.
	 * @return string|null
	 */
	public static function damage( string $before, string $after ): ?string {
		$was = self::count( $before );
		$now = self::count( $after );
		if ( $now < $was ) {
			return sprintf( 'The edit would leave fewer blocks (%d instead of %d); edit block content, not block delimiters.', $now, $was );
		}
		if ( self::is_valid( $before ) && ! self::is_valid( $after ) ) {
			return 'The edit would leave invalid block markup (unbalanced block comments or block attributes that are not JSON).';
		}
		return null;
	}

	/**
	 * Delimiters in order.
	 *
	 * @param string $content Content.
	 * @return array<int, array{closer: string, name: string, attrs: string, void: string}>
	 */
	private static function delimiters( string $content ): array {
		if ( false === strpos( $content, 'wp:' ) ) {
			return array();
		}
		preg_match_all( self::DELIMITER, $content, $matches, PREG_SET_ORDER );
		$found = array();
		foreach ( $matches as $match ) {
			$found[] = array(
				'closer' => $match['closer'],
				'name'   => $match['name'],
				'attrs'  => $match['attrs'],
				'void'   => isset( $match['void'] ) ? $match['void'] : '',
			);
		}
		return $found;
	}
}
