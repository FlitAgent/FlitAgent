<?php
/**
 * Argument checks shared by the page-builder tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Validates tool arguments. Every failure is a ToolError the model can act on.
 */
final class Args {

	/**
	 * Rejects any argument not in the allowed list.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param string[]             $allowed   Allowed names.
	 * @throws ToolError On an unknown argument.
	 */
	public static function only( array $arguments, array $allowed ): void {
		foreach ( array_keys( $arguments ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new ToolError( sprintf( 'Unknown argument: %s.', esc_textarea( $key ) ) );
			}
		}
	}

	/**
	 * The required `post_id`.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return int
	 * @throws ToolError When it is missing or not a positive integer.
	 */
	public static function post_id( array $arguments ): int {
		$post_id = $arguments['post_id'] ?? null;
		if ( ! is_int( $post_id ) || $post_id < 1 ) {
			throw new ToolError( 'post_id must be a positive integer.' );
		}
		return $post_id;
	}

	/**
	 * The required `slug`.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return string
	 * @throws ToolError When it is missing or not a non-empty string.
	 */
	public static function slug( array $arguments ): string {
		$slug = $arguments['slug'] ?? null;
		if ( ! is_string( $slug ) || '' === trim( $slug ) ) {
			throw new ToolError( 'slug must be a non-empty string.' );
		}
		return trim( $slug );
	}

	/**
	 * The optional `names` (list of strings) or `prefix` (string) filter.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array{mode: string, names: string[], prefix: string} Mode `targeted` (names), `prefix`, or `discovery` (neither).
	 * @throws ToolError When names is not a list of strings, prefix is not a string, or both are given.
	 */
	public static function filter( array $arguments ): array {
		$names  = $arguments['names'] ?? array();
		$prefix = $arguments['prefix'] ?? '';

		if ( ! is_array( $names ) || array_values( $names ) !== $names ) {
			throw new ToolError( 'names must be a list of strings.' );
		}
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || '' === $name ) {
				throw new ToolError( 'names must be a list of strings.' );
			}
		}
		if ( ! is_string( $prefix ) ) {
			throw new ToolError( 'prefix must be a string.' );
		}
		if ( array() !== $names && '' !== $prefix ) {
			throw new ToolError( 'Give names or prefix, not both.' );
		}

		$mode = 'discovery';
		if ( array() !== $names ) {
			$mode = 'targeted';
		} elseif ( '' !== $prefix ) {
			$mode = 'prefix';
		}

		return array(
			'mode'   => $mode,
			'names'  => $names,
			'prefix' => $prefix,
		);
	}

	/**
	 * Whether a name passes the filter from filter().
	 *
	 * @param string                                               $name   Control, prop or field name.
	 * @param array{mode: string, names: string[], prefix: string} $filter Filter.
	 * @return bool
	 */
	public static function matches( string $name, array $filter ): bool {
		if ( 'targeted' === $filter['mode'] ) {
			return in_array( $name, $filter['names'], true );
		}
		if ( 'prefix' === $filter['mode'] ) {
			return 0 === strpos( $name, $filter['prefix'] );
		}
		return true;
	}

	/**
	 * JSON Schema properties for the names / prefix filter.
	 *
	 * @param string $what What is being filtered, e.g. "controls".
	 * @return array<string, mixed>
	 */
	public static function filter_schema( string $what ): array {
		return array(
			'names'  => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => sprintf( 'Only these %s, with details (defaults, options, conditions). Omit both names and prefix to list every name and type only.', $what ),
			),
			'prefix' => array(
				'type'        => 'string',
				'description' => sprintf( 'Only %s whose name starts with this, with details.', $what ),
			),
		);
	}
}
