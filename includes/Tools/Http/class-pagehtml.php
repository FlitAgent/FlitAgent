<?php
/**
 * The get_page_html tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Http;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Annotations;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\SiteText;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the rendered HTML of a page on this site, as an anonymous visitor.
 *
 * Only this site is reachable: the URL and every redirect must have the scheme, host and port of `home_url()`, so the
 * tool cannot be used to reach other hosts or internal addresses (SSRF). Redirects are followed by hand so each hop is
 * checked before it is requested. A failing page (non-2xx, or PHP fatal output) is a tool error, never a result.
 */
final class PageHtml implements Tool {

	/**
	 * Largest HTML returned, in bytes; longer pages are cut and flagged `truncated`.
	 */
	const MAX_BYTES = 524288;

	/**
	 * Smallest max_bytes a caller may ask for.
	 */
	const MIN_BYTES = 1024;

	/**
	 * Output modes: the HTML, the visible text, or a small outline of the page.
	 */
	const MODES = array( 'full', 'text', 'summary' );

	/**
	 * Request timeout, in seconds.
	 */
	const TIMEOUT = 15;

	/**
	 * Redirects followed before giving up.
	 */
	const MAX_REDIRECTS = 5;

	/**
	 * Output PHP or WordPress prints when a page dies with a fatal error.
	 */
	const FATAL_PATTERNS = array(
		'/<b>(?:Fatal|Parse) error<\/b>:/i',
		'/(?:^|\n)[ \t]*(?:PHP )?(?:Fatal|Parse) error: /i',
		'/There has been a critical error on this website/i',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_page_html';
	}

	/**
	 * Protected: the request comes from the site's own server, which can bypass IP allowlists and staging protections,
	 * and each call renders a full page.
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		return array(
			'name'         => $this->name(),
			'title'        => 'Get rendered page HTML with FlitAgent',
			'description'  => 'From the FlitAgent WordPress plugin: fetches a page on this site the way an anonymous visitor sees it (no login, no cookies) and returns the HTTP status, final URL after redirects on this site, and the HTML (cut at 512 KB, with truncated set). If the full HTML is too large for your tool results, use mode "summary" first (title, meta description, headings, link and asset counts, page size; a few KB), mode "text" for the visible text without tags, scripts or styles, or a lower max_bytes. Only this site can be fetched. A page that fails (non-2xx status or PHP fatal error output) is reported as an error. Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'url'       => array(
						'type'        => 'string',
						'description' => 'A path on this site such as "/" or "/about/?ref=x", or a full URL on this site.',
					),
					'mode'      => array(
						'type'        => 'string',
						'enum'        => self::MODES,
						'default'     => 'full',
						'description' => 'full (default): the HTML. text: only the visible text, without tags, scripts, styles or comments; much smaller. summary: title, meta description, headings outline, link and asset counts and page size; the smallest, use it first on large pages.',
					),
					'max_bytes' => array(
						'type'        => 'integer',
						'minimum'     => self::MIN_BYTES,
						'maximum'     => self::MAX_BYTES,
						'default'     => self::MAX_BYTES,
						'description' => 'Largest html (mode full) or text (mode text) returned, in bytes; anything longer is cut and truncated is set. Not allowed with mode summary.',
					),
				),
				'required'             => array( 'url' ),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'status'           => array( 'type' => 'integer' ),
					'final_url'        => array( 'type' => 'string' ),
					'content_type'     => array( 'type' => 'string' ),
					'html'             => array(
						'type'        => 'string',
						'description' => 'The page HTML (mode full).',
					),
					'text'             => array(
						'type'        => 'string',
						'description' => 'The page\'s visible text, one line per block (mode text).',
					),
					'bytes'            => array(
						'type'        => 'integer',
						'description' => 'Length of the returned html or text, in bytes.',
					),
					'page_bytes'       => array(
						'type'        => 'integer',
						'description' => 'Length of the HTML read, in bytes, at most 512 KB (modes text and summary).',
					),
					'title'            => array( 'type' => 'string' ),
					'meta_description' => array( 'type' => 'string' ),
					'headings'         => array(
						'type'        => 'array',
						'description' => 'Headings in page order (mode summary), at most 100.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'level' => array( 'type' => 'integer' ),
								'text'  => array( 'type' => 'string' ),
							),
						),
					),
					'counts'           => array(
						'type'        => 'object',
						'description' => 'Counts of links (internal: on this site; external: other web addresses), images, scripts and stylesheets (mode summary).',
						'properties'  => array(
							'links'          => array( 'type' => 'integer' ),
							'internal_links' => array( 'type' => 'integer' ),
							'external_links' => array( 'type' => 'integer' ),
							'images'         => array( 'type' => 'integer' ),
							'scripts'        => array( 'type' => 'integer' ),
							'stylesheets'    => array( 'type' => 'integer' ),
						),
					),
					'truncated'        => array(
						'type'        => 'boolean',
						'description' => 'True if anything was left out: the page was longer than 512 KB, or the output was cut to max_bytes or to the summary limits.',
					),
				),
				'required'   => array( 'status', 'final_url', 'content_type', 'truncated' ),
			),
			'annotations'  => Annotations::read_only( 'Get rendered page HTML with FlitAgent' ),
		);
	}

	/**
	 * Fetches the page.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the URL is not on this site, a redirect leaves it, or the page fails.
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array( 'url', 'mode', 'max_bytes' ), $this->name() );
		if ( ! isset( $arguments['url'] ) || ! is_string( $arguments['url'] ) ) {
			throw new ToolError( 'url is required: a path such as "/about/" or a full URL on this site.' );
		}
		$mode = Arguments::enum( $arguments, 'mode', self::MODES ) ?? 'full';
		if ( 'summary' === $mode && array_key_exists( 'max_bytes', $arguments ) ) {
			throw new ToolError( 'max_bytes applies only to mode "full" and "text"; a summary is always small. Leave max_bytes out.' );
		}
		$max_bytes = Arguments::int( $arguments, 'max_bytes', self::MAX_BYTES, self::MIN_BYTES, self::MAX_BYTES );

		$url = $this->input_url( $arguments['url'] );

		for ( $redirects = 0; ; $redirects++ ) {
			$response = wp_safe_remote_get( $url, $this->request_args() );
			if ( is_wp_error( $response ) ) {
				throw new ToolError( 'Could not fetch the page: ' . esc_textarea( $response->get_error_message() ) );
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( $status < 300 || $status >= 400 || '' === $location ) {
				break;
			}
			if ( $redirects >= self::MAX_REDIRECTS ) {
				throw new ToolError( sprintf( 'The page redirects more than %d times.', (int) self::MAX_REDIRECTS ) );
			}
			$next = $this->redirect_target( $url, $location );
			if ( null === $next ) {
				throw new ToolError( 'The page redirects off this site (another host, scheme or port). FlitAgent only fetches this site, so the redirect was not followed.' );
			}
			$url = $next;
		}

		$body  = (string) wp_remote_retrieve_body( $response );
		$fatal = self::fatal_excerpt( $body );

		if ( $status < 200 || $status >= 300 ) {
			throw new ToolError( sprintf( 'The page returned HTTP %d, not a successful page.', (int) $status ) . ( null !== $fatal ? ' PHP fatal error: ' . esc_textarea( $fatal ) : '' ) );
		}
		if ( null !== $fatal ) {
			throw new ToolError( sprintf( 'The page returned HTTP %d but its output contains a PHP fatal error: %s', (int) $status, esc_textarea( $fatal ) ) );
		}

		$page_cut = strlen( $body ) > self::MAX_BYTES;
		$page     = PageDigest::cut( $body, self::MAX_BYTES );
		$result   = array(
			'status'       => $status,
			'final_url'    => $url,
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		);

		if ( 'summary' === $mode ) {
			$summary = PageDigest::summary(
				$page,
				function ( string $href ): string {
					return $this->link_kind( $href );
				}
			);
			if ( null === $summary ) {
				throw new ToolError( 'The page could not be summarized. Use mode "full" with a lower max_bytes instead.' );
			}
			return $result + array(
				'title'            => $summary['title'],
				'meta_description' => $summary['meta_description'],
				'headings'         => $summary['headings'],
				'counts'           => $summary['counts'],
				'page_bytes'       => strlen( $page ),
				'truncated'        => $page_cut || $summary['truncated'],
			);
		}

		if ( 'text' === $mode ) {
			$text = PageDigest::text( $page );
			if ( null === $text ) {
				throw new ToolError( 'The page\'s text could not be extracted. Use mode "full" with a lower max_bytes instead.' );
			}
			$text = SiteText::defang( $text );
			$cut  = PageDigest::cut( $text, $max_bytes );
			return $result + array(
				'text'       => $cut,
				'bytes'      => strlen( $cut ),
				'page_bytes' => strlen( $page ),
				'truncated'  => $page_cut || strlen( $cut ) < strlen( $text ),
			);
		}

		$html = PageDigest::cut( $page, $max_bytes );
		return $result + array(
			'html'      => $html,
			'bytes'     => strlen( $html ),
			'truncated' => $page_cut || strlen( $html ) < strlen( $page ),
		);
	}

	/**
	 * Whether a link points to this site ('internal': relative, or absolute with this site's scheme, host and port), to
	 * another web address ('external'), or elsewhere such as mailto: ('other').
	 *
	 * @param string $href Link target.
	 * @return string
	 */
	private function link_kind( string $href ): string {
		$href = trim( $href );
		if ( 0 === strpos( $href, '//' ) ) {
			$href = $this->home_parts()['scheme'] . ':' . $href;
		} elseif ( 1 !== preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) ) {
			return 'internal';
		}
		if ( 1 !== preg_match( '#^https?:#i', $href ) ) {
			return 'other';
		}
		return null !== $this->same_site( $href ) ? 'internal' : 'external';
	}

	/**
	 * Request options: no automatic redirects, a size cap, and nothing that identifies the caller.
	 *
	 * @return array<string, mixed>
	 */
	private function request_args(): array {
		return array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::MAX_BYTES + 1,
			'cookies'             => array(),
			'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8' ),
			'user-agent'          => 'FlitAgent/' . FLITAGENT_VERSION . ' (get_page_html)',
		);
	}

	/**
	 * Turns the url argument into an absolute URL on this site.
	 *
	 * @param string $input Path or URL.
	 * @return string
	 * @throws ToolError When it is not on this site.
	 */
	private function input_url( string $input ): string {
		$url = null;
		if ( self::is_path( $input ) ) {
			$url = $this->same_site( home_url( $input ) );
		} elseif ( ! self::has_unsafe_characters( $input ) ) {
			$url = $this->same_site( $input );
		}

		if ( null === $url ) {
			throw new ToolError( 'url must be a path such as "/about/" or a URL on this site (' . esc_textarea( $this->origin() ) . '). get_page_html only fetches this site.' );
		}
		return $url;
	}

	/**
	 * Resolves a redirect's Location against the current URL and checks it stays on this site.
	 *
	 * @param string $current  URL that redirected.
	 * @param string $location Location header.
	 * @return string|null The next URL, or null if it leaves this site.
	 */
	private function redirect_target( string $current, string $location ) {
		if ( self::has_unsafe_characters( $location ) ) {
			return null;
		}
		if ( self::is_path( $location ) ) {
			return $this->same_site( $this->origin() . $location );
		}
		if ( 1 !== preg_match( '#^[a-z][a-z0-9+.-]*:#i', $location ) && 0 !== strpos( $location, '//' ) ) {
			// A relative reference such as "page-2/": resolve against the current URL's folder.
			$path = (string) wp_parse_url( $current, PHP_URL_PATH );
			$dir  = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
			return $this->same_site( $this->origin() . ( '' === $dir ? '/' : $dir ) . $location );
		}
		return $this->same_site( $location );
	}

	/**
	 * Returns the URL without its fragment if it has this site's scheme, host and port and no user info; else null.
	 *
	 * @param string $url Absolute URL.
	 * @return string|null
	 */
	private function same_site( string $url ) {
		if ( self::has_unsafe_characters( $url ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		$home  = $this->home_parts();
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		if ( strtolower( $parts['scheme'] ) !== $home['scheme'] || strtolower( $parts['host'] ) !== $home['host'] ) {
			return null;
		}
		if ( self::port( $parts ) !== self::port( $home ) ) {
			return null;
		}

		$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return $this->origin() . $path . $query;
	}

	/**
	 * This site's lower-cased scheme, host and port, from `home_url()`.
	 *
	 * @return array<string, mixed>
	 * @throws ToolError When the home URL cannot be read.
	 */
	private function home_parts(): array {
		$home = wp_parse_url( home_url() );
		if ( ! is_array( $home ) || ! isset( $home['scheme'], $home['host'] ) ) {
			throw new ToolError( 'This site\'s home URL could not be read.' );
		}
		$home['scheme'] = strtolower( $home['scheme'] );
		$home['host']   = strtolower( $home['host'] );
		return $home;
	}

	/**
	 * This site's origin, e.g. `http://localhost:8881`.
	 *
	 * @return string
	 */
	private function origin(): string {
		$home = $this->home_parts();
		return $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
	}

	/**
	 * The effective port of parsed URL parts.
	 *
	 * @param array<string, mixed> $parts Parsed URL.
	 * @return int
	 */
	private static function port( array $parts ): int {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}
		return 'https' === strtolower( $parts['scheme'] ) ? 443 : 80;
	}

	/**
	 * Whether the value is a path on this host ("/x"), not a protocol-relative URL ("//host").
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_path( string $value ): bool {
		return '' !== $value && '/' === $value[0] && 0 !== strpos( $value, '//' ) && ! self::has_unsafe_characters( $value );
	}

	/**
	 * Backslashes, whitespace and control characters: browsers and HTTP clients disagree on them, so they are refused.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function has_unsafe_characters( string $value ): bool {
		return 1 === preg_match( '/[\x00-\x20\x7f\\\\]/', $value );
	}

	/**
	 * The first fatal error line in a page, without tags or the WordPress root path, or null if there is none.
	 *
	 * @param string $body Page body.
	 * @return string|null
	 */
	private static function fatal_excerpt( string $body ) {
		foreach ( self::FATAL_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $body, $match, PREG_OFFSET_CAPTURE ) ) {
				$excerpt = substr( $body, (int) $match[0][1], 400 );
				$excerpt = str_replace( ABSPATH, '', wp_strip_all_tags( $excerpt ) );
				$excerpt = trim( (string) preg_replace( '/\s+/', ' ', $excerpt ) );
				return substr( $excerpt, 0, 300 );
			}
		}
		return null;
	}
}
