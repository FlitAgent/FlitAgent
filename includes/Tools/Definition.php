<?php
/**
 * Tool definition builder.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the MCP definition of a read-only FlitAgent tool.
 */
final class Definition {

	/**
	 * Definition with read-only annotations and a closed input schema.
	 *
	 * @param string               $name        Tool name.
	 * @param string               $title       Title (names FlitAgent).
	 * @param string               $description Description (starts "From the FlitAgent WordPress plugin: ").
	 * @param array<string, mixed> $properties  Input properties.
	 * @param string[]             $required    Required input names.
	 * @return array<string, mixed>
	 */
	public static function read_only( string $name, string $title, string $description, array $properties = array(), array $required = array() ): array {
		$input = array(
			'type'                 => 'object',
			'properties'           => array() === $properties ? new \stdClass() : $properties,
			'additionalProperties' => false,
		);
		if ( array() !== $required ) {
			$input['required'] = $required;
		}

		$annotations = Annotations::read_only( $title );

		return array(
			'name'        => $name,
			'title'       => $title,
			'description' => 'From the FlitAgent WordPress plugin: ' . $description,
			'inputSchema' => $input,
			'annotations' => $annotations,
		);
	}
}
