<?php
/**
 * Content QA rules.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Triage;

use FlitAgent\Tools\SiteText;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a snapshot of the site's content (see ContentSnapshot) into findings in the same shape as Triage. Pure: no
 * WordPress calls.
 */
final class Content {

	/**
	 * Most findings listed; the summary still counts all of them.
	 */
	const MAX_FINDINGS = 200;

	/**
	 * Fewer words than this is thin content.
	 */
	const THIN_WORDS = 300;

	/**
	 * Published content not modified for this many days is stale (18 months).
	 */
	const STALE_DAYS = 547;

	/**
	 * Drafts not touched for this many days are abandoned (6 months).
	 */
	const OLD_DRAFT_DAYS = 183;

	/**
	 * Paths that are not single posts or pages, so internal links to them are not checked.
	 */
	const SKIPPED_PATHS = '#^/(wp-content|wp-admin|wp-includes|wp-json|feed|comments|category|tag|author|page|search)(/|$)|\.(xml|php|txt)$#';

	/**
	 * Findings for a snapshot.
	 *
	 * @param array<string, mixed> $snapshot Snapshot from ContentSnapshot::read().
	 * @return array{summary: array<string, int>, truncated: bool, findings: array<int, array<string, mixed>>}
	 */
	public static function run( array $snapshot ): array {
		$now   = (int) $snapshot['now'];
		$posts = $snapshot['posts'];

		$findings = array_merge(
			self::library_images( $snapshot['images'] ),
			self::inline_images( $posts ),
			self::broken_links( $posts, (string) $snapshot['home'], $snapshot['links'] ),
			self::empty_titles( $posts ),
			self::duplicate_titles( $posts ),
			self::descriptions( $posts ),
			self::thin( $posts ),
			self::stale( $posts, $now ),
			self::old_drafts( $snapshot['drafts'], $now ),
			self::empty_terms( $snapshot['terms'], (int) $snapshot['default_category'] )
		);

		$sorted  = array();
		$summary = array_fill_keys( Triage::SEVERITIES, 0 );
		foreach ( Triage::SEVERITIES as $severity ) {
			foreach ( $findings as $finding ) {
				if ( $severity === $finding['severity'] ) {
					$sorted[] = $finding;
					++$summary[ $severity ];
				}
			}
		}

		return array(
			'summary'   => $summary,
			'truncated' => count( $sorted ) > self::MAX_FINDINGS,
			'findings'  => array_slice( $sorted, 0, self::MAX_FINDINGS ),
		);
	}

	/**
	 * Links in HTML that point to single posts or pages on this site, normalized (no fragment), each once.
	 *
	 * @param string $html Post content.
	 * @param string $home Home URL.
	 * @return string[]
	 */
	public static function internal_links( string $html, string $home ): array {
		$home_parts = self::parse( $home );
		if ( ! is_array( $home_parts ) || empty( $home_parts['host'] ) ) {
			return array();
		}
		$base = ( $home_parts['scheme'] ?? 'https' ) . '://' . $home_parts['host'] . ( isset( $home_parts['port'] ) ? ':' . $home_parts['port'] : '' );
		$root = rtrim( $home_parts['path'] ?? '', '/' );

		preg_match_all( '/<a\s[^>]*href\s*=\s*(["\'])(.*?)\1/is', $html, $matches );
		$links = array();
		foreach ( $matches[2] as $href ) {
			$href = html_entity_decode( trim( $href ), ENT_QUOTES );
			if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
				$href = $base . $href;
			}
			$parts = self::parse( $href );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || strtolower( $parts['host'] ) !== strtolower( $home_parts['host'] ) ) {
				continue;
			}
			$path  = (string) ( $parts['path'] ?? '/' );
			$local = '' !== $root && 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
			$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
			if ( ( '' === trim( $local, '/' ) && '' === $query ) || 1 === preg_match( self::SKIPPED_PATHS, $local ) ) {
				continue;
			}
			$url = $base . $path . $query;
			if ( ! in_array( $url, $links, true ) ) {
				$links[] = $url;
			}
		}
		return $links;
	}

	/**
	 * Media-library images without alt text.
	 *
	 * @param array<int, array<string, mixed>> $images Images.
	 * @return array<int, array<string, mixed>>
	 */
	private static function library_images( array $images ): array {
		$out = array();
		foreach ( $images as $image ) {
			if ( '' !== trim( (string) $image['alt'] ) ) {
				continue;
			}
			$out[] = self::finding(
				'image_alt:' . $image['id'],
				'warning',
				SiteText::message( 'Image "%s" has no alt text', $image['title'] ),
				'Screen readers and search engines cannot tell what this media-library image shows. Add a short description of it as alt text.',
				self::fix( 'update_media', array( 'attachment_id' => (int) $image['id'] ), array( 'alt_text' ) )
			);
		}
		return $out;
	}

	/**
	 * Images in post content without alt text, one finding per post.
	 *
	 * @param array<int, array<string, mixed>> $posts Posts.
	 * @return array<int, array<string, mixed>>
	 */
	private static function inline_images( array $posts ): array {
		$out = array();
		foreach ( $posts as $post ) {
			preg_match_all( '/<img\b[^>]*>/i', (string) $post['content'], $tags );
			$missing = 0;
			foreach ( $tags[0] as $tag ) {
				if ( 1 !== preg_match( '/\salt\s*=\s*(["\'])\s*[^"\'\s][^"\']*\1/i', $tag ) ) {
					++$missing;
				}
			}
			if ( 0 === $missing ) {
				continue;
			}
			$out[] = self::finding(
				'inline_alt:' . $post['id'],
				'warning',
				SiteText::message( '"%s" has images without alt text', $post['title'] ),
				SiteText::message( '%d images in this %s have no alt text. Edit the %s and add alt text to each image block.', $missing, $post['type'], $post['type'] ),
				null
			);
		}
		return $out;
	}

	/**
	 * Links to this site's content that is missing or not published.
	 *
	 * @param array<int, array<string, mixed>> $posts Posts.
	 * @param string                           $home  Home URL.
	 * @param array<string, string>            $links Link URL => target status (publish, draft, trash, missing, ...).
	 * @return array<int, array<string, mixed>>
	 */
	private static function broken_links( array $posts, string $home, array $links ): array {
		$out = array();
		foreach ( $posts as $post ) {
			foreach ( self::internal_links( (string) $post['content'], $home ) as $url ) {
				$status = isset( $links[ $url ] ) ? (string) $links[ $url ] : 'publish';
				if ( 'publish' === $status ) {
					continue;
				}
				$what           = 'missing' === $status ? 'no content on this site has that address' : sprintf( 'the linked content is %s', 'trash' === $status ? 'in the trash' : $status );
				$finding        = self::finding(
					'broken_link:' . $post['id'] . ':' . substr( md5( $url ), 0, 12 ),
					'warning',
					SiteText::message( 'Broken link in "%s"', $post['title'] ),
					sprintf( 'It links to the address in url, but %s, so visitors get an error page. Update or remove the link.', $what ),
					null
				);
				$finding['url'] = $url;
				// Its own field: a URL stays exact, and prose is cleaned as text, which a query can break.
				$out[] = $finding;
			}
		}
		return $out;
	}

	/**
	 * Published content without a title.
	 *
	 * @param array<int, array<string, mixed>> $posts Posts.
	 * @return array<int, array<string, mixed>>
	 */
	private static function empty_titles( array $posts ): array {
		$out = array();
		foreach ( $posts as $post ) {
			if ( '' !== trim( (string) $post['title'] ) ) {
				continue;
			}
			$out[] = self::finding(
				'empty_title:' . $post['id'],
				'warning',
				SiteText::message( 'A published %s has no title', $post['type'] ),
				SiteText::message( '%s %d has no title, which hurts search results and shows as "(no title)".', ucfirst( (string) $post['type'] ), $post['id'] ),
				self::fix( 'update_post', array( 'post_id' => (int) $post['id'] ), array( 'title' ) )
			);
		}
		return $out;
	}

	/**
	 * Titles used by more than one published item, one finding per title.
	 *
	 * @param array<int, array<string, mixed>> $posts Posts.
	 * @return array<int, array<string, mixed>>
	 */
	private static function duplicate_titles( array $posts ): array {
		$groups = array();
		foreach ( $posts as $post ) {
			$key = strtolower( trim( (string) $post['title'] ) );
			if ( '' !== $key ) {
				$groups[ $key ][] = $post;
			}
		}
		$out = array();
		foreach ( $groups as $key => $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}
			$out[] = self::finding(
				'duplicate_title:' . substr( md5( (string) $key ), 0, 12 ),
				'warning',
				SiteText::message( '%d items share the title "%s"', count( $group ), trim( (string) $group[0]['title'] ) ),
				sprintf( 'IDs %s have the same title, so search engines may treat them as duplicates. Give each a distinct title.', implode( ', ', array_column( $group, 'id' ) ) ),
				null
			);
		}
		return $out;
	}

	/**
	 * Published content with neither an excerpt nor an SEO meta description.
	 *
	 * @param array<int, array<string, mixed>> $posts Posts.
	 * @return array<int, array<string, mixed>>
	 */
	private static function descriptions( array $posts ): array {
		$out = array();
		foreach ( $posts as $post ) {
			if ( '' !== trim( (string) $post['excerpt'] ) || '' !== trim( (string) $post['seo_description'] ) ) {
				continue;
			}
			$out[] = self::finding(
				'no_description:' . $post['id'],
				'info',
				SiteText::message( '"%s" has no description', $post['title'] ),
				'There is no excerpt and no SEO meta description, so search engines and social shares pick their own snippet. Add a one or two sentence excerpt.',
				self::fix( 'update_post', array( 'post_id' => (int) $post['id'] ), array( 'excerpt' ) )
			);
		}
		return $out;
	}

	/**
	 * Published content with fewer than THIN_WORDS words.
	 *
	 * @param array<int, array<string, mixed>> $posts Posts.
	 * @return array<int, array<string, mixed>>
	 */
	private static function thin( array $posts ): array {
		$out = array();
		foreach ( $posts as $post ) {
			$words = self::word_count( (string) $post['content'] );
			if ( null === $words || $words >= self::THIN_WORDS ) {
				continue;
			}
			$out[] = self::finding(
				'thin_content:' . $post['id'],
				'info',
				SiteText::message( '"%s" is short', $post['title'] ),
				sprintf( 'It has %d words. Pages under %d words rarely rank; expand it, merge it into a related page, or leave it if it is meant to be short (a contact page, for example).', $words, self::THIN_WORDS ),
				null
			);
		}
		return $out;
	}

	/**
	 * Published content not modified for STALE_DAYS.
	 *
	 * @param array<int, array<string, mixed>> $posts Posts.
	 * @param int                              $now   Current time.
	 * @return array<int, array<string, mixed>>
	 */
	private static function stale( array $posts, int $now ): array {
		$out = array();
		foreach ( $posts as $post ) {
			if ( null === $post['modified'] ) {
				continue;
			}
			$days = (int) floor( ( $now - (int) $post['modified'] ) / 86400 );
			if ( $days <= self::STALE_DAYS ) {
				continue;
			}
			$out[] = self::finding(
				'stale:' . $post['id'],
				'info',
				SiteText::message( '"%s" has not been updated in %d months', $post['title'], (int) floor( $days / 30 ) ),
				'Check that it is still accurate; update facts, dates and links, or unpublish it if it no longer applies.',
				null
			);
		}
		return $out;
	}

	/**
	 * Drafts not touched for OLD_DRAFT_DAYS.
	 *
	 * @param array<int, array<string, mixed>> $drafts Drafts.
	 * @param int                              $now    Current time.
	 * @return array<int, array<string, mixed>>
	 */
	private static function old_drafts( array $drafts, int $now ): array {
		$out = array();
		foreach ( $drafts as $draft ) {
			if ( null === $draft['modified'] ) {
				continue;
			}
			$days = (int) floor( ( $now - (int) $draft['modified'] ) / 86400 );
			if ( $days <= self::OLD_DRAFT_DAYS ) {
				continue;
			}
			$out[] = self::finding(
				'old_draft:' . $draft['id'],
				'info',
				SiteText::message( 'Draft "%s" was abandoned', '' === trim( (string) $draft['title'] ) ? '(no title)' : $draft['title'] ),
				sprintf( 'Nobody has touched this draft for %d months. Finish it or move it to the trash (it can be restored from there).', (int) floor( $days / 30 ) ),
				self::fix( 'trash_post', array( 'post_id' => (int) $draft['id'] ) )
			);
		}
		return $out;
	}

	/**
	 * Categories and tags with no posts, except the default category.
	 *
	 * @param array<int, array<string, mixed>> $terms            Terms.
	 * @param int                              $default_category Default category id.
	 * @return array<int, array<string, mixed>>
	 */
	private static function empty_terms( array $terms, int $default_category ): array {
		$out = array();
		foreach ( $terms as $term ) {
			if ( (int) $term['count'] > 0 || ( 'category' === $term['taxonomy'] && (int) $term['term_id'] === $default_category ) ) {
				continue;
			}
			$out[] = self::finding(
				'empty_term:' . $term['taxonomy'] . ':' . $term['term_id'],
				'info',
				SiteText::message( '%s "%s" has no posts', 'category' === $term['taxonomy'] ? 'Category' : 'Tag', $term['name'] ),
				'Its archive page is empty. Delete it, or assign posts to it.',
				self::fix(
					'delete_term',
					array(
						'term_id'  => (int) $term['term_id'],
						'taxonomy' => (string) $term['taxonomy'],
					)
				)
			);
		}
		return $out;
	}

	/**
	 * URL parts, or null.
	 *
	 * @param string $url URL.
	 * @return array<string, mixed>|null
	 */
	private static function parse( string $url ): ?array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class without WordPress loaded; PHP 7.4+ parses scheme-relative URLs correctly.
		$parts = parse_url( $url );
		return is_array( $parts ) ? $parts : null;
	}

	/**
	 * Words in HTML content.
	 *
	 * @param string $html Content.
	 * @return int|null Null when they could not be counted, which says nothing about the content.
	 */
	private static function word_count( string $html ): ?int {
		$text = preg_replace( '@<(script|style)(?=[\s/>])[^>]*+>(?:[^<]++|<(?!/\1(?=[\s/>])))*+(?:</\1(?=[\s/>])[^>]*+>|\z)@i', '', SiteText::without_open_ends( $html ) );
		$text = null === $text ? null : preg_replace( '/<[^>]*>/', ' ', $text );
		$text = null === $text ? null : preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES ) );
		if ( null === $text ) {
			return null;
		}
		$text = trim( $text );
		return '' === $text ? 0 : count( explode( ' ', $text ) );
	}

	/**
	 * A finding (Triage shape).
	 *
	 * @param string                    $id       Stable id.
	 * @param string                    $severity warning or info.
	 * @param string                    $title    One line.
	 * @param string                    $detail   What and what to do.
	 * @param array<string, mixed>|null $fix      Fix or null.
	 * @return array<string, mixed>
	 */
	private static function finding( string $id, string $severity, string $title, string $detail, ?array $fix ): array {
		return array(
			'id'       => $id,
			'severity' => $severity,
			'title'    => $title,
			'detail'   => $detail,
			'fix'      => $fix,
		);
	}

	/**
	 * A fix; `fill` names arguments the client must add (a value only a person or the model can write).
	 *
	 * @param string               $tool      Tool.
	 * @param array<string, mixed> $arguments Arguments.
	 * @param string[]             $fill      Arguments to add.
	 * @return array<string, mixed>
	 */
	private static function fix( string $tool, array $arguments, array $fill = array() ): array {
		$fix = array(
			'tool'      => $tool,
			'arguments' => $arguments,
		);
		if ( array() !== $fill ) {
			$fix['fill'] = $fill;
		}
		return $fix;
	}
}
