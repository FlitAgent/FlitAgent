<?php
/**
 * Tool: get_registered_meta.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Lists meta keys registered with register_meta(), without their callbacks.
 */
final class RegisteredMeta implements Tool {

	/**
	 * Object types that can register meta.
	 *
	 * @var string[]
	 */
	const OBJECT_TYPES = array( 'post', 'comment', 'term', 'user' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_registered_meta';
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
			'title'        => 'List FlitAgent registered meta',
			'description'  => 'From the FlitAgent WordPress plugin: lists the meta keys registered with register_meta() for posts, comments, terms and users, with subtype (post type or taxonomy; empty for all), type, description, single, whether the key is exposed in the REST API, and its default. Secret-looking defaults are masked. Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'object_type' => array(
						'type'        => 'string',
						'enum'        => self::OBJECT_TYPES,
						'description' => 'Optional: only keys for this object type.',
					),
				),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'meta' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'object_type'  => $string,
								'subtype'      => $string,
								'name'         => $string,
								'type'         => $string,
								'description'  => $string,
								'single'       => array( 'type' => 'boolean' ),
								'show_in_rest' => array( 'type' => 'boolean' ),
								'default'      => array( 'description' => 'Default value; null when none is registered.' ),
							),
							'required'   => array( 'object_type', 'subtype', 'name', 'type', 'description', 'single', 'show_in_rest', 'default' ),
						),
					),
				),
				'required'   => array( 'meta' ),
			),
			'annotations'  => Annotations::read_only( 'List FlitAgent registered meta' ),
		);
	}

	/**
	 * Lists the registrations.
	 *
	 * @param array<string, mixed> $arguments Optional `object_type`.
	 * @return array<string, mixed>
	 * @throws ToolError When an argument is invalid.
	 */
	public function call( array $arguments ): array {
		foreach ( array_keys( $arguments ) as $name ) {
			if ( 'object_type' !== $name ) {
				throw new ToolError( 'Unknown argument. get_registered_meta accepts only object_type.' );
			}
		}
		$only = $arguments['object_type'] ?? null;
		if ( array_key_exists( 'object_type', $arguments ) && ! in_array( $only, self::OBJECT_TYPES, true ) ) {
			throw new ToolError( 'object_type must be one of post, comment, term, user.' );
		}

		// Core has no getter that lists subtypes, so they come from the registry register_meta() fills; the keys come
		// from get_registered_meta_keys() (subtype argument since core 4.9.8).
		$registry = isset( $GLOBALS['wp_meta_keys'] ) && is_array( $GLOBALS['wp_meta_keys'] ) ? $GLOBALS['wp_meta_keys'] : array();

		$meta = array();
		foreach ( $registry as $object_type => $subtypes ) {
			if ( ! is_array( $subtypes ) || ( null !== $only && $only !== $object_type ) ) {
				continue;
			}
			foreach ( array_keys( $subtypes ) as $subtype ) {
				foreach ( get_registered_meta_keys( (string) $object_type, (string) $subtype ) as $name => $args ) {
					$meta[] = self::entry( (string) $object_type, (string) $subtype, (string) $name, (array) $args );
				}
			}
		}

		return array( 'meta' => $meta );
	}

	/**
	 * Builds one entry. Only these fields leave the site: never sanitize or auth callbacks.
	 *
	 * @param string               $object_type Object type.
	 * @param string               $subtype     Subtype, '' for all.
	 * @param string               $name        Meta key.
	 * @param array<string, mixed> $args        Registration arguments.
	 * @return array<string, mixed>
	 */
	private static function entry( string $object_type, string $subtype, string $name, array $args ): array {
		$default = array_key_exists( 'default', $args ) ? $args['default'] : null;
		if ( MetaEntries::is_masked_key( $name ) ) {
			$default = Redactor::redact( array( 'secret' => $default ) )['secret'];
		}

		return array(
			'object_type'  => $object_type,
			'subtype'      => $subtype,
			'name'         => $name,
			'type'         => isset( $args['type'] ) ? (string) $args['type'] : 'string',
			'description'  => isset( $args['description'] ) ? (string) $args['description'] : '',
			'single'       => ! empty( $args['single'] ),
			'show_in_rest' => ! empty( $args['show_in_rest'] ),
			'default'      => Redactor::redact( $default ),
		);
	}
}
