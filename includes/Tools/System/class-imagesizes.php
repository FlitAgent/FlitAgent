<?php
/**
 * The list_image_sizes tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\System;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Lists registered image sizes (WP-CLI `media image-size`).
 */
final class ImageSizes implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_image_sizes';
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
		return Definition::read_only(
			$this->name(),
			'List image sizes with FlitAgent',
			'lists the image sizes WordPress generates for uploads: name, width, height (0 means unlimited) and whether it crops.'
		);
	}

	/**
	 * Runs the tool.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError On invalid arguments.
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array(), $this->name() );

		$sizes = array();
		foreach ( (array) wp_get_registered_image_subsizes() as $name => $size ) {
			$sizes[] = array(
				'name'   => (string) $name,
				'width'  => (int) ( $size['width'] ?? 0 ),
				'height' => (int) ( $size['height'] ?? 0 ),
				'crop'   => ! empty( $size['crop'] ),
			);
		}

		return array( 'sizes' => $sizes );
	}
}
