<?php
/**
 * Tool: run_readonly_ability.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one WordPress ability (Abilities API, core 6.9+) that its registering code declares read-only
 * (`annotations.readonly: true`), through `WP_Ability::execute()`: input validation and the ability's own permission
 * check included. Any other ability goes through the `run_ability` write tool, which needs an administrator's approval.
 */
final class RunReadonlyAbility implements Tool {

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
		return 'run_readonly_ability';
	}

	/**
	 * Protected: abilities may return data only administrators should see.
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
			'title'        => 'Run an FlitAgent read-only WordPress ability',
			'description'  => 'From the FlitAgent WordPress plugin: runs one ability registered with the WordPress Abilities API (WordPress 6.9+) that declares itself read-only (annotations.readonly true), e.g. "core/get-site-info", with optional input matching its input schema (get_ability shows it). The ability\'s own permission check applies. Secret-looking values in the output are masked; output over 512 KB is replaced by an excerpt. Abilities that change anything are refused here: use run_ability, which needs an administrator\'s approval. Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'  => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Ability name, e.g. "core/get-site-info". list_abilities lists them.',
					),
					'input' => array(
						'type'        => 'object',
						'description' => 'Input for the ability, matching its input schema. Leave out for abilities that take none.',
					),
				),
				'required'             => array( 'name' ),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'ability'   => array( 'type' => 'string' ),
					'output'    => array( 'description' => 'What the ability returned, with secret-looking values masked; null when truncated.' ),
					'truncated' => array( 'type' => 'boolean' ),
					'excerpt'   => array(
						'type'        => 'string',
						'description' => 'Start of the output JSON, only when truncated.',
					),
					'bytes'     => array(
						'type'        => 'integer',
						'description' => 'Size of the full output JSON, only when truncated.',
					),
				),
				'required'   => array( 'ability', 'output', 'truncated' ),
			),
			'annotations'  => Annotations::read_only( 'Run an FlitAgent read-only WordPress ability', true ),
		);
	}

	/**
	 * Runs the ability.
	 *
	 * @param array<string, mixed> $arguments `name`, optional `input`.
	 * @return array<string, mixed>
	 * @throws ToolError When an argument is invalid, the ability is unknown, not read-only or fails.
	 */
	public function call( array $arguments ): array {
		foreach ( array_keys( $arguments ) as $key ) {
			if ( ! in_array( $key, array( 'name', 'input' ), true ) ) {
				throw new ToolError( 'Unknown argument. run_readonly_ability accepts only name and input.' );
			}
		}
		$name = $arguments['name'] ?? null;
		if ( ! is_string( $name ) || '' === $name ) {
			throw new ToolError( 'name must be an ability name such as "core/get-site-info".' );
		}
		$input   = AbilityRunner::input( $arguments );
		$ability = AbilityRunner::find( $name, $this->available );
		if ( null === $ability ) {
			throw new ToolError( sprintf( 'No ability named "%s". Use list_abilities to see the registered abilities.', esc_textarea( $name ) ) );
		}
		if ( ! AbilityRunner::is_readonly( $ability ) ) {
			throw new ToolError( sprintf( 'The ability "%s" does not declare itself read-only, so it can change the site; use run_ability, which needs an administrator\'s approval.', esc_textarea( $name ) ) );
		}

		$output = $ability->execute( $input );
		if ( is_wp_error( $output ) ) {
			throw new ToolError( sprintf( 'The ability "%s" failed: %s', esc_textarea( $name ), esc_textarea( AbilityRunner::message( $output ) ) ) );
		}
		return array_merge( array( 'ability' => $name ), AbilityRunner::result( $output ) );
	}
}
