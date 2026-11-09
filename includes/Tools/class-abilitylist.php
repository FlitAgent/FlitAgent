<?php
/**
 * Tool: list_abilities.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the abilities registered with the WordPress Abilities API (core 6.9+).
 */
final class AbilityList implements Tool {

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
		return 'list_abilities';
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
		return array(
			'name'         => $this->name(),
			'title'        => 'List FlitAgent WordPress abilities',
			'description'  => 'From the FlitAgent WordPress plugin: lists the abilities registered with the WordPress Abilities API (WordPress 6.9+) by core and plugins, with name, label, description, category, annotations and whether each is exposed in the REST API. Pass category to narrow the list; use get_ability for one ability\'s input and output schemas. Never runs an ability. Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'category' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Optional: only abilities in this category, e.g. "site".',
					),
				),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'abilities' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => Abilities::summary_schema(),
							'required'   => array_keys( Abilities::summary_schema() ),
						),
					),
				),
				'required'   => array( 'abilities' ),
			),
			'annotations'  => Annotations::read_only( 'List FlitAgent WordPress abilities' ),
		);
	}

	/**
	 * Lists the abilities.
	 *
	 * @param array<string, mixed> $arguments Optional `category`.
	 * @return array<string, mixed>
	 * @throws ToolError When an argument is invalid or the Abilities API is missing.
	 */
	public function call( array $arguments ): array {
		foreach ( array_keys( $arguments ) as $key ) {
			if ( 'category' !== $key ) {
				throw new ToolError( 'Unknown argument. list_abilities accepts only category.' );
			}
		}
		$category = $arguments['category'] ?? null;
		if ( array_key_exists( 'category', $arguments ) && ( ! is_string( $category ) || '' === $category ) ) {
			throw new ToolError( 'category must be a non-empty string.' );
		}

		$abilities = array();
		foreach ( Abilities::all( $this->available ) as $ability ) {
			$summary = Abilities::summary( $ability );
			if ( null === $category || $category === $summary['category'] ) {
				$abilities[] = $summary;
			}
		}

		return array( 'abilities' => $abilities );
	}
}
