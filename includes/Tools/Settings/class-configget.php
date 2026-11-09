<?php
/**
 * The get_config tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Settings;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Reads an allowlist of non-secret wp-config.php settings (WP-CLI `config get`).
 */
final class ConfigGet implements Tool {

	/**
	 * Constants that are safe to show: no credentials, keys, salts or filesystem paths.
	 */
	const ALLOWED = array(
		'WP_DEBUG',
		'WP_DEBUG_LOG',
		'WP_DEBUG_DISPLAY',
		'SCRIPT_DEBUG',
		'SAVEQUERIES',
		'WP_ENVIRONMENT_TYPE',
		'WP_DEVELOPMENT_MODE',
		'WP_MEMORY_LIMIT',
		'WP_MAX_MEMORY_LIMIT',
		'WP_CACHE',
		'DISABLE_WP_CRON',
		'ALTERNATE_WP_CRON',
		'WP_CRON_LOCK_TIMEOUT',
		'AUTOMATIC_UPDATER_DISABLED',
		'WP_AUTO_UPDATE_CORE',
		'DISALLOW_FILE_EDIT',
		'DISALLOW_FILE_MODS',
		'WP_POST_REVISIONS',
		'AUTOSAVE_INTERVAL',
		'EMPTY_TRASH_DAYS',
		'MEDIA_TRASH',
		'IMAGE_EDIT_OVERWRITE',
		'FORCE_SSL_ADMIN',
		'CONCATENATE_SCRIPTS',
		'COMPRESS_SCRIPTS',
		'COMPRESS_CSS',
		'WP_HTTP_BLOCK_EXTERNAL',
		'WP_HOME',
		'WP_SITEURL',
		'MULTISITE',
		'WP_ALLOW_MULTISITE',
		'SUBDOMAIN_INSTALL',
		'WP_DEFAULT_THEME',
		'WPLANG',
		'DB_CHARSET',
		'DB_COLLATE',
	);

	/**
	 * Settings that are not constants.
	 */
	const DERIVED = array( 'table_prefix', 'environment_type' );

	/**
	 * Returns array( defined, value ) for a constant name.
	 *
	 * @var callable
	 */
	private $lookup;

	/**
	 * Constructor.
	 *
	 * @param callable|null $lookup Constant reader, for tests. Defaults to defined()/constant().
	 */
	public function __construct( ?callable $lookup = null ) {
		$this->lookup = $lookup ? $lookup : static function ( string $name ): array {
			return defined( $name ) ? array( true, constant( $name ) ) : array( false, null );
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_config';
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
			'Get wp-config settings with FlitAgent',
			'reads non-secret wp-config.php settings: debug, environment, memory, cron, automatic updates, file editing, revisions, trash, SSL, script concatenation, multisite, home and site URL, language, database charset, plus table_prefix and environment_type. Database credentials, keys, salts and filesystem paths are never returned. Each entry says whether the constant is defined.',
			array(
				'name' => array(
					'type'        => 'string',
					'description' => 'One setting, e.g. "WP_DEBUG". Omit for all.',
				),
			)
		);
	}

	/**
	 * Reads the settings.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the named setting is not exposed.
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array( 'name' ), $this->name() );
		$name = Arguments::string( $arguments, 'name', false );

		$names = array_merge( self::ALLOWED, self::DERIVED );
		if ( null !== $name ) {
			if ( ! in_array( $name, $names, true ) ) {
				throw new ToolError( sprintf( '"%s" is not exposed. get_config returns only non-secret settings: %s.', esc_textarea( $name ), esc_textarea( implode( ', ', $names ) ) ) );
			}
			$names = array( $name );
		}

		$settings = array();
		foreach ( $names as $setting ) {
			$settings[] = $this->setting( $setting );
		}

		return array( 'settings' => $settings );
	}

	/**
	 * One setting.
	 *
	 * @param string $name Setting name.
	 * @return array<string, mixed>
	 */
	private function setting( string $name ): array {
		if ( 'table_prefix' === $name ) {
			return array(
				'name'    => $name,
				'defined' => true,
				'value'   => isset( $GLOBALS['wpdb']->prefix ) ? (string) $GLOBALS['wpdb']->prefix : null,
			);
		}
		if ( 'environment_type' === $name ) {
			return array(
				'name'    => $name,
				'defined' => true,
				'value'   => wp_get_environment_type(),
			);
		}

		list( $defined, $value ) = call_user_func( $this->lookup, $name );

		return array(
			'name'    => $name,
			'defined' => (bool) $defined,
			'value'   => $defined ? self::safe( $name, $value ) : null,
		);
	}

	/**
	 * Hides filesystem paths in a setting's value.
	 *
	 * @param string $name  Setting name.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	private static function safe( string $name, $value ) {
		if ( is_string( $value ) && ( '' !== $value && ( '/' === $value[0] || 1 === preg_match( '/^[A-Za-z]:[\\\\\/]/', $value ) ) ) ) {
			return 'WP_DEBUG_LOG' === $name ? 'custom file' : 'path hidden';
		}
		return $value;
	}
}
