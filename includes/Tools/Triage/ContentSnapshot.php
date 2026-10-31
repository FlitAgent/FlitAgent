<?php
/**
 * What content QA looks at.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the content Content checks, as plain arrays: recent published items, drafts, media-library images, empty
 * categories and tags, and the status of every internal link in the published items. Uses only this site's database;
 * makes no HTTP requests and changes nothing.
 */
final class ContentSnapshot {

	/**
	 * Most images, drafts and terms read.
	 */
	const MAX_ROWS = 200;

	/**
	 * Most distinct internal links resolved.
	 */
	const MAX_LINKS = 500;

	/**
	 * The snapshot.
	 *
	 * @param string[] $types Post types to check (unknown ones are ignored).
	 * @param int      $limit Most recently modified published items to check.
	 * @return array<string, mixed>
	 */
	public static function read( array $types, int $limit ): array {
		$types = array_values( array_filter( $types, 'post_type_exists' ) );
		$home  = home_url( '/' );
		$posts = array() === $types ? array() : self::posts( $types, $limit );

		$links = array();
		foreach ( $posts as $post ) {
			foreach ( Content::internal_links( $post['content'], $home ) as $url ) {
				if ( ! isset( $links[ $url ] ) && count( $links ) < self::MAX_LINKS ) {
					$links[ $url ] = self::link_status( $url );
				}
			}
		}

		return array(
			'now'              => time(),
			'home'             => $home,
			'posts'            => $posts,
			'drafts'           => array() === $types ? array() : self::drafts( $types ),
			'images'           => self::images(),
			'terms'            => self::terms(),
			'default_category' => (int) get_option( 'default_category' ),
			'links'            => $links,
		);
	}

	/**
	 * Published items, most recently modified first.
	 *
	 * @param string[] $types Post types.
	 * @param int      $limit Most items.
	 * @return array<int, array<string, mixed>>
	 */
	private static function posts( array $types, int $limit ): array {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'   => $types,
				'post_status' => 'publish',
				'numberposts' => $limit,
				'orderby'     => 'modified',
				'order'       => 'DESC',
			)
		) as $post ) {
			$out[] = array(
				'id'              => (int) $post->ID,
				'type'            => (string) $post->post_type,
				'title'           => (string) $post->post_title,
				'excerpt'         => (string) $post->post_excerpt,
				'content'         => (string) $post->post_content,
				'modified'        => (int) strtotime( (string) $post->post_modified_gmt . ' UTC' ),
				'seo_description' => self::seo_description( (int) $post->ID ),
			);
		}
		return $out;
	}

	/**
	 * Yoast SEO or Rank Math meta description, '' when neither is set.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private static function seo_description( int $post_id ): string {
		foreach ( array( '_yoast_wpseo_metadesc', 'rank_math_description' ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Drafts, least recently modified first.
	 *
	 * @param string[] $types Post types.
	 * @return array<int, array<string, mixed>>
	 */
	private static function drafts( array $types ): array {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'   => $types,
				'post_status' => 'draft',
				'numberposts' => self::MAX_ROWS,
				'orderby'     => 'modified',
				'order'       => 'ASC',
			)
		) as $post ) {
			$out[] = array(
				'id'       => (int) $post->ID,
				'type'     => (string) $post->post_type,
				'title'    => (string) $post->post_title,
				'modified' => (int) strtotime( (string) $post->post_modified_gmt . ' UTC' ),
			);
		}
		return $out;
	}

	/**
	 * Media-library images, newest first, with their alt text.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function images(): array {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'numberposts'    => self::MAX_ROWS,
			)
		) as $image ) {
			$out[] = array(
				'id'    => (int) $image->ID,
				'title' => (string) $image->post_title,
				'alt'   => (string) get_post_meta( (int) $image->ID, '_wp_attachment_image_alt', true ),
			);
		}
		return $out;
	}

	/**
	 * Categories and tags with their post counts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function terms(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => array( 'category', 'post_tag' ),
				'hide_empty' => false,
				'number'     => self::MAX_ROWS,
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$out[] = array(
				'taxonomy' => (string) $term->taxonomy,
				'term_id'  => (int) $term->term_id,
				'name'     => (string) $term->name,
				'count'    => (int) $term->count,
			);
		}
		return $out;
	}

	/**
	 * Status of the content an internal link points to: a post status, or `missing`.
	 *
	 * @param string $url Internal link.
	 * @return string
	 */
	private static function link_status( string $url ): string {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid -- Uses the VIP cached variant when present.
		$id = function_exists( 'wpcom_vip_url_to_postid' ) ? (int) wpcom_vip_url_to_postid( $url ) : url_to_postid( $url );
		if ( $id > 0 ) {
			return (string) get_post_status( $id );
		}

		// A trashed or unpublished item's address no longer resolves; find it by its slug.
		$slug = sanitize_title( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( '' === $slug ) {
			return 'missing';
		}
		$found = get_posts(
			array(
				'name'        => $slug,
				'post_type'   => 'any',
				'post_status' => array( 'draft', 'pending', 'private', 'future' ),
				'numberposts' => 1,
			)
		);
		if ( array() === $found ) {
			$found = get_posts(
				array(
					'name'        => $slug . '__trashed',
					'post_type'   => 'any',
					'post_status' => 'trash',
					'numberposts' => 1,
				)
			);
		}
		return array() === $found ? 'missing' : (string) $found[0]->post_status;
	}
}
