<?php
/**
 * The get_theme_mods tool.
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
 * Reads the active theme's mods (WP-CLI `theme mod list` / `theme mod get`).
 */
final class ThemeMods implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_theme_mods';
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
			'Get theme mods with FlitAgent',
			'reads the active theme\'s customizer settings (theme mods), all of them or one by name, with secret-looking fields redacted.',
			array(
				'name' => array(
					'type'        => 'string',
					'description' => 'One theme mod, e.g. "custom_logo". Omit for all.',
				),
			)
		);
	}

	/**
	 * Reads the theme mods.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the named mod is not set.
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array( 'name' ), $this->name() );
		$name = Arguments::string( $arguments, 'name', false );

		$mods = get_theme_mods();
		$mods = is_array( $mods ) ? $mods : array();

		if ( null !== $name ) {
			if ( ! array_key_exists( $name, $mods ) ) {
				throw new ToolError( sprintf( 'Theme mod "%s" is not set for the active theme.', esc_textarea( $name ) ) );
			}
			$mods = array( $name => $mods[ $name ] );
		}

		return array(
			'theme' => get_stylesheet(),
			'mods'  => Redactor::redact( $mods ),
		);
	}
}
