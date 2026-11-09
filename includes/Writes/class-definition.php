<?php
/**
 * Write tool definition builder.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the MCP definition of a write tool: a closed input schema with `idempotency_key` (required) and `dry_run`
 * added, and explicit write annotations.
 */
final class Definition {

	/**
	 * Pattern every idempotency key must match.
	 */
	const KEY_PATTERN = '^[A-Za-z0-9._:-]{8,128}$';

	/**
	 * Property names a write tool may not declare: the Gateway owns idempotency and dry runs, and only the server
	 * derives tiers and approvals.
	 */
	const RESERVED = '/^(idempotency_key|dry_run)$|tier|risk|approv|confirm|force|bypass/i';

	/**
	 * The `idempotency_key` property.
	 *
	 * @return array<string, mixed>
	 */
	public static function key_property(): array {
		return array(
			'type'        => 'string',
			'pattern'     => self::KEY_PATTERN,
			'description' => 'A new random key per intended change (8-128 of A-Z a-z 0-9 . _ : -). Repeating a key with the same arguments returns the first result instead of writing again.',
		);
	}

	/**
	 * Definition of a write tool.
	 *
	 * @param string               $name        Tool name.
	 * @param string               $title       Title (names FlitAgent).
	 * @param string               $description Description, without the "From the FlitAgent WordPress plugin: " prefix.
	 * @param array<string, mixed> $properties  Input properties (no reserved names).
	 * @param string[]             $required    Required input names.
	 * @param string[]             $tiers       Tiers the tool can assess to (see WriteTool::tiers()).
	 * @param bool                 $open_world  Whether the write reaches services outside this site.
	 * @return array<string, mixed>
	 * @throws \LogicException When a property name is reserved.
	 */
	public static function write( string $name, string $title, string $description, array $properties, array $required, array $tiers, bool $open_world ): array {
		foreach ( array_keys( $properties ) as $property ) {
			if ( 1 === preg_match( self::RESERVED, (string) $property ) ) {
				throw new \LogicException( sprintf( 'Write tool %s may not declare the reserved property %s.', esc_textarea( $name ), esc_textarea( $property ) ) );
			}
		}

		$properties['idempotency_key'] = self::key_property();
		$properties['dry_run']         = array(
			'type'        => 'boolean',
			'description' => 'true returns the tier, summary, preview and whether approval is needed, and writes nothing.',
		);
		$required[]                    = 'idempotency_key';

		return array(
			'name'        => $name,
			'title'       => $title,
			'description' => 'From the FlitAgent WordPress plugin: ' . $description,
			'inputSchema' => array(
				'type'                 => 'object',
				'properties'           => $properties,
				'required'             => $required,
				'additionalProperties' => false,
			),
			'annotations' => array(
				'title'           => $title,
				'readOnlyHint'    => false,
				'destructiveHint' => in_array( Risk::DESTRUCTIVE, $tiers, true ),
				'idempotentHint'  => true,
				'openWorldHint'   => $open_world,
			),
		);
	}
}
