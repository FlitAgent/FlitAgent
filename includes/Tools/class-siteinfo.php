<?php
/**
 * Tool: get_site_info.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;

defined( 'ABSPATH' ) || exit;

/**
 * Returns basic, already-public information about the site.
 */
final class SiteInfo implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_site_info';
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return Tool::ACCESS_PUBLIC;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		$nullable_string = array( 'type' => array( 'string', 'null' ) );
		$string          = array( 'type' => 'string' );

		return array(
			'name'         => $this->name(),
			'title'        => 'Get FlitAgent site info',
			'description'  => 'From the FlitAgent WordPress plugin: returns basic public information about this WordPress site: title, tagline, home URL, language, timezone and site icon URL.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'name'          => $string,
					'description'   => $string,
					'url'           => $string,
					'language'      => $string,
					'timezone'      => $string,
					'site_icon_url' => $nullable_string,
					'generator'     => $string,
				),
				'required'   => array( 'name', 'description', 'url', 'language', 'timezone', 'site_icon_url', 'generator' ),
			),
			'annotations'  => array(
				'title'           => 'Get FlitAgent site info',
				'readOnlyHint'    => true,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			),
		);
	}

	/**
	 * Returns the public site data. Takes no arguments.
	 *
	 * @param array<string, mixed> $arguments Ignored.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array(), $this->name() );

		$icon = get_site_icon_url();

		return array(
			'name'          => SiteText::plain( (string) get_bloginfo( 'name' ) ),
			'description'   => SiteText::plain( (string) get_bloginfo( 'description' ) ),
			'url'           => home_url( '/' ),
			'language'      => get_bloginfo( 'language' ),
			'timezone'      => wp_timezone_string(),
			'site_icon_url' => $icon ? $icon : null,
			'generator'     => 'WordPress',
		);
	}
}
