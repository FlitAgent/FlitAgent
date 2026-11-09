<?php
/**
 * Smaller views of a page's HTML for get_page_html: a byte cut, the visible text, and a summary.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Http;

use FlitAgent\Tools\SiteText;

defined( 'ABSPATH' ) || exit;

/**
 * Pure string functions over fetched HTML; no WordPress calls, no requests.
 */
final class PageDigest {

	/**
	 * Elements whose content is never visible text. `script` and `style` run to the end of the page when unclosed.
	 */
	const HIDDEN = 'noscript|template|svg|iframe|canvas|title';

	/**
	 * The rest of an svg's opening tag, as a browser reads attributes: a quote opens a value only straight after a name's `=`,
	 * an unquoted value runs to whitespace or `>`, and a `=` where a name starts belongs to the name. A quote with no close
	 * ends the match, because a browser reads the rest of the page as that value. Group 1 is the `/` of a tag that closes itself.
	 */
	const SVG_TAG_REST = '/\G(?:\s++|\/(?!>)|(?:=[^\s\/>=]*+|[^\s\/>=]++)(?:\s*+=\s*+(?:"[^"]*+"|\'[^\']*+\'|(?!["\'])[^\s>]*+)|(?!\s*+=)))*+(\/?)>/';

	/**
	 * What follows a whole element name: `<svg-icon>` is another element.
	 */
	const NAME_END = '(?=[\s/>])';

	/**
	 * A comment: `<!-->` and `<!--->` end at once, and one with no close runs to the end, as in a browser.
	 */
	const COMMENT = '/<!--(?:-?>|.*?(?:--!?>|\z))/s';

	/**
	 * A script or style, with what it holds.
	 */
	const SCRIPT_OR_STYLE = '#<(script|style)(?=[\s/>])[^>]*>.*?(?:</\1(?=[\s/>])[^>]*>|\z)#is';

	/**
	 * Most headings in a summary.
	 */
	const MAX_HEADINGS = 100;

	/**
	 * Largest heading text in a summary, in bytes.
	 */
	const HEADING_BYTES = 200;

	/**
	 * Largest title or meta description in a summary, in bytes.
	 */
	const TEXT_BYTES = 300;

	/**
	 * Elements that start a new line of text.
	 */
	const BLOCKS = 'address|article|aside|blockquote|body|dd|details|div|dl|dt|fieldset|figcaption|figure|footer|form|h[1-6]|header|hr|html|li|main|nav|ol|p|pre|section|summary|table|tbody|tfoot|thead|tr|ul';

	/**
	 * The page's visible text: no comments, scripts, styles or other hidden elements, no tags, entities decoded,
	 * one line per block element and whitespace collapsed.
	 *
	 * @param string $html HTML.
	 * @return string|null Null when a pattern failed (e.g. a PCRE limit).
	 */
	public static function text( string $html ): ?string {
		$html = preg_replace( self::COMMENT, '', $html );
		$html = null === $html ? null : preg_replace( self::SCRIPT_OR_STYLE, '', SiteText::without_open_ends( $html ) );
		$html = null === $html ? null : self::without_closed_head( $html );
		$html = null === $html ? null : self::without_self_closed_svgs( $html );
		if ( null === $html ) {
			return null;
		}

		$rules = array(
			'#<(' . self::HIDDEN . ')(?=[\s/>])[^>]*>.*?(?:</\1(?=[\s/>])[^>]*>|\z)#is' => '',
			'#<br\b[^>]*>#i'                         => "\n",
			'#</?(?:' . self::BLOCKS . ')\b[^>]*>#i' => "\n\n",
			'#</?(?:td|th)\b[^>]*>#i'                => ' ',
			'#<[^>]*>#'                              => '',
		);
		foreach ( $rules as $pattern => $replacement ) {
			$html = preg_replace( $pattern, $replacement, $html );
			if ( null === $html ) {
				return null;
			}
		}

		$text = str_replace( "\xC2\xA0", ' ', html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$text = (string) preg_replace( '/[ \t\r\f]+/', ' ', $text );
		$text = (string) preg_replace( '/ ?\n ?/', "\n", $text );
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Takes out every svg that closes itself, which, unlike the other hidden elements, an svg can do. Its opening tag ends at
	 * the first `>` outside a quoted attribute value: `<svg aria-label="Next >"/>` closes itself and `<svg a="/>">` doesn't.
	 * A `/` closes it only between attributes: as a browser reads them, `<svg a=b/>` holds the value `b/` and stays open.
	 * Each tag is read once, and one with no end means the page's last `>` is in a quote, so no tag follows.
	 *
	 * @param string $html HTML.
	 * @return string|null Null when a pattern failed.
	 */
	private static function without_self_closed_svgs( string $html ): ?string {
		$kept = '';
		$at   = 0;
		$open = array();
		for ( $found = preg_match( '#<svg(?=[\s/>])#i', $html, $open, PREG_OFFSET_CAPTURE, $at ); 1 === $found; $found = preg_match( '#<svg(?=[\s/>])#i', $html, $open, PREG_OFFSET_CAPTURE, $at ) ) {
			$start = $open[0][1];
			$inner = $start + 4;
			$ended = preg_match( self::SVG_TAG_REST, $html, $tag, 0, $inner );
			if ( 1 !== $ended ) {
				return false === $ended ? null : $kept . substr( $html, $at );
			}
			$end   = $inner + strlen( $tag[0] );
			$kept .= substr( $html, $at, ( '/' === $tag[1] ? $start : $end ) - $at );
			$at    = $end;
		}
		return false === $found ? null : $kept . substr( $html, $at );
	}

	/**
	 * Takes out what a closed head holds. A head need not be closed: it then ends at the first thing that can't be in one, so
	 * nothing is taken out for it.
	 *
	 * @param string $html HTML.
	 * @return string|null Null when a pattern failed.
	 */
	private static function without_closed_head( string $html ): ?string {
		$parts = self::elements( $html, 'head' );
		if ( null === $parts ) {
			return null;
		}
		$kept = '';
		$at   = 0;
		foreach ( $parts as $part ) {
			$kept .= substr( $html, $at, $part['start'] - $at );
			$at    = $part['end'];
		}
		return $kept . substr( $html, $at );
	}

	/**
	 * Every closed element of some names, in order: each opening is looked for, then its close. A name with no close left is
	 * not looked for again, so the time grows with the length of the page.
	 *
	 * @param string $html HTML.
	 * @param string $name Pattern for the name.
	 * @return array<int, array{start: int, end: int, name: string, inner: string}>|null Null when a pattern failed.
	 */
	private static function elements( string $html, string $name ): ?array {
		$opening = '#<(' . $name . ')' . self::NAME_END . '[^>]*+>#i';
		$found   = array();
		$none    = array();
		$at      = 0;
		$tag     = array();
		for ( $hit = preg_match( $opening, $html, $tag, PREG_OFFSET_CAPTURE, $at ); 1 === $hit; $hit = preg_match( $opening, $html, $tag, PREG_OFFSET_CAPTURE, $at ) ) {
			$element = strtolower( $tag[1][0] );
			$inner   = $tag[0][1] + strlen( $tag[0][0] );
			$at      = $inner;
			if ( isset( $none[ $element ] ) ) {
				continue;
			}
			$closed = preg_match( '#</' . $element . self::NAME_END . '[^>]*+>#i', $html, $close, PREG_OFFSET_CAPTURE, $inner );
			if ( false === $closed ) {
				return null;
			}
			if ( 0 === $closed ) {
				$none[ $element ] = true;
				continue;
			}
			$at      = $close[0][1] + strlen( $close[0][0] );
			$found[] = array(
				'start' => $tag[0][1],
				'end'   => $at,
				'name'  => $element,
				'inner' => substr( $html, $inner, $close[0][1] - $inner ),
			);
		}//end for
		return false === $hit ? null : $found;
	}

	/**
	 * A small outline of the page: title, meta description, headings and counts of links and assets.
	 *
	 * Headings inside comments, scripts, styles and templates are ignored; empty headings are skipped. At most
	 * MAX_HEADINGS headings of HEADING_BYTES each, and a title and description of TEXT_BYTES each, are kept; `truncated`
	 * says whether anything was left out.
	 *
	 * @param string   $html      HTML.
	 * @param callable $link_kind Maps an href to 'internal', 'external' or 'other'.
	 * @return array<string, mixed>|null Null when a pattern failed (e.g. a PCRE limit).
	 */
	public static function summary( string $html, callable $link_kind ): ?array {
		$html = preg_replace( self::COMMENT, '', $html );
		if ( null === $html ) {
			return null;
		}
		$html    = SiteText::without_open_ends( $html );
		$scripts = (int) preg_match_all( '#<script' . self::NAME_END . '#i', $html );
		$html    = preg_replace( array( self::SCRIPT_OR_STYLE, '#<template(?=[\s/>])[^>]*>.*?(?:</template(?=[\s/>])[^>]*>|\z)#is' ), '', $html );
		if ( null === $html ) {
			return null;
		}

		$truncated = false;
		$titles    = self::elements( $html, 'title' );
		$headings  = self::elements( $html, 'h[1-6]' );
		if ( null === $titles || null === $headings ) {
			return null;
		}
		$title       = array() === $titles ? '' : self::inline( $titles[0]['inner'] );
		$description = '';
		foreach ( self::tags( 'meta', $html ) as $attributes ) {
			if ( 'description' === strtolower( trim( $attributes['name'] ?? '' ) ) ) {
				$description = self::one_line( $attributes['content'] ?? '' );
				break;
			}
		}

		$found    = $headings;
		$headings = array();
		foreach ( $found as $heading ) {
			$text = self::inline( $heading['inner'] );
			if ( '' === $text ) {
				continue;
			}
			if ( count( $headings ) >= self::MAX_HEADINGS ) {
				$truncated = true;
				break;
			}
			$headings[] = array(
				'level' => (int) substr( $heading['name'], 1 ),
				'text'  => self::cut( $text, self::HEADING_BYTES ),
			);
			$truncated  = $truncated || strlen( $text ) > self::HEADING_BYTES;
		}

		$counts = array(
			'links'          => 0,
			'internal_links' => 0,
			'external_links' => 0,
			'images'         => (int) preg_match_all( '#<img\b#i', $html ),
			'scripts'        => $scripts,
			'stylesheets'    => 0,
		);
		foreach ( self::tags( 'a', $html ) as $attributes ) {
			if ( isset( $attributes['href'] ) ) {
				++$counts['links'];
				$kind = $link_kind( $attributes['href'] );
				if ( 'internal' === $kind || 'external' === $kind ) {
					++$counts[ $kind . '_links' ];
				}
			}
		}
		foreach ( self::tags( 'link', $html ) as $attributes ) {
			if ( in_array( 'stylesheet', preg_split( '/\s+/', strtolower( $attributes['rel'] ?? '' ) ), true ) ) {
				++$counts['stylesheets'];
			}
		}

		return array(
			'title'            => self::cut( $title, self::TEXT_BYTES ),
			'meta_description' => self::cut( $description, self::TEXT_BYTES ),
			'headings'         => $headings,
			'counts'           => $counts,
			'truncated'        => $truncated || strlen( $title ) > self::TEXT_BYTES || strlen( $description ) > self::TEXT_BYTES,
		);
	}

	/**
	 * The attributes of every opening tag with this name.
	 *
	 * @param string $name Tag name.
	 * @param string $html HTML.
	 * @return array<int, array<string, string>> Lower-case attribute names to decoded values; the first of a name wins.
	 */
	private static function tags( string $name, string $html ): array {
		preg_match_all( '#<' . $name . '\b([^>]*)>#i', $html, $found );
		$tags = array();
		foreach ( $found[1] as $inside ) {
			preg_match_all( '/([^\s"\'<>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $inside, $pairs, PREG_SET_ORDER );
			$attributes = array();
			foreach ( $pairs as $pair ) {
				$key = strtolower( $pair[1] );
				if ( ! isset( $attributes[ $key ] ) ) {
					$value              = ( $pair[2] ?? '' ) . ( $pair[3] ?? '' ) . ( $pair[4] ?? '' );
					$attributes[ $key ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}
			$tags[] = $attributes;
		}
		return $tags;
	}

	/**
	 * Inline HTML as one line of plain text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function inline( string $html ): string {
		$text = (string) preg_replace( array( '#<br\b[^>]*>#i', '#<[^>]*>#' ), array( ' ', '' ), $html );
		return self::one_line( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Decoded text as one line. What reads as a tag here is words the page shows, such as `The <div> element`: defang() keeps
	 * them through the cleaning every read tool's result gets.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function one_line( string $text ): string {
		$text = str_replace( "\xC2\xA0", ' ', $text );
		return SiteText::defang( trim( (string) preg_replace( '/\s+/', ' ', $text ) ) );
	}

	/**
	 * Cuts text to at most $max bytes without splitting a UTF-8 character, so the result still encodes as JSON.
	 *
	 * @param string $text Text.
	 * @param int    $max  Largest length, in bytes.
	 * @return string
	 */
	public static function cut( string $text, int $max ): string {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = substr( $text, 0, $max );
		// Walk back over continuation bytes to the last character's first byte and drop that character if incomplete.
		for ( $i = $max - 1; $i >= 0 && $i >= $max - 4; $i-- ) {
			$byte = ord( $cut[ $i ] );
			if ( $byte < 0x80 ) {
				return $cut;
			}
			if ( $byte >= 0xC0 ) {
				$length = $byte >= 0xF0 ? 4 : ( $byte >= 0xE0 ? 3 : 2 );
				return $i + $length <= $max ? $cut : substr( $cut, 0, $i );
			}
		}
		return $cut;
	}
}
