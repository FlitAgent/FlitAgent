<?php
/**
 * MCP definitions for the page-builder registry tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Tools\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a read-only tool definition.
 */
final class Definition {

	/**
	 * Builds the definition.
	 *
	 * @param string               $name        Tool name.
	 * @param string               $title       Title, naming FlitAgent.
	 * @param string               $description What it returns, after the shared prefix.
	 * @param array<string, mixed> $inputs      Input properties.
	 * @param string[]             $required    Required inputs.
	 * @param array<string, mixed> $outputs     Output properties, all required, in output order.
	 * @return array<string, mixed>
	 */
	public static function read_only( string $name, string $title, string $description, array $inputs, array $required, array $outputs ): array {
		$input_schema = array(
			'type'                 => 'object',
			'properties'           => (object) $inputs,
			'additionalProperties' => false,
		);
		if ( array() !== $required ) {
			$input_schema['required'] = $required;
		}

		return array(
			'name'         => $name,
			'title'        => $title,
			'description'  => 'From the FlitAgent WordPress plugin: ' . $description,
			'inputSchema'  => $input_schema,
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => $outputs,
				'required'   => array_keys( $outputs ),
			),
			'annotations'  => Annotations::read_only( $title ),
		);
	}
}
