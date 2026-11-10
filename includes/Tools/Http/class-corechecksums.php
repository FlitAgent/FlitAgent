<?php
/**
 * The verify_core_checksums tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Http;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the WordPress core files with the checksums WordPress.org publishes for the installed version.
 *
 * Not core's get_core_checksums(): it falls back to plain HTTP when HTTPS fails, which would let a network attacker
 * choose the checksums, and it times out after 3 s outside cron. Same endpoint, HTTPS only.
 */
final class CoreChecksums implements Tool {

	/**
	 * WordPress.org core checksums API.
	 */
	const API = 'https://api.wordpress.org/core/checksums/1.0/';

	/**
	 * WordPress root folder.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Constructor.
	 *
	 * @param string|null $root WordPress root folder; defaults to ABSPATH.
	 */
	public function __construct( $root = null ) {
		$this->root = null === $root ? ABSPATH : (string) $root;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'verify_core_checksums';
	}

	/**
	 * Protected: the result tells an attacker which core files differ, and each call makes the site contact
	 * WordPress.org.
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		return array(
			'name'         => $this->name(),
			'title'        => 'Verify WordPress core files with FlitAgent',
			'description'  => 'From the FlitAgent WordPress plugin: checks the WordPress core files against the checksums WordPress.org publishes for the installed version (like wp core verify-checksums). Returns whether core verifies and the relative paths of files that are modified, missing, or extra in wp-admin, wp-includes or root wp-* files (unexpected files there are a common malware sign). Never reads wp-config.php or wp-content, and never returns file contents. Contacts api.wordpress.org. Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			),
			'outputSchema' => self::output_schema(
				array(
					'version' => array( 'type' => 'string' ),
					'locale'  => array(
						'type'        => 'string',
						'description' => 'Locale whose checksums were used (en_US when the site locale has none).',
					),
				),
				array( 'verified', 'version', 'locale', 'files_checked', 'counts', 'files', 'truncated' )
			),
			'annotations'  => Annotations::read_only( 'Verify WordPress core files with FlitAgent', true ),
		);
	}

	/**
	 * Output schema shared with the per-plugin results of verify_plugin_checksums.
	 *
	 * @param array<string, mixed> $extra    Extra properties.
	 * @param string[]             $required Required keys, in output order.
	 * @return array<string, mixed>
	 */
	public static function output_schema( array $extra, array $required ): array {
		$count = array( 'type' => 'integer' );

		return array(
			'type'       => 'object',
			'properties' => $extra + array(
				'verified'      => array( 'type' => 'boolean' ),
				'files_checked' => $count,
				'counts'        => array(
					'type'       => 'object',
					'properties' => array(
						'modified' => $count,
						'missing'  => $count,
						'extra'    => $count,
					),
				),
				'files'         => array(
					'type'        => 'array',
					'description' => 'Files that failed, at most 100.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'path'   => array(
								'type'        => 'string',
								'description' => 'Relative path.',
							),
							'status' => array(
								'type' => 'string',
								'enum' => array( 'modified', 'missing', 'extra' ),
							),
						),
						'required'   => array( 'path', 'status' ),
					),
				),
				'truncated'     => array(
					'type'        => 'boolean',
					'description' => 'True if more than 100 files failed; counts stay exact.',
				),
			),
			'required'   => $required,
		);
	}

	/**
	 * Verifies core. Takes no arguments.
	 *
	 * @param array<string, mixed> $arguments Must be empty.
	 * @return array<string, mixed>
	 * @throws ToolError When an argument is passed or WordPress.org has no checksums for this version.
	 */
	public function call( array $arguments ): array {
		if ( array() !== $arguments ) {
			throw new ToolError( 'verify_core_checksums takes no arguments.' );
		}

		global $wp_version;
		$version = (string) $wp_version;
		$locale  = (string) get_locale();

		$found = self::for_site( $version, $locale );
		if ( null === $found ) {
			throw new ToolError( sprintf( 'WordPress.org has no checksums for WordPress %s (en_US), or could not be reached. Development and pre-release versions have none.', esc_textarea( $version ) ) );
		}
		$locale    = $found['locale'];
		$checksums = $found['checksums'];

		$manifest = array();
		foreach ( $checksums as $path => $md5 ) {
			$manifest[ (string) $path ] = FileChecksums::md5_list( $md5 );
		}

		$result = FileChecksums::compare(
			$this->root,
			$manifest,
			static function ( string $path ): bool {
				return 0 !== strpos( $path, 'wp-content/' ) && 'wp-config.php' !== $path;
			},
			array(
				'wp-admin'    => true,
				'wp-includes' => true,
				''            => false,
			),
			static function ( string $path ): bool {
				// Like WP-CLI: everything in wp-admin and wp-includes, and root wp-* files except wp-config.php.
				return false !== strpos( $path, '/' ) || 1 === preg_match( '/^wp-(?!config\.php$)[^\/]*$/', $path );
			}
		);

		return array(
			'verified'      => $result['verified'],
			'version'       => $version,
			'locale'        => $locale,
			'files_checked' => $result['files_checked'],
			'counts'        => $result['counts'],
			'files'         => $result['files'],
			'truncated'     => $result['truncated'],
		);
	}

	/**
	 * Fetches the checksums for the site's version and locale, falling back to en_US: localized packages often have
	 * no checksums, and en_US covers everything but translated readme/license files.
	 *
	 * @param string $version WordPress version.
	 * @param string $locale  Site locale.
	 * @return array{locale: string, checksums: array<string, mixed>}|null The locale used and its relative path => MD5
	 *                                                                     map, or null if WordPress.org has none.
	 */
	public static function for_site( string $version, string $locale ) {
		$checksums = self::checksums( $version, $locale );
		if ( null === $checksums && 'en_US' !== $locale ) {
			$locale    = 'en_US';
			$checksums = self::checksums( $version, $locale );
		}
		return null === $checksums ? null : compact( 'locale', 'checksums' );
	}

	/**
	 * Fetches the checksums for a version and locale.
	 *
	 * @param string $version WordPress version.
	 * @param string $locale  Locale.
	 * @return array<string, mixed>|null Relative path => MD5, or null if WordPress.org has none.
	 */
	private static function checksums( string $version, string $locale ) {
		$body = FileChecksums::fetch_json( self::API . '?' . http_build_query( compact( 'version', 'locale' ), '', '&' ) );
		return isset( $body['checksums'] ) && is_array( $body['checksums'] ) && array() !== $body['checksums'] ? $body['checksums'] : null;
	}
}
