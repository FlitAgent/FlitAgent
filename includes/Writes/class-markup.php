<?php
/**
 * Whether text from an AI app can be stored without a person seeing it.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * A signed request runs as an administrator, and core stops filtering posts for a user with `unfiltered_html`, so a write
 * can store script, event handlers and frames as they were sent. A write that policy would apply without a person may
 * store only what is_safe() passes: anything else waits in Approvals, where a person sees it. It compares and never
 * rewrites: an approved write stores what was approved.
 *
 * Core's kses judges text printed as the body of a page, so this is only for such text. Values printed elsewhere (post
 * meta, a page builder's settings) cannot be judged this way, and those tools never auto-approve.
 *
 * The string judged is not always the string that reaches the page: core strips tags from term fields and alt text, removes
 * blocks that render to nothing and drops others from excerpts, and decodes entities along many routes. So a value passes
 * as sent, decoded and with its tags stripped; text beside a block's comment waits, because removing the block joins it to
 * whatever is on the other side; and is_safe_post() and is_safe_term() also ask core what it would store, and a value it
 * would change waits. Two kinds of text have kept finding new routes, so neither is judged by its shape: block attributes must
 * be plain (is_plain()), and no square bracket may appear at all (has_bracket()). Anything this cannot judge waits: the cost of
 * a false hold is one approval.
 */
final class Markup {

	/**
	 * Core blocks whose render callback only adds a class to what the block saved, so that kses has seen what they print. Other
	 * dynamic core blocks read their attributes at render or print content from elsewhere, and are held back: core/button
	 * prints its width into a style attribute unfiltered, and core/image prints attachment data by id.
	 */
	const CHECKED_DYNAMIC_BLOCKS = array( 'core/heading', 'core/list' );

	/**
	 * Decoding stops here, and a value still changing after this many passes waits.
	 */
	const MAX_DECODES = 5;

	/**
	 * What a block attribute may hold: see is_plain().
	 */
	const PLAIN = '/^[A-Za-z0-9 _\-#%.,]*\z/';

	/**
	 * Whether a string, or every string in a list, map or object at any depth (their keys too), may be printed as the body
	 * of a page: wp_kses_post() leaves it unchanged, also once entities are decoded, and nothing in it acts at render as sent,
	 * decoded or with its tags stripped (see runs_at_render()).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_safe( $value ): bool {
		if ( is_string( $value ) ) {
			return self::string_is_safe( $value );
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $key => $item ) {
				if ( ! self::is_safe( (string) $key ) || ! self::is_safe( $item ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Whether post fields (`post_title` => value) pass is_safe(), and core's save filters would store the text fields as sent.
	 * They may change a value in ways nobody predicted, and an approver sees what was sent, so a change waits.
	 *
	 * @param array<string, mixed> $fields Post fields.
	 * @return bool
	 */
	public static function is_safe_post( array $fields ): bool {
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			if ( isset( $fields[ $field ] ) && wp_unslash( sanitize_post_field( $field, wp_slash( (string) $fields[ $field ] ), 0, 'db' ) ) !== (string) $fields[ $field ] ) {
				return false;
			}
		}
		return self::is_safe( $fields );
	}

	/**
	 * Whether term fields pass is_safe(), and core's save filters would store the name and description as sent. Core strips tags
	 * from both for every user, so `[<b>name]` would be stored as `[name]`.
	 *
	 * @param array<string, mixed> $fields   Term fields.
	 * @param string               $taxonomy Taxonomy.
	 * @return bool
	 */
	public static function is_safe_term( array $fields, string $taxonomy ): bool {
		foreach ( array( 'name', 'description' ) as $field ) {
			if ( isset( $fields[ $field ] ) && wp_unslash( sanitize_term_field( $field, wp_slash( (string) $fields[ $field ] ), 0, $taxonomy, 'db' ) ) !== (string) $fields[ $field ] ) {
				return false;
			}
		}
		return self::is_safe( $fields );
	}

	/**
	 * Whether one string passes, in each form core can turn it into.
	 *
	 * @param string $value String.
	 * @return bool
	 */
	private static function string_is_safe( string $value ): bool {
		if ( wp_kses_post( $value ) !== $value ) {
			return false;
		}
		$decoded = $value;
		for ( $pass = 0; $pass < self::MAX_DECODES; $pass++ ) {
			$next = html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $next === $decoded ) {
				break;
			}
			$decoded = $next;
		}
		if ( self::MAX_DECODES === $pass ) {
			return false;
		}
		// Decoded, a lone `&` is ordinary and kses would only encode it again, as it was sent.
		if ( str_replace( '&amp;', '&', wp_kses_post( $decoded ) ) !== str_replace( '&amp;', '&', $decoded ) ) {
			return false;
		}
		foreach ( array_unique( array( $value, $decoded, wp_strip_all_tags( $value ), wp_strip_all_tags( $decoded ) ) ) as $form ) {
			if ( self::runs_at_render( $form ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a string holds anything that acts at render, which kses cannot see: a data attribute, a style that moves an element,
	 * a thickbox link, an address core would embed, any square bracket, text beside a block's comment, a comment shaped
	 * like a block that core would not parse as one, or a block that renders through code or values it has not seen.
	 *
	 * @param string $value String.
	 * @return bool
	 */
	private static function runs_at_render( string $value ): bool {
		return self::has_data_attribute( $value )
			|| self::has_moving_style( $value )
			|| self::has_thickbox( $value )
			|| self::would_embed( $value )
			|| self::has_bracket( $value )
			|| self::has_text_beside_block( $value )
			|| self::has_odd_block_comment( $value )
			|| ( false !== stripos( $value, 'wp:' ) && self::has_unchecked_block( parse_blocks( $value ) ) );
	}

	/**
	 * Whether a tag has a `data-` attribute, whatever its case. Kses keeps these, and they are not inert: the Interactivity API
	 * turns `data-wp-*` into attributes and links at render and in the browser, and other scripts read other names. By the
	 * text, so it needs nothing newer than the oldest WordPress the plugin runs on. Words in the page are left alone: is_safe()
	 * calls this only for text kses left unchanged, so a tag holds no `>` before its end.
	 *
	 * @param string $value String.
	 * @return bool
	 */
	private static function has_data_attribute( string $value ): bool {
		return 0 !== preg_match( '/<[^>]*(?<![a-z0-9_-])data-/i', $value );
	}

	/**
	 * Whether a tag has a style attribute mentioning `position` or `transform`, which can lay a link over a whole page. Kses keeps
	 * them on recent versions of WordPress. Also catches `background-position`, `text-transform` and the like, which then cost
	 * one approval.
	 *
	 * @param string $value String.
	 * @return bool
	 */
	private static function has_moving_style( string $value ): bool {
		return 0 !== preg_match( '/<[^>]*(?<![a-z0-9_-])style\s*=[^>]*(?:position|transform)/i', $value );
	}

	/**
	 * Whether a tag or a block's comment mentions `thickbox`. Core's script opens a link with that class in a frame, or loads it
	 * into the page and runs what comes back. Words in the page are left alone, as in has_data_attribute().
	 *
	 * @param string $value String.
	 * @return bool
	 */
	private static function has_thickbox( string $value ): bool {
		return 0 !== preg_match( '/<[^>]*thickbox/i', $value );
	}

	/**
	 * Whether core would turn part of a string into an embed, by the two patterns of WP_Embed::autoembed(): an address alone
	 * on its line, or alone in a paragraph. The server then fetches the address at render and prints what it answers.
	 * WP_Embed also hides line breaks inside tags first, which only makes it match less.
	 *
	 * @param string $value String.
	 * @return bool
	 */
	private static function would_embed( string $value ): bool {
		return 0 !== preg_match( '|^\s*https?://[^\s<>"]+\s*$|im', $value )
			|| 0 !== preg_match( '|<p(?: [^>]*)?>\s*https?://[^\s<>"]+\s*</p>|i', $value );
	}

	/**
	 * Whether a string holds any `[` or `]`, anywhere. Not by what looks like a shortcode, because core takes more than that for
	 * one: it prints separate values into one page before it runs shortcodes, so `[name` in one term or caption joins a `]` from
	 * another; it removes tags and blocks, and joins what was on either side; and a plugin can register any name, even `123`, on
	 * a later hook, so the registry now says nothing about what runs at render. A comment is no exception: it is text wherever
	 * core does not parse it, and `[123]` is valid JSON in a block's attributes.
	 *
	 * @param string $value String.
	 * @return bool
	 */
	private static function has_bracket( string $value ): bool {
		return false !== strpbrk( $value, '[]' );
	}

	/**
	 * Whether text other than a tag touches a block's comment. The editor saves only tags and whitespace there, and core removes
	 * blocks that render to nothing and drops most blocks from excerpts, which joins what was on either side: an address
	 * would then stand alone and be embedded.
	 *
	 * @param string $value String.
	 * @return bool
	 */
	private static function has_text_beside_block( string $value ): bool {
		return 0 !== preg_match( '/[^\s>]\s*<!--\s+\/?wp:/', $value )
			|| 0 !== preg_match( '/<!--\s+\/?wp:(?:(?!-->).)*-->\s*[^\s<]/s', $value );
	}

	/**
	 * Whether any comment shaped like a block's is not exactly what core's parser takes: its name and attributes by core's grammar,
	 * attributes only on an opener and decoding as JSON, every opener closed by its own name and no closer without an opener.
	 * Otherwise core stops parsing, or leaves the comment in the page as text, so has_unchecked_block() never sees what is in it
	 * and a renderer that escapes it frees whatever it holds.
	 *
	 * @param string $value String.
	 * @return bool
	 */
	private static function has_odd_block_comment( string $value ): bool {
		if ( false === stripos( $value, 'wp:' ) ) {
			return false;
		}
		if ( false === preg_match_all( '/<!--\s*\/?\s*wp:(?:(?!-->).)*(?:-->|\z)/is', $value, $comments ) ) {
			return true;
		}
		$open = array();
		foreach ( $comments[0] as $comment ) {
			if ( 1 !== preg_match( '/^<!--[ \t\f\r\n]+(\/)?wp:((?:[a-z][a-z0-9_-]*\/)?[a-z][a-z0-9_-]*)[ \t\f\r\n]+(?:(\{.*\})[ \t\f\r\n]+)?(\/)?-->$/sD', $comment, $parts ) ) {
				return true;
			}
			$closer = '' !== $parts[1];
			$attrs  = $parts[3] ?? '';
			$void   = '' !== ( $parts[4] ?? '' );
			$name   = 0 === strpos( $parts[2], 'core/' ) ? substr( $parts[2], 5 ) : $parts[2];
			if ( '' !== $attrs && ( $closer || ! is_array( json_decode( $attrs, true ) ) ) ) {
				return true;
			}
			if ( $closer && ( $void || array_pop( $open ) !== $name ) ) {
				return true;
			}
			if ( ! $closer && ! $void ) {
				$open[] = $name;
			}
		}
		return array() !== $open;
	}

	/**
	 * Whether any block, at any depth, has attributes that are not plain or that core acts on, is an embed, is unregistered, or
	 * renders through a callback not on CHECKED_DYNAMIC_BLOCKS. Attributes come first because they apply to any block, static or
	 * not: bindings replace a block's values, `style.css` becomes a stylesheet, `style.position` needs no unusual character to
	 * move a block, other values are printed into styles, and a list where core expects a string crashes rendering.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return bool
	 */
	private static function has_unchecked_block( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			$name     = $block['blockName'] ?? null;
			$attrs    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$metadata = $attrs['metadata'] ?? null;
			$style    = $attrs['style'] ?? null;
			if (
				'core/embed' === $name
				|| ( is_array( $metadata ) && array_key_exists( 'bindings', $metadata ) )
				|| ( is_array( $style ) && ( array_key_exists( 'css', $style ) || array_key_exists( 'position', $style ) ) )
				|| ( array_key_exists( 'tagName', $attrs ) && ! is_string( $attrs['tagName'] ) )
				|| ( is_array( $attrs['layout'] ?? null ) && array() !== array_filter( $attrs['layout'], 'is_array' ) )
				|| ! self::is_plain( $attrs )
			) {
				return true;
			}
			if ( is_string( $name ) && ! in_array( $name, self::CHECKED_DYNAMIC_BLOCKS, true ) ) {
				$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
				if ( ! $type || $type->is_dynamic() ) {
					return true;
				}
			}
			if ( self::has_unchecked_block( $block['innerBlocks'] ?? array() ) ) {
				return true;
			}
		}//end foreach
		return false;
	}

	/**
	 * Whether every key and string in parsed block attributes, at any depth, holds only letters, digits, spaces and `_-#%.,`.
	 * Core prints these values into styles and then runs shortcodes over the page, so anything else waits: this allows what is
	 * known to be plain, rather than listing what is not. Numbers, booleans and null say nothing.
	 *
	 * @param array<mixed> $attrs Attributes.
	 * @return bool
	 */
	private static function is_plain( array $attrs ): bool {
		foreach ( $attrs as $key => $item ) {
			if ( 1 !== preg_match( self::PLAIN, (string) $key ) || ( is_string( $item ) && 1 !== preg_match( self::PLAIN, $item ) ) || ( is_array( $item ) && ! self::is_plain( $item ) ) ) {
				return false;
			}
		}
		return true;
	}
}
