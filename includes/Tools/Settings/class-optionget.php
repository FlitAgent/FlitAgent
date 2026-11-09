<?php
/**
 * The get_option tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Settings;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;
use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Reads one option, optionally one nested value (WP-CLI `option get` / `option pluck`).
 */
final class OptionGet implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_option';
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
		return Definition::read_only(
			$this->name(),
			'Get an option with FlitAgent',
			'reads one WordPress option and its type. With path (e.g. ["a","b"]) returns one nested value, like WP-CLI option pluck. Options whose names look secret are refused and secret-looking nested fields are redacted. json_text is true when the value is a string holding JSON.',
			array(
				'name' => array(
					'type'        => 'string',
					'description' => 'Option name, e.g. "blogname".',
				),
				'path' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Keys to walk into an array or object value.',
				),
			),
			array( 'name' )
		);
	}

	/**
	 * Reads the option.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the name is refused or the option or path does not exist.
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array( 'name', 'path' ), $this->name() );
		$name = (string) Arguments::string( $arguments, 'name', true );
		$path = Arguments::strings( $arguments, 'path' );
		Names::check( $name, 'option' );

		$missing = new \stdClass();
		$value   = get_option( $name, $missing );
		if ( $missing === $value ) {
			throw new ToolError( sprintf( 'Option "%s" not found.', esc_textarea( $name ) ) );
		}

		foreach ( (array) $path as $key ) {
			if ( is_object( $value ) ) {
				$value = get_object_vars( $value );
			}
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				throw new ToolError( sprintf( 'Option "%s" has no value at path %s.', esc_textarea( $name ), esc_textarea( implode( ' > ', $path ) ) ) );
			}
			if ( Redactor::is_secret_key( $key ) ) {
				throw new ToolError( sprintf( 'The path key "%s" is refused: it looks like it holds a secret.', esc_textarea( $key ) ) );
			}
			$value = $value[ $key ];
		}

		return array(
			'name'      => $name,
			'type'      => Values::type( $value ),
			'value'     => Redactor::redact( $value ),
			'json_text' => Values::is_json_text( $value ),
		);
	}
}
