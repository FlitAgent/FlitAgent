<?php
/**
 * Compares files on disk with a WordPress.org checksum manifest.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by verify_core_checksums and verify_plugin_checksums. Reports relative paths and statuses only: never file
 * contents, hashes or absolute paths.
 */
final class FileChecksums {

	/**
	 * Most files listed per result; the counts stay exact.
	 */
	const MAX_FILES = 100;

	/**
	 * Request timeout for WordPress.org, in seconds. Core's own fetch uses 3 s outside cron, too short for slow hosts.
	 */
	const TIMEOUT = 15;

	/**
	 * Fetches a JSON document from WordPress.org over HTTPS (never plain HTTP: the checksums decide what is trusted).
	 *
	 * @param string $url HTTPS URL.
	 * @return array<string, mixed>|null Decoded body, or null on any failure or non-200 status.
	 */
	public static function fetch_json( string $url ) {
		$response = wp_safe_remote_get( $url, array( 'timeout' => self::TIMEOUT ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : null;
	}

	/**
	 * Compares a manifest with the files under a root folder.
	 *
	 * @param string                       $root       Folder the manifest paths are relative to.
	 * @param array<string, array<string>> $manifest   Relative path => accepted MD5 hashes.
	 * @param callable                     $in_scope   Takes a relative path; false skips it in both checks.
	 * @param array<string, bool>          $extra_dirs Relative folder ('' for the root) => whether to scan it
	 *                                                 recursively, for files the manifest does not list.
	 * @param callable                     $may_extra  Takes a relative path found on disk; false never reports it as
	 *                                                 extra.
	 * @return array{verified: bool, files_checked: int, counts: array<string, int>, files: array, truncated: bool}
	 */
	public static function compare( string $root, array $manifest, callable $in_scope, array $extra_dirs, callable $may_extra ): array {
		$root     = rtrim( $root, '/\\' ) . '/';
		$modified = array();
		$missing  = array();
		$extra    = array();
		$checked  = 0;

		foreach ( $manifest as $path => $hashes ) {
			if ( ! self::is_safe_path( $path ) || ! $in_scope( $path ) ) {
				continue;
			}
			++$checked;
			if ( ! is_file( $root . $path ) ) {
				$missing[] = $path;
			} elseif ( ! in_array( md5_file( $root . $path ), $hashes, true ) ) {
				$modified[] = $path;
			}
		}

		foreach ( $extra_dirs as $dir => $recursive ) {
			foreach ( self::files_under( $root, (string) $dir, $recursive ) as $path ) {
				if ( ! isset( $manifest[ $path ] ) && $in_scope( $path ) && $may_extra( $path ) ) {
					$extra[] = $path;
				}
			}
		}

		sort( $extra );
		$files = array();
		foreach ( array(
			'modified' => $modified,
			'missing'  => $missing,
			'extra'    => $extra,
		) as $status => $paths ) {
			foreach ( $paths as $path ) {
				$files[] = array(
					'path'   => $path,
					'status' => $status,
				);
			}
		}

		return array(
			'verified'      => array() === $files,
			'files_checked' => $checked,
			'counts'        => array(
				'modified' => count( $modified ),
				'missing'  => count( $missing ),
				'extra'    => count( $extra ),
			),
			'files'         => array_slice( $files, 0, self::MAX_FILES ),
			'truncated'     => count( $files ) > self::MAX_FILES,
		);
	}

	/**
	 * Turns a manifest's hash entry (`"abc"`, `["abc", "def"]` or `{"md5": ...}`) into a list of MD5 hashes.
	 *
	 * @param mixed $entry Manifest entry.
	 * @return string[]
	 */
	public static function md5_list( $entry ): array {
		if ( is_array( $entry ) && isset( $entry['md5'] ) ) {
			$entry = $entry['md5'];
		}
		return array_values( array_filter( (array) $entry, 'is_string' ) );
	}

	/**
	 * Whether a manifest path stays inside its root: relative, no "..", no backslashes or NUL bytes.
	 *
	 * @param mixed $path Manifest key.
	 * @return bool
	 */
	private static function is_safe_path( $path ): bool {
		return is_string( $path )
			&& '' !== $path
			&& '/' !== $path[0]
			&& 1 !== preg_match( '/[\\\\\x00:]/', $path )
			&& ! in_array( '..', explode( '/', $path ), true );
	}

	/**
	 * Relative paths of the files under `$root . $dir`, without following symlinked folders.
	 *
	 * @param string $root      Root with trailing slash.
	 * @param string $dir       Relative folder, '' for the root itself.
	 * @param bool   $recursive Whether to include subfolders.
	 * @return string[]
	 */
	private static function files_under( string $root, string $dir, bool $recursive ): array {
		$paths  = array();
		$folder = $root . ( '' === $dir ? '' : rtrim( $dir, '/' ) . '/' );
		if ( ! is_dir( $folder ) ) {
			return $paths;
		}
		if ( ! $recursive ) {
			// FilesystemIterator skips '.' and '..': touching the parent breaks under open_basedir.
			foreach ( new \FilesystemIterator( $folder ) as $item ) {
				if ( $item->isFile() ) {
					$paths[] = substr( $item->getPathname(), strlen( $root ) );
				}
			}
			return $paths;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $folder, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			if ( $item->isFile() ) {
				$paths[] = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $root ) ) );
			}
		}
		return $paths;
	}
}
