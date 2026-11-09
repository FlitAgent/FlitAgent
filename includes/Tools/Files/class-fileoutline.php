<?php
/**
 * The get_file_outline tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Files;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Outlines one theme or plugin file: PHP symbols and hooks, JavaScript functions, CSS at-rules.
 */
final class FileOutline extends FileTool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_file_outline';
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		return array(
			'name'         => $this->name(),
			'title'        => 'Outline a theme or plugin file with FlitAgent',
			'description'  => 'From the FlitAgent WordPress plugin: outlines one file from the active theme, its parent theme, or the themes, plugins or mu-plugins directory, with line numbers, so you can read_file just the part you need. PHP (parsed with the tokenizer, never run): namespace, classes, interfaces, traits, enums, functions, methods, and hooks added, removed or fired with a literal name. JavaScript: function declarations. CSS, SCSS, Less: @media, @supports, @container, @layer and @keyframes. Other text files return an empty outline with supported false. Same limits as read_file. Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'root' => self::root_schema(),
					'path' => array(
						'type'        => 'string',
						'description' => 'File path relative to the root, e.g. "functions.php".',
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'root'        => array( 'type' => 'string' ),
					'path'        => array( 'type' => 'string' ),
					'language'    => array(
						'type' => 'string',
						'enum' => array( 'php', 'js', 'css', 'other' ),
					),
					'supported'   => array( 'type' => 'boolean' ),
					'total_lines' => array( 'type' => 'integer' ),
					'outline'     => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'type' => array(
									'type'        => 'string',
									'description' => 'namespace, class, interface, trait, enum, function, method, hook, media, supports, container, layer or keyframes.',
								),
								'name' => array( 'type' => 'string' ),
								'line' => array( 'type' => 'integer' ),
								'call' => array(
									'type'        => 'string',
									'description' => 'For hooks: add_action, add_filter, remove_action, remove_filter, do_action, do_action_ref_array, apply_filters or apply_filters_ref_array.',
								),
							),
							'required'   => array( 'type', 'name', 'line' ),
						),
					),
				),
				'required'   => array( 'root', 'path', 'language', 'supported', 'total_lines', 'outline' ),
			),
			'annotations'  => Annotations::read_only( 'Outline a theme or plugin file with FlitAgent' ),
		);
	}

	/**
	 * Outlines the file.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the arguments are invalid or the file may not be read.
	 */
	public function call( array $arguments ): array {
		$this->reject_unknown( $arguments, array( 'root', 'path' ) );

		$root     = Sandbox::root_argument( $arguments );
		$file     = $this->sandbox()->resolve_file( $root, isset( $arguments['path'] ) ? $arguments['path'] : '' );
		$text     = Sandbox::read_text( $file['real'] );
		$language = Outline::language( $file['path'] );
		$outline  = Outline::build( $language, $text );

		foreach ( $outline as $index => $item ) {
			$outline[ $index ]['name'] = self::redact( $item['name'] );
		}

		return array(
			'root'        => $root,
			'path'        => $file['path'],
			'language'    => $language,
			'supported'   => 'other' !== $language,
			'total_lines' => count( ReadFile::lines( $text ) ),
			'outline'     => $outline,
		);
	}
}
