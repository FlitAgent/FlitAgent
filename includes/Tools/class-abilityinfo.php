<?php
/**
 * Tool: get_ability.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one ability registered with the WordPress Abilities API (core 6.9+), including its schemas.
 */
final class AbilityInfo implements Tool {

	/**
	 * Returns whether the Abilities API exists; null checks for wp_get_abilities().
	 *
	 * @var callable|null
	 */
	private $available;

	/**
	 * Constructor.
	 *
	 * @param callable|null $available Availability check, for tests; null detects wp_get_abilities().
	 */
	public function __construct( ?callable $available = null ) {
		$this->available = $available;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_ability';
	}

	/**
	 * Protected: the registry includes abilities their authors hid from the REST API.
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		$schema = array(
			'type'        => array( 'object', 'null' ),
			'description' => 'JSON Schema as the ability declares it; null when it has none.',
		);

		return array(
			'name'         => $this->name(),
			'title'        => 'Read an FlitAgent WordPress ability',
			'description'  => 'From the FlitAgent WordPress plugin: describes one ability registered with the WordPress Abilities API (WordPress 6.9+) by name, e.g. "core/get-site-info": label, description, category, annotations, REST exposure, input and output JSON schemas, and its other metadata with secret-looking values masked. Never runs the ability. Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'name' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Ability name, e.g. "core/get-site-info". list_abilities lists them.',
					),
				),
				'required'             => array( 'name' ),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array_merge(
					Abilities::summary_schema(),
					array(
						'input_schema'  => $schema,
						'output_schema' => $schema,
						'meta'          => array(
							'type'        => 'object',
							'description' => 'Other registration metadata, with secret-looking values masked.',
						),
					)
				),
				'required'   => array_merge( array_keys( Abilities::summary_schema() ), array( 'input_schema', 'output_schema', 'meta' ) ),
			),
			'annotations'  => Annotations::read_only( 'Read an FlitAgent WordPress ability' ),
		);
	}

	/**
	 * Describes the ability.
	 *
	 * @param array<string, mixed> $arguments `name`.
	 * @return array<string, mixed>
	 * @throws ToolError When an argument is invalid, the ability is unknown or the Abilities API is missing.
	 */
	public function call( array $arguments ): array {
		foreach ( array_keys( $arguments ) as $key ) {
			if ( 'name' !== $key ) {
				throw new ToolError( 'Unknown argument. get_ability accepts only name.' );
			}
		}
		$name = $arguments['name'] ?? null;
		if ( ! is_string( $name ) || '' === $name ) {
			throw new ToolError( 'name must be an ability name such as "core/get-site-info".' );
		}

		// Looked up in the full list: wp_get_ability() reports an unknown name as a developer notice.
		foreach ( Abilities::all( $this->available ) as $ability ) {
			if ( $name !== $ability->get_name() ) {
				continue;
			}
			return array_merge(
				Abilities::summary( $ability ),
				array(
					'input_schema'  => self::schema( $ability->get_input_schema() ),
					'output_schema' => self::schema( $ability->get_output_schema() ),
					'meta'          => Redactor::redact( Abilities::meta( $ability ) ),
				)
			);
		}

		throw new ToolError( sprintf( 'No ability named "%s". Use list_abilities to see the registered abilities.', esc_textarea( $name ) ) );
	}

	/**
	 * A declared schema, or null when empty. Schemas describe arguments; they are code, not stored values.
	 *
	 * @param mixed $schema Schema.
	 * @return array<string, mixed>|null
	 */
	private static function schema( $schema ): ?array {
		return is_array( $schema ) && array() !== $schema ? $schema : null;
	}
}
