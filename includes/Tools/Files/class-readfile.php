<?php
/**
 * The read_file tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Files;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Reads one theme or plugin text file, with secrets redacted.
 */
final class ReadFile extends FileTool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'read_file';
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		$integer = array( 'type' => 'integer' );

		return array(
			'name'         => $this->name(),
			'title'        => 'Read a theme or plugin file with FlitAgent',
			'description'  => 'From the FlitAgent WordPress plugin: reads one text file (PHP, CSS, JS, JSON, HTML, templates, Markdown and similar) from the active theme, its parent theme, or the themes, plugins or mu-plugins directory, e.g. root "plugins" and path "my-plugin/my-plugin.php". Optional start_line and end_line return a slice. Secret values are redacted. Never reads uploads, wp-config.php, hidden files, vendor, keys, databases, backups, archives or logs; files over 512 KB and binary files are refused. Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'root'       => self::root_schema(),
					'path'       => array(
						'type'        => 'string',
						'description' => 'File path relative to the root, e.g. "functions.php" or "templates/index.html".',
					),
					'start_line' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'First line to return (1-based).',
					),
					'end_line'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Last line to return (inclusive).',
					),
				),
				'required'             => array( 'path' ),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'root'        => array( 'type' => 'string' ),
					'path'        => array(
						'type'        => 'string',
						'description' => 'Relative to the root.',
					),
					'content'     => array(
						'type'        => 'string',
						'description' => 'File text with secret values replaced by "[redacted]".',
					),
					'start_line'  => $integer,
					'end_line'    => $integer,
					'total_lines' => $integer,
					'size'        => array(
						'type'        => 'integer',
						'description' => 'File size in bytes.',
					),
				),
				'required'   => array( 'root', 'path', 'content', 'start_line', 'end_line', 'total_lines', 'size' ),
			),
			'annotations'  => Annotations::read_only( 'Read a theme or plugin file with FlitAgent' ),
		);
	}

	/**
	 * Reads the file.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the arguments are invalid or the file may not be read.
	 */
	public function call( array $arguments ): array {
		$this->reject_unknown( $arguments, array( 'root', 'path', 'start_line', 'end_line' ) );

		$root  = Sandbox::root_argument( $arguments );
		$start = self::int_argument( $arguments, 'start_line', null, 1, PHP_INT_MAX );
		$end   = self::int_argument( $arguments, 'end_line', null, 1, PHP_INT_MAX );
		if ( null !== $start && null !== $end && $end < $start ) {
			throw new ToolError( 'end_line must not be before start_line.' );
		}

		$file    = $this->sandbox()->resolve_file( $root, isset( $arguments['path'] ) ? $arguments['path'] : '' );
		$raw     = Sandbox::read_text( $file['real'] );
		$content = self::redact( $raw );
		$lines   = self::lines( $content );
		$total   = count( $lines );

		if ( null !== $start || null !== $end ) {
			$first = null !== $start ? $start : 1;
			if ( $first > max( $total, 1 ) ) {
				throw new ToolError( sprintf( 'start_line is past the end of the file (%d lines).', (int) $total ) );
			}
			$last    = null !== $end ? min( $end, $total ) : $total;
			$content = implode( "\n", array_slice( $lines, $first - 1, max( 0, $last - $first + 1 ) ) );
		} else {
			$first = 1;
			$last  = $total;
		}

		return array(
			'root'        => $root,
			'path'        => $file['path'],
			'content'     => $content,
			'start_line'  => $first,
			'end_line'    => $last,
			'total_lines' => $total,
			'size'        => strlen( $raw ),
		);
	}

	/**
	 * Splits text into lines; a final newline does not start another line.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function lines( string $text ): array {
		if ( '' === $text ) {
			return array();
		}
		$lines = preg_split( '/\r\n|\n|\r/', $text );
		if ( '' === end( $lines ) ) {
			array_pop( $lines );
		}
		return $lines;
	}
}
