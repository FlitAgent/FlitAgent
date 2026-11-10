<?php
/**
 * The search_files tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Files;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Finds plain text in theme or plugin files, like a bounded grep.
 */
final class SearchFiles extends FileTool {

	/**
	 * Default and largest number of matches.
	 */
	const DEFAULT_LIMIT = 50;
	const MAX_LIMIT     = 200;

	/**
	 * Longest query, in bytes.
	 */
	const MAX_QUERY_BYTES = 200;

	/**
	 * Longest returned line, in characters.
	 */
	const MAX_LINE_CHARS = 300;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'search_files';
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		return array(
			'name'         => $this->name(),
			'title'        => 'Search theme or plugin files with FlitAgent',
			'description'  => 'From the FlitAgent WordPress plugin: finds a plain-text query (not a regular expression) in the text files of the active theme, its parent theme, or the themes, plugins or mu-plugins directory, and returns each matching line with its relative path and line number. Case-insensitive unless case_sensitive is true. Narrow with path (a subdirectory) and pattern (a glob: "*.php" matches file names at any depth; a pattern with "/" matches the path from the root). Secret values are redacted before matching, so they never match or show. Skips files over 512 KB, binary files, hidden files, vendor, node_modules, symbolic links and secret files. Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'root'           => self::root_schema(),
					'query'          => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => self::MAX_QUERY_BYTES,
						'description' => 'Plain text to find on one line, e.g. "add_action( \'init\'".',
					),
					'path'           => array(
						'type'        => 'string',
						'description' => 'Directory relative to the root to search in; empty or omitted for the whole root.',
					),
					'pattern'        => array(
						'type'        => 'string',
						'maxLength'   => 200,
						'description' => 'Glob, case-insensitive. Without "/" it matches the file name at any depth, e.g. "*.php". With "/" it matches the whole file path relative to the root (not to path), and "*" does not cross "/", e.g. "templates/*.html".',
					),
					'case_sensitive' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'limit'          => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::MAX_LIMIT,
						'default' => self::DEFAULT_LIMIT,
					),
				),
				'required'             => array( 'query' ),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'root'           => array( 'type' => 'string' ),
					'query'          => array( 'type' => 'string' ),
					'matches'        => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'path' => array(
									'type'        => 'string',
									'description' => 'Relative to the root.',
								),
								'line' => array( 'type' => 'integer' ),
								'text' => array(
									'type'        => 'string',
									'description' => 'The matching line, trimmed, redacted, at most 300 characters.',
								),
							),
							'required'   => array( 'path', 'line', 'text' ),
						),
					),
					'files_searched' => array( 'type' => 'integer' ),
					'truncated'      => array(
						'type'        => 'boolean',
						'description' => 'True when the search stopped at limit matches, 5,000 files or 3 seconds: narrow path, pattern or query.',
					),
				),
				'required'   => array( 'root', 'query', 'matches', 'files_searched', 'truncated' ),
			),
			'annotations'  => Annotations::read_only( 'Search theme or plugin files with FlitAgent' ),
		);
	}

	/**
	 * Runs the search.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the arguments are invalid or the directory may not be read.
	 */
	public function call( array $arguments ): array {
		$this->reject_unknown( $arguments, array( 'root', 'query', 'path', 'pattern', 'case_sensitive', 'limit' ) );

		$root           = Sandbox::root_argument( $arguments );
		$query          = self::query_argument( $arguments );
		$pattern        = ListFiles::pattern_argument( $arguments );
		$case_sensitive = self::bool_argument( $arguments, 'case_sensitive', false );
		$limit          = (int) self::int_argument( $arguments, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT );
		$sandbox        = $this->sandbox();
		$directory      = $sandbox->resolve_directory( $root, isset( $arguments['path'] ) ? $arguments['path'] : null );
		$max_files      = (int) $sandbox->limit( 'search_files' );
		$matches        = array();
		$searched       = 0;
		$hit_limit      = false;

		$truncated = Sandbox::walk(
			$directory,
			true,
			static function ( string $path, string $real, bool $is_dir ) use ( $query, $pattern, $case_sensitive, $limit, $max_files, &$matches, &$searched, &$hit_limit ): bool {
				if ( $is_dir || ! Sandbox::is_text_type( $path ) || ( null !== $pattern && ! ListFiles::pattern_matches( $pattern, $path ) ) ) {
					return true;
				}
				if ( $searched >= $max_files ) {
					$hit_limit = true;
					return false;
				}

				$text = self::searchable_text( $real );
				if ( null === $text ) {
					return true;
				}
				++$searched;

				foreach ( ReadFile::lines( $text ) as $index => $line ) {
					$found = $case_sensitive ? strpos( $line, $query ) : stripos( $line, $query );
					if ( false === $found ) {
						continue;
					}
					if ( count( $matches ) >= $limit ) {
						$hit_limit = true;
						return false;
					}
					$matches[] = array(
						'path' => $path,
						'line' => $index + 1,
						'text' => self::clip( trim( $line ) ),
					);
				}
				return true;
			},
			(int) $sandbox->limit( 'list_entries' ),
			(float) $sandbox->limit( 'search_seconds' )
		);

		return array(
			'root'           => $root,
			'query'          => $query,
			'matches'        => $matches,
			'files_searched' => $searched,
			'truncated'      => $truncated || $hit_limit,
		);
	}

	/**
	 * A file's redacted text, or null when it must be skipped (hard-linked, too large, binary, or not redactable).
	 *
	 * @param string $real Real path from the walk.
	 * @return string|null
	 */
	private static function searchable_text( string $real ): ?string {
		$stat = stat( $real );
		if ( false === $stat || $stat['nlink'] > 1 ) {
			return null;
		}
		try {
			return self::redact( Sandbox::read_text( $real ) );
		} catch ( ToolError $e ) {
			return null;
		}
	}

	/**
	 * Validates the query.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @return string
	 * @throws ToolError When the query is missing, too long or has control characters.
	 */
	private static function query_argument( array $arguments ): string {
		$query = isset( $arguments['query'] ) ? $arguments['query'] : null;
		if ( ! is_string( $query ) || '' === $query || strlen( $query ) > self::MAX_QUERY_BYTES || 1 === preg_match( '/[\x00-\x1f\x7f]/', $query ) || 1 !== preg_match( '//u', $query ) ) {
			throw new ToolError( 'query must be plain text on one line, 1 to 200 bytes.' );
		}
		return $query;
	}

	/**
	 * Cuts a line to MAX_LINE_CHARS characters without splitting a UTF-8 character.
	 *
	 * @param string $line Line.
	 * @return string
	 */
	private static function clip( string $line ): string {
		return 1 === preg_match( '/^.{0,' . self::MAX_LINE_CHARS . '}/su', $line, $match ) ? $match[0] : '';
	}
}
