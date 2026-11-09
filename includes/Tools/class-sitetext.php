<?php
/**
 * Text from the site, as tool output.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Turns text from the site (titles, names, headers, messages built from them) into plain text for an AI app: no tags, no
 * scripts and nothing that a browser parses as a tag. A real tag in the stored value is removed; text the site stores as
 * entities (`The &lt;div&gt; element`) shows literally on the site, so its words stay, unable to render (`The < div>
 * element`). Nothing in wp-admin prints this; it is for the app reading the tool result, which may render it.
 *
 * Read tools are cleaned by default. RAW and EXACT name what must keep its exact bytes. The write layer is the reverse:
 * exact by default, with DISPLAY fields cleaned (for_write).
 */
final class SiteText {

	/**
	 * What stays byte for byte, by tool: `*` for the whole result, a field name at any depth, or `/name` for a top-level
	 * field only. Everything else is cleaned.
	 *
	 * These are values the tool exists to return exactly: content and settings an AI app reads in order to edit them, where
	 * a stripped copy written back would damage the site, and code, regular expressions and JSON schemas, where `<` is
	 * meaningful. Stripping them could also break a later write's precondition or a rollback, which compare exact values.
	 * A result that holds markup here says so with `untrusted_html: true`.
	 *
	 * - wp_rest_get: core and plugin REST data, including `content.raw` and `title.raw` for editing (context=edit).
	 * - run_readonly_ability: whatever another plugin's ability returns; its shape and meaning are unknown here.
	 * - get_option, list_options, get_transient, list_transients, get_theme_mods, get_post_meta, get_user_meta: stored values
	 *   and their exact keys, which update_option, set_theme_mod, set_post_meta and set_user_meta take back.
	 * - list_rewrite_rules: regular expressions, such as `(?P<id>[0-9]+)`.
	 * - read_file, search_files, get_file_outline, list_files: source code, and paths read_file takes back.
	 * - get_page_html: `html` is the page's source. `text` (mode text) is what a visitor reads, with the source's tags
	 *   already gone. PageHtml neutralises it with defang(): stripping from a stray `<` would cut the rest of the page. A
	 *   summary's title, description and headings are cleaned.
	 * - list_cron_events: `args` identify the event to core (run_cron_event, unschedule_cron_event).
	 * - get_registered_meta: `default` is a stored value's default.
	 * - get_ability: JSON schemas, whose patterns can hold `<`.
	 * - get_*_page: the builder layout, which the matching save tool takes back.
	 * - get_*_schema: default values and option keys to build a layout with; labels and descriptions are cleaned.
	 */
	const RAW = array(
		'wp_rest_get'                 => array( '*' ),
		'run_readonly_ability'        => array( '*' ),
		'get_option'                  => array( '*' ),
		'list_options'                => array( '*' ),
		'get_transient'               => array( '*' ),
		'list_transients'             => array( '*' ),
		'get_theme_mods'              => array( '*' ),
		'get_post_meta'               => array( '*' ),
		'get_user_meta'               => array( '*' ),
		'list_rewrite_rules'          => array( '*' ),
		'read_file'                   => array( '*' ),
		'list_files'                  => array( '*' ),
		'search_files'                => array( '*' ),
		'get_file_outline'            => array( '*' ),
		'get_page_html'               => array( '/html', '/text' ),
		'list_cron_events'            => array( 'args' ),
		'get_registered_meta'         => array( 'default' ),
		'get_ability'                 => array( 'input_schema', 'output_schema' ),
		'get_elementor_page'          => array( 'elements' ),
		'get_bricks_page'             => array( 'elements' ),
		'get_breakdance_page'         => array( 'tree' ),
		'get_beaver_page'             => array( 'nodes' ),
		'get_elementor_widget_schema' => array( 'default', 'options', 'condition', 'conditions' ),
		'get_elementor_style_schema'  => array( 'default', 'options' ),
		'get_beaver_module_schema'    => array( 'default', 'defaults', 'options' ),
	);

	/**
	 * Values someone acts on or a report is about, which must name what really exists: slugs, ids, URLs, file paths, hooks. A write tool given a cleaned copy could act on a
	 * different target, and an integrity report could call a malicious file by a harmless name. They stay exact,
	 * since JSON carries them safely. When one could matter if an app rendered it (could_matter()), its row also gets
	 * `<field>_display`, the clean copy to show, and `identifier_unsafe: true`. Fields by tool, at any depth.
	 */
	const EXACT = array(
		'get_site_info'               => array( 'url', 'site_icon_url' ),
		'get_health_triage'           => array( 'id' ),
		'get_update_readiness'        => array( 'id' ),
		'get_content_qa'              => array( 'id', 'url' ),
		'list_plugins'                => array( 'slug' ),
		'list_themes'                 => array( 'slug', 'active_theme' ),
		'list_recent_content'         => array( 'link' ),
		'search_content'              => array( 'link' ),
		'get_core_updates'            => array( 'version', 'locale' ),
		'get_connection_info'         => array( 'login', 'roles', 'slug' ),
		'list_cron_events'            => array( 'hook', 'schedule' ),
		'list_roles'                  => array( 'slug', 'capabilities' ),
		'list_image_sizes'            => array( 'name' ),
		'get_registered_meta'         => array( 'object_type', 'subtype', 'name' ),
		'list_abilities'              => array( 'name', 'category' ),
		'get_ability'                 => array( 'name', 'category' ),
		'get_config'                  => array( 'value' ),
		'get_page_html'               => array( 'final_url' ),
		'verify_core_checksums'       => array( 'path', 'version', 'locale' ),
		'verify_plugin_checksums'     => array( 'path', 'slug', 'version' ),
		'search_plugin_directory'     => array( 'slug' ),
		'list_orders'                 => array( 'number' ),
		'list_products'               => array( 'sku' ),
		'list_elementor_widgets'      => array( 'slug' ),
		'get_elementor_widget_schema' => array( 'slug', 'name', 'type' ),
		'get_elementor_style_schema'  => array( 'name', 'type' ),
		'list_bricks_elements'        => array( 'name' ),
		'list_breakdance_elements'    => array( 'type' ),
		'list_beaver_modules'         => array( 'slug' ),
		'get_beaver_module_schema'    => array( 'slug', 'name', 'type' ),
	);

	/**
	 * Write-layer fields shown as text, at any depth: these are cleaned.
	 */
	const DISPLAY = array( 'summary', 'message', 'error', 'note', 'reason', 'title', 'label', 'client_name', 'featured_media_titles' );

	/**
	 * Write-layer fields holding exact values, never looked into, even for a DISPLAY field: `title` under `before` is the
	 * real old title.
	 */
	const EXACT_VALUES = array( 'before', 'after', 'current', 'restored', 'args', 'rollback', 'fields', 'value' );

	/**
	 * A finding's or update's suggested tool call, whose arguments are passed back exactly. It stays as it is, or, when
	 * cleaning would change it, goes: a changed argument could name something else, and the raw one would carry the markup.
	 */
	const FIX = 'fix';

	/**
	 * The exact fields that hold a URL. An app may put one in an attribute, so RISKY_URL flags it, and its clean copy is
	 * percent-encoded.
	 */
	const URL_FIELDS = array( 'url', 'link', 'site_icon_url', 'final_url' );

	/**
	 * What flags a kept URL: a quote, a backtick, whitespace, a control character, an angle bracket, or a reference a browser
	 * would decode, numeric or named, with or without a semicolon.
	 */
	const RISKY_URL = '/["\'`<>\x00-\x20\x7F]|&(?:#[0-9]|#[xX][0-9a-fA-F]|lt|LT|gt|GT|amp|AMP|[a-zA-Z][a-zA-Z0-9]*;)/';

	/**
	 * What flags any other kept value, besides what reads as an angle bracket: a double quote, a backtick or a control
	 * character, which ordinary names, slugs and hooks do not hold. Spaces and apostrophes do not flag.
	 */
	const RISKY_NAME = '/["`\x00-\x1F\x7F]/';

	/**
	 * Most rounds of decoding a value gets. Real text needs two or three; each further round undoes one more layer of
	 * encoding. A value still changing after this keeps its words, without a `<` or an `&`.
	 */
	const MAX_ROUNDS = 5;

	/**
	 * What starts a comment, or a complete tag as the HTML tokenizer reads it: a `<!`, `<?` or `</` up to the next `>`, or a
	 * name and attributes up to the next `>` outside a quoted attribute value. A quote that never closes counts for nothing,
	 * so the text after it stays. A `<` that starts neither is left for harmless().
	 */
	const TAG = '/<!--|<[!?\/][^>]*+>|<[a-zA-Z](?:[^>=]++|=\s*+"[^"]*+"|=\s*+\'[^\']*+\'|=)*+>/';

	/**
	 * A tag that opens a script or a style, whose contents are never words.
	 */
	const RAW_ELEMENT = '@^<(script|style)(?=[\s/>])@i';

	/**
	 * What ends a comment, looked for from the comment's fifth character. `<!-->` and `<!--->` end at once (after_tag()).
	 */
	const COMMENT_END = '/--!?>/';

	/**
	 * Plain text: real tags removed, with the contents of comments, scripts and styles; then entities decoded, repeated, because a
	 * value can be encoded more than once. Whatever decoding reveals keeps its words, and harmless() makes sure none of it
	 * can render. Cleaning twice changes nothing more, and a value with neither `<` nor `&` keeps its exact bytes.
	 *
	 * @param string $text Text from the site.
	 * @return string
	 */
	public static function plain( string $text ): string {
		if ( false === strpos( $text, '<' ) && false === strpos( $text, '&' ) ) {
			return $text;
		}
		return self::settled( self::without_tags( str_replace( "\0", '', $text ) ) );
	}

	/**
	 * Plain text for a message that names a value: the same, except that a comment, script or style that never closes takes
	 * only its opening with it, because the words after it are the message's own. `Option "nope<!--x" not found.` reads
	 * `Option "nope< !--x" not found.`. For tool errors, whose value is mostly what the caller asked for.
	 *
	 * @param string $text Message.
	 * @return string
	 */
	public static function readable( string $text ): string {
		if ( false === strpos( $text, '<' ) && false === strpos( $text, '&' ) ) {
			return $text;
		}
		return self::settled( self::without_tags( str_replace( "\0", '', $text ), true ) );
	}

	/**
	 * Text without its tags, decoded until nothing changes and unable to render.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function settled( string $text ): string {
		for ( $round = 0; $round < self::MAX_ROUNDS; $round++ ) {
			$plain = trim( self::harmless( self::decode( $text ) ) );
			if ( $plain === $text ) {
				return $plain;
			}
			$text = $plain;
		}
		return trim( str_replace( array( '<', '&' ), '', $text ) );
	}

	/**
	 * A sentence built from values from the site. Each value is cleaned on its own, first, so that one cannot open a tag or
	 * a comment that a later value closes and take the sentence's fixed words with it. Numbers pass as they are.
	 *
	 * @param string           $format  sprintf() format, without markup.
	 * @param string|int|float ...$values Values.
	 * @return string
	 */
	public static function message( string $format, ...$values ): string {
		foreach ( $values as $index => $value ) {
			if ( is_string( $value ) ) {
				$values[ $index ] = self::plain( $value );
			}
		}
		return vsprintf( $format, $values );
	}

	/**
	 * Removes the complete tags in a stored value, in one pass, as the tokenizer meets them. Unlike strip_tags(), it leaves a
	 * `<` that starts no complete tag and the text after it: `I <3 WordPress` and `a <b` stay whole. A comment, script or style
	 * takes its contents with it, and with no close they run to the end of the value, as they do in a browser, unless
	 * $keep_unclosed says the words count (readable()). A tag ends in a `>`, so what follows the last one holds no complete
	 * tag and is not searched, or every `<` there would scan to the end; only a comment can open there. Each search either
	 * moves past what it found or ends the pass, and a close that never came is not looked for again, so the time grows with
	 * the value's length. A search that fails keeps nothing more.
	 *
	 * @param string $text          Text.
	 * @param bool   $keep_unclosed Whether what follows an opening that never closes stays.
	 * @return string
	 */
	private static function without_tags( string $text, bool $keep_unclosed = false ): string {
		$end  = strrpos( $text, '>' );
		$head = false === $end ? '' : substr( $text, 0, $end + 1 );
		$tail = false === $end ? $text : substr( $text, $end + 1 );
		$kept = '';
		$at   = 0;
		$open = array();
		for ( $found = preg_match( self::TAG, $head, $tag, PREG_OFFSET_CAPTURE, $at ); 1 === $found; $found = preg_match( self::TAG, $head, $tag, PREG_OFFSET_CAPTURE, $at ) ) {
			$kept .= substr( $head, $at, $tag[0][1] - $at );
			$next  = self::after_tag( $head, $tag[0][0], $tag[0][1], $open );
			if ( null === $next ) {
				if ( ! $keep_unclosed ) {
					return $kept;
				}
				$kept .= '<!--' === $tag[0][0] ? $tag[0][0] : '';
				$next  = $tag[0][1] + strlen( $tag[0][0] );
			}
			$at = $next;
		}
		if ( false === $found ) {
			return $kept;
		}
		$comment = $keep_unclosed ? false : strpos( $tail, '<!--' );
		return $kept . substr( $head, $at ) . ( false === $comment ? $tail : substr( $tail, 0, $comment ) );
	}

	/**
	 * Where the text goes on after a tag: right after it, or, for a comment, script or style, after its close.
	 *
	 * @param string              $head  The text being searched, which ends in a `>`.
	 * @param string              $tag   What TAG found.
	 * @param int                 $start Where it starts.
	 * @param array<string, bool> $open  Closes that were looked for and never came.
	 * @return int|null Null when the close never comes.
	 */
	private static function after_tag( string $head, string $tag, int $start, array &$open ): ?int {
		if ( '<!--' === $tag ) {
			foreach ( array( '>', '->' ) as $at_once ) {
				if ( substr( $head, $start + 4, strlen( $at_once ) ) === $at_once ) {
					return $start + 4 + strlen( $at_once );
				}
			}
			$close = self::COMMENT_END;
			$from  = $start + 4;
		} elseif ( 1 === preg_match( self::RAW_ELEMENT, $tag, $name ) ) {
			$close = '@</' . strtolower( $name[1] ) . '(?=[\s/>])[^>]*+>@i';
			$from  = $start + strlen( $tag );
		} else {
			return $start + strlen( $tag );
		}
		if ( isset( $open[ $close ] ) ) {
			return null;
		}
		if ( 1 === preg_match( $close, $head, $found, PREG_OFFSET_CAPTURE, $from ) ) {
			return $found[0][1] + strlen( $found[0][0] );
		}
		$open[ $close ] = true;
		return null;
	}

	/**
	 * Parts a `<` from what would make it start a tag: to the HTML tokenizer, a letter, `/`, `!` or `?`. Any other `<` is text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function harmless( string $text ): string {
		return (string) preg_replace( '/<(?=[a-zA-Z\/!?])/', '< ', $text );
	}

	/**
	 * Decodes entities, and the bare references that can build a tag, which html_entity_decode() leaves alone but a browser
	 * or a lenient client reads: `&#60img`, `&ltimg`, `&LTimg`, and `&amp` or `&AMP` before another reference (`&amplt;`).
	 * harmless() then parts any tag start. Words survive (`Acme&LTD` reads `Acme< D`). Other bare references stay as they
	 * are, since none can start a tag: `Ford&GT`, `SEO&AMP tips`, `?a=1&gt=3`.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function decode( string $text ): string {
		$text = (string) preg_replace( array( '/&#([0-9]+)(?![0-9;])/', '/&#[xX]([0-9a-fA-F]+)(?![0-9a-fA-F;])/' ), array( '&#$1;', '&#x$1;' ), $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return (string) preg_replace(
			array( '/&(?:amp|AMP)(?=#|lt|LT|gt|GT|amp|AMP)/', '/&(?:lt|LT)(?=[a-zA-Z\/!?])/' ),
			array( '&', '<' ),
			$text
		);
	}

	/**
	 * HTML with every `<` after its last `>` written as an entity. A tag ends in a `>`, so none of them starts one, and a
	 * pattern looking for a tag doesn't scan to the end from each.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function without_open_ends( string $html ): string {
		$end = strrpos( $html, '>' );
		$cut = false === $end ? 0 : $end + 1;
		return substr( $html, 0, $cut ) . str_replace( '<', '&lt;', substr( $html, $cut ) );
	}

	/**
	 * Neutralises a `<` that could start a tag, adding a space so it reads as text and takes nothing with it. For text that
	 * is whole words a page shows, such as get_page_html's: `The <div> element` reads `The < div> element`.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function defang( string $text ): string {
		return self::harmless( $text );
	}

	/**
	 * A read tool's result: RAW and EXACT fields kept, everything else plain, and `untrusted_html: true` at the top when a
	 * RAW value holds markup or an EXACT value could matter if rendered (could_matter()), so an app knows not to render it.
	 * A result without one comes back unchanged.
	 *
	 * @param string               $tool   Tool name.
	 * @param array<string, mixed> $result Tool result.
	 * @return array<string, mixed>
	 */
	public static function for_tool( string $tool, array $result ): array {
		$raw = self::RAW[ $tool ] ?? array();
		if ( in_array( '*', $raw, true ) ) {
			return self::flagged( $result, self::holds_markup( $result ) );
		}

		$exact = self::EXACT[ $tool ] ?? array();
		$plain = array();
		foreach ( $result as $key => $value ) {
			$plain += in_array( '/' . $key, $raw, true ) ? array( $key => $value ) : self::deep( array( $key => $value ), $raw, $exact );
		}
		return self::flagged( $plain, self::raw_markup( $result, $raw ) );
	}

	/**
	 * What the write layer shows an AI app. The reverse of a read tool: everything stays exact (change ids, fingerprints,
	 * rollback data, before and after values, names a later call takes back), except DISPLAY fields. Only this copy is
	 * changed: the Gateway has already stored, fingerprinted and compared the exact values, and does so again on its own
	 * data at apply and rollback time. `untrusted_html: true` says when an exact value holds markup.
	 *
	 * @param array<string, mixed> $result Gateway response.
	 * @return array<string, mixed>
	 */
	public static function for_write( array $result ): array {
		$clean = self::display_only( $result );
		return self::flagged( $clean, self::deep( $clean ) !== $clean );
	}

	/**
	 * Cleans DISPLAY fields, leaving alone everything under EXACT_VALUES.
	 *
	 * @param array<mixed> $value Part of a Gateway response.
	 * @return array<mixed>
	 */
	private static function display_only( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( in_array( $key, self::DISPLAY, true ) ) {
				$value[ $key ] = self::deep( $item );
			} elseif ( is_array( $item ) && ! in_array( $key, self::EXACT_VALUES, true ) ) {
				$value[ $key ] = self::display_only( $item );
			}
		}
		return $value;
	}

	/**
	 * Adds the top-level flag when a kept value holds markup or could matter if rendered.
	 *
	 * @param array<string, mixed> $result     Result.
	 * @param bool                 $raw_markup Whether a value kept as it is holds markup.
	 * @return array<string, mixed>
	 */
	private static function flagged( array $result, bool $raw_markup = false ): array {
		if ( $raw_markup || self::holds_unsafe( $result ) ) {
			$result['untrusted_html'] = true;
		}
		return $result;
	}

	/**
	 * Whether a RAW field of a result holds markup: `/name` for a top-level field, or a name at any depth.
	 *
	 * @param mixed    $value Result, or part of one.
	 * @param string[] $raw   RAW names of the tool.
	 * @param bool     $top   Whether $value is the top of the result.
	 * @return bool
	 */
	private static function raw_markup( $value, array $raw, bool $top = true ): bool {
		$items = is_object( $value ) ? get_object_vars( $value ) : $value;
		if ( ! is_array( $items ) ) {
			return false;
		}
		foreach ( $items as $key => $item ) {
			$kept = in_array( $key, $raw, true ) || ( $top && in_array( '/' . $key, $raw, true ) );
			if ( $kept ? self::holds_markup( $item ) : self::raw_markup( $item, $raw, false ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a value kept as it is holds markup: it holds an angle bracket, which a stored value, a schema's pattern or a
	 * rewrite rule may mean as one, or cleaning would change it. Keys count too. Any size is judged whole, and a pattern that
	 * fails on a value counts as markup.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function holds_markup( $value ): bool {
		if ( is_string( $value ) ) {
			return 0 !== preg_match( '/[<>]/', $value ) || self::plain( $value ) !== $value;
		}
		$items = is_object( $value ) ? get_object_vars( $value ) : $value;
		if ( ! is_array( $items ) ) {
			return false;
		}
		foreach ( $items as $key => $item ) {
			if ( ( is_string( $key ) && self::holds_markup( $key ) ) || self::holds_markup( $item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a row anywhere in a result is marked identifier_unsafe.
	 *
	 * @param mixed $value Result, or part of one.
	 * @return bool
	 */
	private static function holds_unsafe( $value ): bool {
		$items = is_object( $value ) ? get_object_vars( $value ) : $value;
		if ( ! is_array( $items ) ) {
			return false;
		}
		if ( true === ( $items['identifier_unsafe'] ?? null ) ) {
			return true;
		}
		foreach ( $items as $item ) {
			if ( self::holds_unsafe( $item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every string in a tool result made plain, array keys included; numbers, booleans and null stay as they are.
	 *
	 * @param mixed    $value Tool result, or part of one.
	 * @param string[] $raw   Fields to leave as they are.
	 * @param string[] $exact Fields kept exact and flagged (EXACT).
	 * @return mixed
	 */
	public static function deep( $value, array $raw = array(), array $exact = array() ) {
		if ( is_string( $value ) ) {
			return self::plain( $value );
		}
		if ( is_object( $value ) ) {
			$properties = get_object_vars( $value );
			$clean      = self::deep( $properties, $raw, $exact );
			// The same object when nothing changed, so a result without markup compares identical. An empty one stays one too: JSON tells {} from [].
			return $clean === $properties ? $value : (object) $clean;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$plain = array();
		foreach ( $value as $key => $item ) {
			if ( is_int( $key ) ) {
				$plain[ $key ] = self::deep( $item, $raw, $exact );
			} elseif ( in_array( $key, $raw, true ) ) {
				$plain[ $key ] = $item;
			} elseif ( in_array( $key, $exact, true ) ) {
				$plain += self::exact_field( $key, $item );
			} elseif ( self::FIX === $key ) {
				$plain[ $key ] = self::deep( $item ) === $item ? $item : null;
			} else {
				$plain[ self::plain( $key ) ] = self::deep( $item, $raw, $exact );
			}
		}
		return $plain;
	}

	/**
	 * Whether a kept value could matter if an app rendered it. A pattern that fails on it counts as yes. A URL is held to RISKY_URL, without weighing what it means:
	 * `?p=1&ltype=3` is flagged and `?a=1&b=2` is not. Anything else, such as a name, a slug or a hook, is held to RISKY_NAME or
	 * for what reads as an angle bracket, so `O'Brien` and `John Smith` are not flagged.
	 *
	 * @param mixed $value Value.
	 * @param bool  $url   Whether it is in a URL field.
	 * @return bool
	 */
	private static function could_matter( $value, bool $url ): bool {
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( is_object( $value ) ? get_object_vars( $value ) : $value as $key => $item ) {
				if ( ( is_string( $key ) && self::could_matter( $key, $url ) ) || self::could_matter( $item, $url ) ) {
					return true;
				}
			}
			return false;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}
		return 0 !== preg_match( $url ? self::RISKY_URL : self::RISKY_NAME, $value ) || ( ! $url && self::reads_as_angle_bracket( $value ) );
	}

	/**
	 * Whether text holds an angle bracket, or a reference that decodes into one in any layer. Text still changing after the
	 * last round counts too: plain() gives up on it.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function reads_as_angle_bracket( string $text ): bool {
		for ( $round = 0; $round < self::MAX_ROUNDS; $round++ ) {
			if ( 0 !== preg_match( '/[<>]|&(?:lt|LT|gt|GT)/', $text ) ) {
				return true;
			}
			$decoded = self::decode( $text );
			// A failed pattern empties the text, and what it held is unknown, so it counts as flagged.
			if ( '' === $decoded && '' !== $text ) {
				return true;
			}
			if ( $decoded === $text ) {
				return false;
			}
			$text = $decoded;
		}
		return true;
	}

	/**
	 * An exact field: always byte for byte, and with a clean copy and the flag when it could matter.
	 *
	 * @param string $key  Field name.
	 * @param mixed  $item Value.
	 * @return array<string, mixed>
	 */
	public static function exact_field( string $key, $item ): array {
		$url = in_array( $key, self::URL_FIELDS, true );
		if ( ! self::could_matter( $item, $url ) ) {
			return array( $key => $item );
		}
		$clean = self::deep( $item );
		return array(
			$key                => $item,
			$key . '_display'   => is_string( $clean ) && $url ? self::encoded_url( $clean ) : self::without_risky_name( $clean ),
			'identifier_unsafe' => true,
		);
	}

	/**
	 * A clean copy without what RISKY_NAME finds, at any depth. It is cleaned again each time, because taking a character out
	 * can join what it separated: `<"b` would become a tag.
	 *
	 * @param mixed $value Clean copy, or part of one.
	 * @return mixed
	 */
	private static function without_risky_name( $value ) {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				$clean[ is_string( $key ) ? self::without_risky_name( $key ) : $key ] = self::without_risky_name( $item );
			}
			return $clean;
		}
		if ( is_object( $value ) ) {
			return (object) self::without_risky_name( get_object_vars( $value ) );
		}
		while ( is_string( $value ) && 1 === preg_match( self::RISKY_NAME, $value ) ) {
			$value = self::plain( (string) preg_replace( self::RISKY_NAME, '', $value ) );
		}
		return $value;
	}

	/**
	 * A URL with percent escapes for quotes, backticks, whitespace, control characters and angle brackets. The rest stays.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function encoded_url( string $url ): string {
		return (string) preg_replace_callback(
			'/["\'`<>\x00-\x20\x7F]/',
			static function ( array $found ): string {
				return rawurlencode( $found[0] );
			},
			$url
		);
	}
}
