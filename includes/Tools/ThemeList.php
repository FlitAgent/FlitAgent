<?php
/**
 * Tool: list_themes.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;

defined( 'ABSPATH' ) || exit;

/**
 * Lists installed themes, which one is active, and any update WordPress already found.
 */
final class ThemeList implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_themes';
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		$string = array( 'type' => 'string' );

		return array(
			'name'         => $this->name(),
			'title'        => 'List FlitAgent site themes',
			'description'  => 'From the FlitAgent WordPress plugin: lists the installed themes with name, slug, version, which theme is active (and its parent theme, if it is a child theme), and whether an update is available (from the last update check WordPress saved; this tool never checks for updates itself). Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'themes'             => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'             => $string,
								'slug'             => $string,
								'version'          => $string,
								'status'           => array(
									'type'        => 'string',
									'enum'        => array( 'active', 'parent', 'inactive' ),
									'description' => 'parent: the parent of the active child theme, so also in use.',
								),
								'update_available' => array( 'type' => 'boolean' ),
								'new_version'      => array( 'type' => array( 'string', 'null' ) ),
							),
							'required'   => array( 'name', 'slug', 'version', 'status', 'update_available', 'new_version' ),
						),
					),
					'active_theme'       => $string,
					'updates_checked_at' => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'When WordPress last checked for updates (ISO 8601, UTC); null if it has not checked yet.',
					),
				),
				'required'   => array( 'themes', 'active_theme', 'updates_checked_at' ),
			),
			'annotations'  => Annotations::read_only( 'List FlitAgent site themes' ),
		);
	}

	/**
	 * Lists the themes. Takes no arguments.
	 *
	 * @param array<string, mixed> $arguments Ignored.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		$active   = (string) get_stylesheet();
		$template = (string) get_template();
		$updates  = new CachedUpdates( 'update_themes' );
		$themes   = array();

		foreach ( wp_get_themes() as $slug => $theme ) {
			$slug        = (string) $slug;
			$version     = (string) $theme->get( 'Version' );
			$new_version = $updates->new_version( $slug, $version );

			$themes[] = array(
				'name'             => (string) $theme->get( 'Name' ),
				'slug'             => $slug,
				'version'          => $version,
				'status'           => self::status( $slug, $active, $template ),
				'update_available' => null !== $new_version,
				'new_version'      => $new_version,
			);
		}

		return array(
			'themes'             => $themes,
			'active_theme'       => $active,
			'updates_checked_at' => $updates->checked_at(),
		);
	}

	/**
	 * Whether a theme is the active theme, the parent of an active child theme, or unused.
	 *
	 * @param string $slug     Theme slug (stylesheet).
	 * @param string $active   Active theme slug.
	 * @param string $template Active theme's template (itself when not a child theme).
	 * @return string
	 */
	private static function status( string $slug, string $active, string $template ): string {
		if ( $slug === $active ) {
			return 'active';
		}
		return $slug === $template ? 'parent' : 'inactive';
	}
}
