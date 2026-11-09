<?php
/**
 * Base for the tools that read one post's page-builder layout.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\SiteText;
use FlitAgent\Tools\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a post's layout from the builder's stored data. Protected; the caller must also be able to edit the post.
 */
abstract class PageTool implements Tool {

	/**
	 * Builder name for messages, e.g. "Elementor".
	 *
	 * @return string
	 */
	abstract protected function builder(): string;

	/**
	 * Tool-specific description, after the shared prefix.
	 *
	 * @return string
	 */
	abstract protected function describe(): string;

	/**
	 * Extra input properties besides post_id.
	 *
	 * @return array<string, mixed>
	 */
	protected function extra_inputs(): array {
		return array();
	}

	/**
	 * Validates extra arguments before anything is read.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @return array<string, mixed> Validated extra arguments.
	 * @throws ToolError On an invalid argument.
	 */
	protected function extra_arguments( array $arguments ): array {
		return array();
	}

	/**
	 * Output properties besides id, title, type and status, in output order.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function layout_schema(): array;

	/**
	 * Reads the layout fields, or null when the post has no layout for this builder.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $extra   Validated extra arguments.
	 * @return array<string, mixed>|null
	 */
	abstract protected function layout( int $post_id, array $extra ): ?array;

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
		$title = sprintf( 'Get FlitAgent %s page layout', $this->builder() );

		return array(
			'name'         => $this->name(),
			'title'        => $title,
			'description'  => sprintf( 'From the FlitAgent WordPress plugin: returns one post\'s %s layout as saved by the builder, read-only. ', $this->builder() ) . $this->describe() . ' Works for any status the caller can edit (drafts and private posts included). Secret-looking settings are redacted.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array_merge(
					array(
						'post_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'ID of the post, page or template.',
						),
					),
					$this->extra_inputs()
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array_merge(
					array(
						'id'     => array( 'type' => 'integer' ),
						'title'  => array( 'type' => 'string' ),
						'type'   => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string' ),
					),
					$this->layout_schema()
				),
				'required'   => array_merge( array( 'id', 'title', 'type', 'status' ), array_keys( $this->layout_schema() ) ),
			),
			'annotations'  => Annotations::read_only( $title ),
		);
	}

	/**
	 * Reads the layout.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When an argument is invalid, the post cannot be read, or it has no layout.
	 */
	public function call( array $arguments ): array {
		Args::only( $arguments, array_merge( array( 'post_id' ), array_keys( $this->extra_inputs() ) ) );
		$post_id = Args::post_id( $arguments );
		$extra   = $this->extra_arguments( $arguments );

		$post = get_post( $post_id );
		if ( ! is_object( $post ) || ! current_user_can( 'edit_post', $post_id ) ) {
			throw new ToolError( sprintf( 'Post %d was not found, or you cannot edit it.', (int) $post_id ) );
		}

		$layout = $this->layout( $post_id, $extra );
		if ( null === $layout ) {
			throw new ToolError( sprintf( 'Post %d has no %s layout.', (int) $post_id, esc_textarea( $this->builder() ) ) );
		}

		return Output::finish(
			array_merge(
				array(
					'id'     => $post_id,
					'title'  => SiteText::plain( (string) $post->post_title ),
					'type'   => (string) $post->post_type,
					'status' => (string) $post->post_status,
				),
				$layout
			)
		);
	}

	/**
	 * A post meta value from the builder's own key, as WordPress returns it.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return mixed
	 */
	protected static function meta( int $post_id, string $key ) {
		return get_post_meta( $post_id, $key, true );
	}

	/**
	 * A meta value as a string, or null when unset.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string|null
	 */
	protected static function meta_string( int $post_id, string $key ): ?string {
		$value = self::meta( $post_id, $key );
		return is_scalar( $value ) && '' !== (string) $value ? (string) $value : null;
	}
}
