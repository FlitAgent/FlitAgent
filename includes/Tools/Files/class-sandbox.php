<?php
/**
 * The only way the file tools touch the filesystem.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Files;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Confines file reads to a fixed set of roots (the active theme, its parent, the themes, plugins and must-use plugins
 * directories). Every path is checked by name, resolved with realpath() and refused if it escapes its root, and every
 * read is size- and binary-checked. Paths it returns are relative to the root; error messages never contain an
 * absolute path.
 *
 * Uploads, the wp-content root (debug.log, db.php, the SQLite database, drop-ins), wp-config.php and core directories
 * are never roots.
 */
final class Sandbox {

	/**
	 * Root names, in the order tools list them. `theme` is the default.
	 */
	const ROOTS = array( 'theme', 'parent_theme', 'themes', 'plugins', 'mu_plugins' );

	/**
	 * Largest file read, in bytes (512 KB).
	 */
	const MAX_FILE_BYTES = 524288;

	/**
	 * Longest accepted path, in bytes.
	 */
	const MAX_PATH_BYTES = 1024;

	/**
	 * File types read_file, search_files and get_file_outline accept (the last extension, lower case).
	 */
	const TEXT_EXTENSIONS = array( 'php', 'phtml', 'inc', 'css', 'scss', 'sass', 'less', 'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'json', 'html', 'htm', 'twig', 'txt', 'md', 'xml', 'svg', 'yml', 'yaml', 'pot', 'po' );

	/**
	 * Any extension part (not only the last) that denies a name: keys and certificates, databases and dumps, backups,
	 * archives, logs and environment or ini files.
	 */
	const DENIED_EXTENSIONS = array(
		'pem',
		'key',
		'crt',
		'cer',
		'der',
		'p12',
		'pfx',
		'jks',
		'keystore',
		'ppk',
		'asc',
		'gpg',
		'pgp',
		'kdbx',
		'sql',
		'sqlite',
		'sqlite3',
		'db',
		'dump',
		'bak',
		'backup',
		'old',
		'orig',
		'save',
		'swp',
		'swo',
		'tmp',
		'zip',
		'tar',
		'gz',
		'tgz',
		'bz2',
		'xz',
		'7z',
		'rar',
		'wpress',
		'phar',
		'log',
		'env',
		'ini',
		'htpasswd',
	);

	/**
	 * Directory names never entered: third-party dependency trees.
	 */
	const DENIED_DIRECTORIES = array( 'vendor', 'node_modules' );

	/**
	 * Secret file names (case-insensitive), matched from the start of a name.
	 */
	const DENIED_NAME_PATTERN = '/^(?:wp-config|php\d*\.ini$|auth\.json$|credentials|secrets?(?![a-z])|service[-_]?account|id_(?:rsa|dsa|ecdsa|ed25519))/i';

	/**
	 * Default walk limits.
	 */
	const DEFAULT_LIMITS = array(
		'list_entries'   => 10000,
		'list_seconds'   => 2,
		'search_files'   => 5000,
		'search_seconds' => 3,
	);

	/**
	 * Root name to directory, or null when the root does not apply (e.g. no parent theme).
	 *
	 * @var array<string, string|null>
	 */
	private $roots;

	/**
	 * Walk limits.
	 *
	 * @var array<string, int|float>
	 */
	private $limits;

	/**
	 * Creates a sandbox.
	 *
	 * @param array<string, string|null> $roots  Root name to directory (see ROOTS).
	 * @param array<string, int|float>   $limits Overrides for DEFAULT_LIMITS (tests use small ones).
	 */
	public function __construct( array $roots, array $limits = array() ) {
		$this->roots  = $roots;
		$this->limits = array_merge( self::DEFAULT_LIMITS, $limits );
	}

	/**
	 * The roots of the running WordPress site.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		$stylesheet = (string) get_stylesheet_directory();
		$template   = (string) get_template_directory();

		return new self(
			array(
				'theme'        => $stylesheet,
				'parent_theme' => $template !== $stylesheet ? $template : null,
				'themes'       => (string) get_theme_root(),
				'plugins'      => defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : null,
				'mu_plugins'   => defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : null,
			)
		);
	}

	/**
	 * A walk limit.
	 *
	 * @param string $name Limit name (see DEFAULT_LIMITS).
	 * @return int|float
	 */
	public function limit( string $name ) {
		return $this->limits[ $name ];
	}

	/**
	 * Validates the `root` argument (default `theme`).
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return string
	 * @throws ToolError When the root is not one of ROOTS.
	 */
	public static function root_argument( array $arguments ): string {
		$root = array_key_exists( 'root', $arguments ) ? $arguments['root'] : 'theme';
		if ( ! is_string( $root ) || ! in_array( $root, self::ROOTS, true ) ) {
			throw new ToolError( 'root must be one of: ' . esc_textarea( implode( ', ', self::ROOTS ) ) . '.' );
		}
		return $root;
	}

	/**
	 * Resolves a file inside a root. Refuses unsafe syntax, denied names, paths that resolve outside the root,
	 * directories, non-text types and hard-linked files.
	 *
	 * @param string $root Root name.
	 * @param mixed  $path Relative path from the client.
	 * @return array{root: string, path: string, real: string}
	 * @throws ToolError When the file may not be read.
	 */
	public function resolve_file( string $root, $path ): array {
		$segments = self::segments( $path, false );
		$resolved = $this->resolve( $root, $segments );

		if ( ! is_file( $resolved['real'] ) ) {
			throw new ToolError( 'path is not a file. Use list_files to see what is there.' );
		}
		if ( ! self::is_text_type( $resolved['path'] ) || ! self::is_text_type( $resolved['real'] ) ) {
			throw new ToolError( 'path is not a readable text file type. Allowed types: ' . esc_textarea( implode( ', ', self::TEXT_EXTENSIONS ) ) . '.' );
		}
		self::assert_single_link( $resolved['real'] );

		return $resolved;
	}

	/**
	 * Resolves a directory inside a root; an empty or missing path is the root itself.
	 *
	 * @param string $root Root name.
	 * @param mixed  $path Relative path from the client, or null.
	 * @return array{root: string, path: string, real: string}
	 * @throws ToolError When the directory may not be read.
	 */
	public function resolve_directory( string $root, $path ): array {
		$resolved = $this->resolve( $root, self::segments( null === $path ? '' : $path, true ) );

		if ( ! is_dir( $resolved['real'] ) ) {
			throw new ToolError( 'path is not a directory.' );
		}

		return $resolved;
	}

	/**
	 * Reads a resolved file's text. Refuses files over MAX_FILE_BYTES and binary or non-UTF-8 files.
	 *
	 * @param string $real Real path from resolve_file() or walk().
	 * @return string
	 * @throws ToolError When the file is too large, binary or unreadable.
	 */
	public static function read_text( string $real ): string {
		$size = filesize( $real );
		if ( false === $size ) {
			throw new ToolError( 'The file could not be read.' );
		}
		if ( $size > self::MAX_FILE_BYTES ) {
			throw new ToolError( sprintf( 'The file is %d KB, over the 512 KB limit.', (int) ceil( $size / 1024 ) ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local file, already confined to a root.
		$content = file_get_contents( $real, false, null, 0, self::MAX_FILE_BYTES + 1 );
		if ( false === $content ) {
			throw new ToolError( 'The file could not be read.' );
		}
		if ( strlen( $content ) > self::MAX_FILE_BYTES ) {
			throw new ToolError( 'The file is over the 512 KB limit.' );
		}
		// Invalid UTF-8 makes preg_match() fail with PREG_BAD_UTF8_ERROR; any other failure is a PCRE limit.
		$utf8 = preg_match( '//u', $content );
		if ( false === $utf8 && PREG_BAD_UTF8_ERROR !== preg_last_error() ) {
			throw new ToolError( 'The file could not be checked safely, so it is not returned.' );
		}
		if ( false !== strpos( $content, "\0" ) || 1 !== $utf8 ) {
			throw new ToolError( 'The file looks binary or is not UTF-8 text, so it is not returned.' );
		}

		return $content;
	}

	/**
	 * Walks a resolved directory depth-first in name order. Skips denied names (and never enters denied directories),
	 * symbolic links and anything that is neither a file nor a directory. Stops after $max_entries entries or
	 * $max_seconds seconds.
	 *
	 * @param array{root: string, path: string, real: string} $directory   Directory from resolve_directory().
	 * @param bool                                            $recursive   Whether to enter subdirectories.
	 * @param callable                                        $visit       fn( string $path, string $real, bool $is_dir ): bool; return false to stop.
	 * @param int                                             $max_entries Most entries to visit.
	 * @param float                                           $max_seconds Longest walk.
	 * @return bool Whether the walk stopped at a limit (truncated).
	 */
	public static function walk( array $directory, bool $recursive, callable $visit, int $max_entries, float $max_seconds ): bool {
		$deadline = microtime( true ) + $max_seconds;
		$visited  = 0;
		$stack    = array( array( $directory['path'], $directory['real'] ) );

		while ( $stack ) {
			list( $relative, $real ) = array_pop( $stack );

			$names = scandir( $real );
			if ( false === $names ) {
				continue;
			}

			$subdirectories = array();
			foreach ( $names as $name ) {
				if ( '.' === $name || '..' === $name || self::is_denied_name( $name ) ) {
					continue;
				}
				$child_real     = $real . DIRECTORY_SEPARATOR . $name;
				$child_relative = '' === $relative ? $name : $relative . '/' . $name;
				if ( is_link( $child_real ) ) {
					continue;
				}
				$is_dir = is_dir( $child_real );
				if ( ! $is_dir && ! is_file( $child_real ) ) {
					continue;
				}

				if ( ++$visited > $max_entries || microtime( true ) > $deadline ) {
					return true;
				}
				if ( false === $visit( $child_relative, $child_real, $is_dir ) ) {
					return false;
				}
				if ( $is_dir && $recursive ) {
					$subdirectories[] = array( $child_relative, $child_real );
				}
			}//end foreach

			// Push in reverse so the first subdirectory is walked next.
			foreach ( array_reverse( $subdirectories ) as $subdirectory ) {
				$stack[] = $subdirectory;
			}
		}//end while

		return false;
	}

	/**
	 * Whether read_file accepts a name's type (its last extension).
	 *
	 * @param string $name File name or path.
	 * @return bool
	 */
	public static function is_text_type( string $name ): bool {
		$base = basename( str_replace( '\\', '/', $name ) );
		$dot  = strrpos( $base, '.' );
		return false !== $dot && in_array( strtolower( substr( $base, $dot + 1 ) ), self::TEXT_EXTENSIONS, true );
	}

	/**
	 * Whether a single path segment (file or directory name) is denied.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function is_denied_name( string $name ): bool {
		if ( '' === $name || '.' === $name[0] || '~' === substr( $name, -1 ) ) {
			return true;
		}
		$lower = strtolower( $name );
		if ( in_array( $lower, self::DENIED_DIRECTORIES, true ) || 1 === preg_match( self::DENIED_NAME_PATTERN, $name ) ) {
			return true;
		}
		$parts = explode( '.', $lower );
		array_shift( $parts );
		return array() !== array_intersect( $parts, self::DENIED_EXTENSIONS );
	}

	/**
	 * Splits and validates a client path. Refuses non-strings, absolute paths, `..`, `.` and empty segments,
	 * backslashes, colons, control bytes, percent-encoded bytes and over-long paths.
	 *
	 * @param mixed $path        Path.
	 * @param bool  $allow_empty Whether '' (the root itself) is allowed.
	 * @return string[]
	 * @throws ToolError When the path is unsafe.
	 */
	private static function segments( $path, bool $allow_empty ): array {
		if ( ! is_string( $path ) ) {
			throw new ToolError( 'path must be a string, relative to the root, e.g. "functions.php" or "templates/index.html".' );
		}
		if ( '' === $path ) {
			if ( $allow_empty ) {
				return array();
			}
			throw new ToolError( 'path is required, relative to the root, e.g. "functions.php".' );
		}
		if ( strlen( $path ) > self::MAX_PATH_BYTES ) {
			throw new ToolError( 'path is too long.' );
		}
		if ( 1 === preg_match( '/[\x00-\x1f\x7f\\\\:]/', $path ) || 1 === preg_match( '/%[0-9a-f]{2}/i', $path ) ) {
			throw new ToolError( 'path must not contain control characters, backslashes, colons or percent-encoded bytes.' );
		}
		if ( '/' === $path[0] ) {
			throw new ToolError( 'path must be relative to the root, not absolute.' );
		}

		$segments = explode( '/', $path );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				throw new ToolError( 'path must not contain "..", "." or empty segments.' );
			}
		}

		return $segments;
	}

	/**
	 * Resolves validated segments inside a root.
	 *
	 * @param string   $root     Root name.
	 * @param string[] $segments Validated segments.
	 * @return array{root: string, path: string, real: string}
	 * @throws ToolError When a name is denied, the path is missing or it resolves outside the root.
	 */
	private function resolve( string $root, array $segments ): array {
		foreach ( $segments as $segment ) {
			if ( self::is_denied_name( $segment ) ) {
				throw new ToolError( 'path is not allowed: hidden files, vendor and node_modules, wp-config, keys, certificates, databases, dumps, backups, archives, logs and env or ini files are never read.' );
			}
		}

		$root_real = $this->root_real( $root );
		$relative  = implode( '/', $segments );
		$real      = array() === $segments ? $root_real : realpath( $root_real . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $segments ) );

		if ( false === $real ) {
			throw new ToolError( sprintf( 'path not found in the %s root.', esc_textarea( $root ) ) );
		}

		$prefix = $root_real . DIRECTORY_SEPARATOR;
		if ( $real !== $root_real && 0 !== strpos( $real, $prefix ) ) {
			throw new ToolError( sprintf( 'path resolves outside the %s root (through a symbolic link), so it is not read.', esc_textarea( $root ) ) );
		}

		// A symlink inside the root may still point at a denied name inside it.
		if ( $real !== $root_real ) {
			foreach ( explode( DIRECTORY_SEPARATOR, substr( $real, strlen( $prefix ) ) ) as $segment ) {
				if ( self::is_denied_name( $segment ) ) {
					throw new ToolError( 'path is not allowed: it resolves to a hidden, vendor or secret file.' );
				}
			}
		}

		return array(
			'root' => $root,
			'path' => $relative,
			'real' => $real,
		);
	}

	/**
	 * A root's real path.
	 *
	 * @param string $root Root name.
	 * @return string
	 * @throws ToolError When the root does not apply or does not exist.
	 */
	private function root_real( string $root ): string {
		if ( ! in_array( $root, self::ROOTS, true ) ) {
			throw new ToolError( 'root must be one of: ' . esc_textarea( implode( ', ', self::ROOTS ) ) . '.' );
		}
		if ( 'parent_theme' === $root && empty( $this->roots['parent_theme'] ) ) {
			throw new ToolError( 'The active theme is not a child theme, so it has no parent_theme root. Use root "theme".' );
		}

		$directory = isset( $this->roots[ $root ] ) ? $this->roots[ $root ] : null;
		$real      = is_string( $directory ) && '' !== $directory ? realpath( $directory ) : false;
		if ( false === $real || ! is_dir( $real ) || dirname( $real ) === $real ) {
			throw new ToolError( sprintf( 'The %s root is not available on this site.', esc_textarea( $root ) ) );
		}

		return $real;
	}

	/**
	 * Refuses a file with more than one hard link: another name for it may sit outside the root.
	 *
	 * @param string $real Real path.
	 * @throws ToolError When the file has more than one hard link.
	 */
	private static function assert_single_link( string $real ): void {
		$stat = stat( $real );
		if ( false === $stat || $stat['nlink'] > 1 ) {
			throw new ToolError( 'path is a file with more than one hard link, so it is not read.' );
		}
	}
}
