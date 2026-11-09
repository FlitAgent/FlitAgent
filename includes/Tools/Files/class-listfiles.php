<?php
/**
 * The list_files tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Files;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Lists files and directories in a theme or plugin root.
 */
final class ListFiles extends FileTool {

	/**
	 * Default and largest page size.
	 */
	const DEFAULT_LIMIT = 200;
	const MAX_LIMIT     = 1000;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_files';
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		return array(
			'name'         => $this->name(),
			'title'        => 'List theme or plugin files with FlitAgent',
			'description'  => 'From the FlitAgent WordPress plugin: lists files and directories in the active theme, its parent theme, or the themes, plugins or mu-plugins directory, sorted, with paths relative to the root, sizes, and whether read_file can read each file. Filter with a glob pattern (e.g. "*.php" or "templates/*.html") and page with limit and offset. Hidden files, vendor, node_modules, symbolic links and secret files (wp-config, keys, databases, backups, archives, logs) are left out. Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'root'      => self::root_schema(),
					'path'      => array(
						'type'        => 'string',
						'description' => 'Directory relative to the root; empty or omitted for the root itself.',
					),
					'pattern'   => array(
						'type'        => 'string',
						'maxLength'   => 200,
						'description' => 'Glob on the path relative to the root, e.g. "*.php" (top level), "*/*.php" or "templates/*.html". "*" does not cross "/".',
					),
					'recursive' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'limit'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::MAX_LIMIT,
						'default' => self::DEFAULT_LIMIT,
					),
					'offset'    => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'root'        => array( 'type' => 'string' ),
					'path'        => array( 'type' => 'string' ),
					'entries'     => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'path'     => array(
									'type'        => 'string',
									'description' => 'Relative to the root.',
								),
								'type'     => array(
									'type' => 'string',
									'enum' => array( 'file', 'directory' ),
								),
								'size'     => array(
									'type'        => array( 'integer', 'null' ),
									'description' => 'Bytes; null for directories.',
								),
								'readable' => array(
									'type'        => 'boolean',
									'description' => 'Whether read_file accepts this file type.',
								),
							),
							'required'   => array( 'path', 'type', 'size', 'readable' ),
						),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => 'Entries found (before paging).',
					),
					'next_offset' => array(
						'type'        => array( 'integer', 'null' ),
						'description' => 'Offset of the next page; null on the last page.',
					),
					'truncated'   => array(
						'type'        => 'boolean',
						'description' => 'True when the walk stopped at its limit (10,000 entries or 2 seconds): narrow path or pattern.',
					),
				),
				'required'   => array( 'root', 'path', 'entries', 'total', 'next_offset', 'truncated' ),
			),
			'annotations'  => Annotations::read_only( 'List theme or plugin files with FlitAgent' ),
		);
	}

	/**
	 * Lists the directory.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the arguments are invalid or the directory may not be read.
	 */
	public function call( array $arguments ): array {
		$this->reject_unknown( $arguments, array( 'root', 'path', 'pattern', 'recursive', 'limit', 'offset' ) );

		$root      = Sandbox::root_argument( $arguments );
		$pattern   = self::pattern_argument( $arguments );
		$recursive = self::bool_argument( $arguments, 'recursive', true );
		$limit     = (int) self::int_argument( $arguments, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT );
		$offset    = (int) self::int_argument( $arguments, 'offset', 0, 0, PHP_INT_MAX );
		$sandbox   = $this->sandbox();
		$directory = $sandbox->resolve_directory( $root, isset( $arguments['path'] ) ? $arguments['path'] : null );
		$entries   = array();

		$truncated = Sandbox::walk(
			$directory,
			$recursive,
			static function ( string $path, string $real, bool $is_dir ) use ( &$entries, $pattern ): bool {
				if ( null === $pattern || fnmatch( $pattern, $path, FNM_PATHNAME | FNM_CASEFOLD ) ) {
					$entries[] = array(
						'path'     => $path,
						'type'     => $is_dir ? 'directory' : 'file',
						'size'     => $is_dir ? null : (int) filesize( $real ),
						'readable' => ! $is_dir && Sandbox::is_text_type( $path ),
					);
				}
				return true;
			},
			(int) $sandbox->limit( 'list_entries' ),
			(float) $sandbox->limit( 'list_seconds' )
		);

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		$total = count( $entries );
		$next  = $offset + $limit < $total ? $offset + $limit : null;

		return array(
			'root'        => $root,
			'path'        => $directory['path'],
			'entries'     => array_slice( $entries, $offset, $limit ),
			'total'       => $total,
			'next_offset' => $next,
			'truncated'   => $truncated,
		);
	}

	/**
	 * Validates the optional glob pattern.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @return string|null
	 * @throws ToolError When the pattern is not a short string.
	 */
	public static function pattern_argument( array $arguments ): ?string {
		if ( ! array_key_exists( 'pattern', $arguments ) || null === $arguments['pattern'] || '' === $arguments['pattern'] ) {
			return null;
		}
		$pattern = $arguments['pattern'];
		if ( ! is_string( $pattern ) || strlen( $pattern ) > 200 || substr_count( $pattern, '*' ) > 10 ) {
			throw new ToolError( 'pattern must be a glob of at most 200 characters and 10 "*", e.g. "*.php".' );
		}
		return $pattern;
	}
}
