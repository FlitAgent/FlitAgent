<?php
/**
 * The search_plugin_directory tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Http;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\SiteText;
use FlitAgent\Tools\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Searches the WordPress.org plugin directory with core's plugins_api(), which does not cache, so nothing is written.
 */
final class PluginDirectorySearch implements Tool {

	/**
	 * Longest search term accepted.
	 */
	const MAX_SEARCH_LENGTH = 200;

	/**
	 * Most results per page; WordPress.org allows more, but listings stay small for the model.
	 */
	const MAX_PER_PAGE = 30;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'search_plugin_directory';
	}

	/**
	 * Protected: each call makes the site send a request to WordPress.org, which anonymous callers must not be able to
	 * trigger.
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		$string   = array( 'type' => 'string' );
		$optional = array( 'type' => array( 'string', 'null' ) );
		$integer  = array( 'type' => 'integer' );

		return array(
			'name'         => $this->name(),
			'title'        => 'Search WordPress.org plugins with FlitAgent',
			'description'  => 'From the FlitAgent WordPress plugin: searches the WordPress.org plugin directory and returns each plugin\'s name, slug, latest version, rating (0-100), number of ratings, active installs, the WordPress version it is tested up to, and required WordPress and PHP versions. It only searches; it never installs anything. Contacts api.wordpress.org. Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => 'Search terms, e.g. "contact form".',
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_PER_PAGE,
						'description' => 'Results per page, 1 to 30. Default 10.',
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Page of results. Default 1.',
					),
				),
				'required'             => array( 'search' ),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'plugins' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'              => $string,
								'slug'              => $string,
								'version'           => $string,
								'rating'            => $integer,
								'num_ratings'       => $integer,
								'active_installs'   => $integer,
								'tested_up_to'      => $optional,
								'requires_wp'       => $optional,
								'requires_php'      => $optional,
								'short_description' => $string,
							),
							'required'   => array( 'name', 'slug', 'version', 'rating', 'num_ratings', 'active_installs', 'tested_up_to', 'requires_wp', 'requires_php', 'short_description' ),
						),
					),
					'page'    => $integer,
					'pages'   => $integer,
					'total'   => $integer,
				),
				'required'   => array( 'plugins', 'page', 'pages', 'total' ),
			),
			'annotations'  => Annotations::read_only( 'Search WordPress.org plugins with FlitAgent', true ),
		);
	}

	/**
	 * Runs the search.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the arguments are invalid or WordPress.org returns an error.
	 */
	public function call( array $arguments ): array {
		foreach ( array_keys( $arguments ) as $key ) {
			if ( ! in_array( $key, array( 'search', 'per_page', 'page' ), true ) ) {
				throw new ToolError( 'Unknown argument. search_plugin_directory accepts search, per_page and page.' );
			}
		}

		$search = isset( $arguments['search'] ) && is_string( $arguments['search'] ) ? trim( $arguments['search'] ) : '';
		if ( '' === $search || strlen( $search ) > self::MAX_SEARCH_LENGTH ) {
			throw new ToolError( sprintf( 'search is required: search terms of 1 to %d characters.', (int) self::MAX_SEARCH_LENGTH ) );
		}
		$per_page = self::integer( $arguments, 'per_page', 10, 1, self::MAX_PER_PAGE );
		$page     = self::integer( $arguments, 'page', 1, 1, PHP_INT_MAX );

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$response = plugins_api(
			'query_plugins',
			array(
				'search'   => $search,
				'per_page' => $per_page,
				'page'     => $page,
				'fields'   => array(
					'short_description' => true,
					'description'       => false,
					'sections'          => false,
					'tested'            => true,
					'requires'          => true,
					'requires_php'      => true,
					'rating'            => true,
					'ratings'           => false,
					'num_ratings'       => true,
					'active_installs'   => true,
					'downloaded'        => false,
					'download_link'     => false,
					'last_updated'      => false,
					'added'             => false,
					'tags'              => false,
					'compatibility'     => false,
					'homepage'          => false,
					'donate_link'       => false,
					'icons'             => false,
					'banners'           => false,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new ToolError( 'WordPress.org plugin search failed: ' . esc_textarea( $response->get_error_message() ) );
		}

		$response = (array) $response;
		$info     = isset( $response['info'] ) ? (array) $response['info'] : array();
		$plugins  = array();
		foreach ( isset( $response['plugins'] ) ? (array) $response['plugins'] : array() as $plugin ) {
			$plugin    = (array) $plugin;
			$plugins[] = array(
				'name'              => self::text( $plugin, 'name' ),
				'slug'              => self::text( $plugin, 'slug' ),
				'version'           => self::text( $plugin, 'version' ),
				'rating'            => isset( $plugin['rating'] ) ? (int) $plugin['rating'] : 0,
				'num_ratings'       => isset( $plugin['num_ratings'] ) ? (int) $plugin['num_ratings'] : 0,
				'active_installs'   => isset( $plugin['active_installs'] ) ? (int) $plugin['active_installs'] : 0,
				'tested_up_to'      => self::optional( $plugin, 'tested' ),
				'requires_wp'       => self::optional( $plugin, 'requires' ),
				'requires_php'      => self::optional( $plugin, 'requires_php' ),
				'short_description' => self::text( $plugin, 'short_description' ),
			);
		}

		return array(
			'plugins' => $plugins,
			'page'    => isset( $info['page'] ) ? (int) $info['page'] : $page,
			'pages'   => isset( $info['pages'] ) ? (int) $info['pages'] : 0,
			'total'   => isset( $info['results'] ) ? (int) $info['results'] : 0,
		);
	}

	/**
	 * Reads an optional integer argument within bounds.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param string               $key       Name.
	 * @param int                  $fallback  Default.
	 * @param int                  $min       Minimum.
	 * @param int                  $max       Maximum.
	 * @return int
	 * @throws ToolError When the value is not an integer in range.
	 */
	private static function integer( array $arguments, string $key, int $fallback, int $min, int $max ): int {
		if ( ! array_key_exists( $key, $arguments ) || null === $arguments[ $key ] ) {
			return $fallback;
		}
		$value = $arguments[ $key ];
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			throw new ToolError( sprintf( '%s must be an integer from %d%s.', esc_textarea( $key ), (int) $min, PHP_INT_MAX === $max ? ' up' : ' to ' . esc_textarea( $max ) ) );
		}
		return $value;
	}

	/**
	 * A field as plain text: tags removed, entities decoded.
	 *
	 * @param array<string, mixed> $plugin Plugin data.
	 * @param string               $key    Field.
	 * @return string
	 */
	private static function text( array $plugin, string $key ): string {
		if ( ! isset( $plugin[ $key ] ) || ! is_scalar( $plugin[ $key ] ) ) {
			return '';
		}
		return SiteText::plain( (string) $plugin[ $key ] );
	}

	/**
	 * A version field, or null when WordPress.org has none (it sends false).
	 *
	 * @param array<string, mixed> $plugin Plugin data.
	 * @param string               $key    Field.
	 * @return string|null
	 */
	private static function optional( array $plugin, string $key ) {
		$value = self::text( $plugin, $key );
		return '' === $value ? null : $value;
	}
}
