<?php
/**
 * Tool: get_post_meta.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a post's meta, with secret and personal values masked.
 */
final class PostMeta implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_post_meta';
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
		return array(
			'name'         => $this->name(),
			'title'        => 'Read FlitAgent post meta',
			'description'  => 'From the FlitAgent WordPress plugin: reads the custom fields (meta) of any post, page or custom post type item by ID, including hidden keys starting with "_", like WP-CLI `post meta list`. Pass key to read one meta key. Values under secret-looking keys (passwords, keys, tokens, sessions) or email keys are masked. Requires an administrator login.',
			'inputSchema'  => self::input_schema( 'post_id', 'Post ID.' ),
			'outputSchema' => self::output_schema( 'post_id' ),
			'annotations'  => Annotations::read_only( 'Read FlitAgent post meta' ),
		);
	}

	/**
	 * Reads the meta.
	 *
	 * @param array<string, mixed> $arguments `post_id` and optional `key`.
	 * @return array<string, mixed>
	 * @throws ToolError When an argument is invalid or the post does not exist.
	 */
	public function call( array $arguments ): array {
		list( $post_id, $key ) = MetaEntries::arguments( $this->name(), 'post_id', $arguments );

		if ( null === get_post( $post_id ) ) {
			throw new ToolError( sprintf( 'No post found with ID %d.', (int) $post_id ) );
		}

		$meta = get_post_meta( $post_id, $key, false );

		return array(
			'post_id' => $post_id,
			'meta'    => MetaEntries::entries( is_array( $meta ) ? $meta : array(), $key ),
		);
	}

	/**
	 * Input schema shared by the post and user meta tools.
	 *
	 * @param string $id_name     ID argument name.
	 * @param string $description ID description.
	 * @return array<string, mixed>
	 */
	public static function input_schema( string $id_name, string $description ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				$id_name => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => $description,
				),
				'key'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 255,
					'description' => 'Optional: one meta key to read. Omit to read every key.',
				),
			),
			'required'             => array( $id_name ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Output schema shared by the post and user meta tools.
	 *
	 * @param string $id_name ID field name.
	 * @return array<string, mixed>
	 */
	public static function output_schema( string $id_name ): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				$id_name => array( 'type' => 'integer' ),
				'meta'   => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'   => array( 'type' => 'string' ),
							'values' => array(
								'type'        => 'array',
								'description' => 'Every value stored under this key, unserialized. Secret values are "' . Redactor::MASK . '".',
							),
						),
						'required'   => array( 'name', 'values' ),
					),
				),
			),
			'required'   => array( $id_name, 'meta' ),
		);
	}
}
