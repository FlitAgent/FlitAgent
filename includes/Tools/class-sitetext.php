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
 * Read tools are cleaned by default. EXACT names what must keep its exact bytes, because cleaning it would make a report
 * name something that does not exist.
 */
final class SiteText {

	/**
	 * Values someone acts on or a report is about, which must name what really exists: slugs, ids, URLs. They stay exact,
	 * since JSON carries them safely. When one could matter if an app rendered it (could_matter()), its row also gets
	 * `<field>_display`, the clean copy to show, and `identifier_unsafe: true`. Fields by tool, at any depth.
	 */
	const EXACT = array(
		'get_site_info'        => array( 'url', 'site_icon_url' ),
		'get_health_triage'    => array( 'id' ),
		'get_update_readiness' => array( 'id' ),
		'get_content_qa'       => array( 'id', 'url' ),
		'list_plugins'         => array( 'slug' ),
		'list_themes'          => array( 'slug', 'active_theme' ),
		'list_recent_content'  => array( 'link' ),
		'search_content'       => array( 'link' ),
		'get_core_updates'     => array( 'version', 'locale' ),
		'get_connection_info'  => array( 'login', 'roles', 'slug' ),
	);

	/**
	 * The exact fields that hold a URL. An app may put one in an attribute, so RISKY_URL flags it, and its clean copy is
	 * percent-encoded.
	 */
	const URL_FIELDS = array( 'url', 'link', 'site_icon_url' );

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
	 * A complete tag as the HTML tokenizer reads it: a comment, a `<!`, `<?` or `</` up to the next `>`, or a name and
	 * attributes up to the next `>` outside a quoted attribute value. A quote that never closes counts for nothing, so the
	 * text after it stays. A `<` that starts no complete tag is left for harmless().
	 */
	const TAG = '/<!--(?:[^-]++|-(?!->))*+-->|<[!?\/][^>]*+>|<[a-zA-Z](?:[^>=]++|=\s*+"[^"]*+"|=\s*+\'[^\']*+\'|=)*+>/';

	/**
	 * A script or a style with its contents.
	 */
	const RAW_ELEMENT = '@<(script|style)(?=[\s/>])[^>]*+>(?:[^<]++|<(?!/\1>))*+</\1>@i';

	/**
	 * Plain text: real tags removed, with the contents of scripts and styles; then entities decoded, repeated, because a
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
		$text = self::without_tags( str_replace( "\0", '', $text ) );
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
	 * Removes the complete tags in a stored value. Unlike strip_tags(), it leaves a `<` that starts no complete tag and the
	 * text after it: `I <3 WordPress` and `a <b` stay whole. A tag ends in a `>`, so what follows the last one holds no tag and
	 * is not searched, or every `<` there would scan to the end.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function without_tags( string $text ): string {
		$end = strrpos( $text, '>' );
		if ( false === $end ) {
			return $text;
		}
		return (string) preg_replace( array( self::RAW_ELEMENT, self::TAG ), '', substr( $text, 0, $end + 1 ) ) . substr( $text, $end + 1 );
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
	 * A read tool's result: exact fields kept, everything else plain, and `untrusted_html: true` at the top when an exact
	 * value could matter if rendered (could_matter()), so an app knows not to render it. A result without one comes back
	 * unchanged.
	 *
	 * @param string               $tool   Tool name.
	 * @param array<string, mixed> $result Tool result.
	 * @return array<string, mixed>
	 */
	public static function for_tool( string $tool, array $result ): array {
		return self::flagged( self::deep( $result, self::EXACT[ $tool ] ?? array() ) );
	}

	/**
	 * Adds the top-level flag when a row kept an exact value that could matter if rendered.
	 *
	 * @param array<string, mixed> $result Result.
	 * @return array<string, mixed>
	 */
	private static function flagged( array $result ): array {
		if ( self::holds_unsafe( $result ) ) {
			$result['untrusted_html'] = true;
		}
		return $result;
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
	 * @param string[] $exact Fields kept exact (EXACT).
	 * @return mixed
	 */
	public static function deep( $value, array $exact = array() ) {
		if ( is_string( $value ) ) {
			return self::plain( $value );
		}
		if ( is_object( $value ) ) {
			$properties = get_object_vars( $value );
			$clean      = self::deep( $properties, $exact );
			// The same object when nothing changed, so a result without markup compares identical. An empty one stays one too: JSON tells {} from [].
			return $clean === $properties ? $value : (object) $clean;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$plain = array();
		foreach ( $value as $key => $item ) {
			if ( is_int( $key ) ) {
				$plain[ $key ] = self::deep( $item, $exact );
			} elseif ( in_array( $key, $exact, true ) ) {
				$plain += self::exact_field( $key, $item );
			} else {
				$plain[ self::plain( $key ) ] = self::deep( $item, $exact );
			}
		}
		return $plain;
	}

	/**
	 * Whether a kept value could matter if an app rendered it. A URL is held to RISKY_URL, without weighing what it means:
	 * `?p=1&ltype=3` is flagged and `?a=1&b=2` is not. Anything else, such as a name, a slug or a hook, is held to RISKY_NAME or
	 * for what reads as an angle bracket, so `O'Brien` and `John Smith` are not flagged.
	 *
	 * @param mixed $value Value.
	 * @param bool  $url   Whether it is in a URL field.
	 * @return bool
	 */
	private static function could_matter( $value, bool $url ): bool {
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $item ) {
				if ( self::could_matter( $item, $url ) ) {
					return true;
				}
			}
			return false;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}
		return $url ? 1 === preg_match( self::RISKY_URL, $value ) : 1 === preg_match( self::RISKY_NAME, $value ) || self::reads_as_angle_bracket( $value );
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
			if ( 1 === preg_match( '/[<>]|&(?:lt|LT|gt|GT)/', $text ) ) {
				return true;
			}
			$decoded = self::decode( $text );
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
			return array_map( array( self::class, 'without_risky_name' ), $value );
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
