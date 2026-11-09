<?php
/**
 * Plain-English pieces of a change's summary.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * How the update tools word their summaries (e2e 2026-10-30, M7): fields joined as "title, slug and excerpt" and an
 * object named by its title in quotes, or by its kind and id when it has none, so a summary reads
 * `Update the title and excerpt of "Opening hours"`. The summary is what an administrator reads in Approvals and
 * Activity; the exact ids stay in the preview and the arguments. WordPress-free.
 */
final class Summary {

	/**
	 * Longest title quoted, in characters; a longer one is cut and ends with "...".
	 */
	const MAX_TITLE = 80;

	/**
	 * Field names as words, joined with commas and a final "and": "title", "title and excerpt",
	 * "title, alt text and caption". Underscores read as spaces.
	 *
	 * @param string[] $fields Field or argument names, in order.
	 * @return string
	 */
	public static function fields( array $fields ): string {
		return self::join(
			array_map(
				static function ( $field ): string {
					return str_replace( '_', ' ', (string) $field );
				},
				$fields
			)
		);
	}

	/**
	 * Values as written, joined with commas and a final "and": "News", "News and Events", "a, b and c".
	 *
	 * @param array<int|string, mixed> $values Values, in order.
	 * @return string
	 */
	public static function join( array $values ): string {
		$words = array_map( 'strval', array_values( $values ) );
		$last  = array_pop( $words );
		if ( null === $last ) {
			return '';
		}
		return array() === $words ? $last : implode( ', ', $words ) . ' and ' . $last;
	}

	/**
	 * An object as a summary names it: its title in double quotes (shortened), or the fallback, such as "post 11", when
	 * the title is empty or only spaces.
	 *
	 * @param string $title    Title.
	 * @param string $fallback What to say when there is no title.
	 * @return string
	 */
	public static function named( string $title, string $fallback ): string {
		return '' === trim( $title ) ? $fallback : '"' . self::short( $title ) . '"';
	}

	/**
	 * An object as a destructive summary names it: its kind, its quoted title and its ID (`the page "Home" (ID 2)`),
	 * or `the page with ID 2` without a title. Titles are free text and Approvals shows only the summary, so what cannot
	 * be undone is always named unambiguously.
	 *
	 * @param string $kind  Kind, such as page or attachment.
	 * @param string $title Title.
	 * @param int    $id    ID.
	 * @return string
	 */
	public static function named_with_id( string $kind, string $title, int $id ): string {
		return '' === trim( $title ) ? sprintf( 'the %s with ID %d', $kind, $id ) : sprintf( 'the %s "%s" (ID %d)', $kind, self::short( $title ), $id );
	}

	/**
	 * " This changes the live page." when the post the write changes is published (it goes live at once); '' otherwise.
	 *
	 * @param string $status The post's status.
	 * @param string $kind   Kind, such as page or post.
	 * @return string '' or a sentence, with a leading space.
	 */
	public static function live_note( string $status, string $kind ): string {
		return 'publish' === $status ? sprintf( ' This changes the live %s.', $kind ) : '';
	}

	/**
	 * A count with its noun in the singular or the plural: "1 revision", "2 revisions", "0 revisions". Summaries and
	 * findings are English by design (receipts keep them and the model reads them), so this is not translated.
	 *
	 * @param int    $n    Count.
	 * @param string $one  Singular noun.
	 * @param string $many Plural noun.
	 * @return string
	 */
	public static function count( int $n, string $one, string $many ): string {
		return $n . ' ' . ( 1 === $n ? $one : $many );
	}

	/**
	 * A title cut to MAX_TITLE characters, with "..." when it was longer.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function short( string $title ): string {
		preg_match( '/^.{0,' . self::MAX_TITLE . '}/su', $title, $match );
		$short = isset( $match[0] ) ? $match[0] : '';
		return $short === $title ? $title : $short . '...';
	}
}
