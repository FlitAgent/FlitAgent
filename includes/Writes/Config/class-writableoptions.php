<?php
/**
 * Which options the configuration write tools may change.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * The write-side option rules (the read tools' rules are Tools\Settings\Names): a hard-block list that always wins, then
 * an allowlist of registered `show_in_rest` settings with a scalar type plus a curated list of core options, each with a
 * schema the string value is converted and validated against.
 */
final class WritableOptions {

	/**
	 * Options that are never written, even when a plugin registers them: identity, access, code loading, scheduling,
	 * storage paths, theme state owned by other tools, FlitAgent's own settings and the permalink options (use
	 * set_permalink_structure).
	 */
	const BLOCKED = array(
		'siteurl',
		'home',
		'admin_email',
		'new_admin_email',
		'users_can_register',
		'default_role',
		'active_plugins',
		'active_sitewide_plugins',
		'recently_activated',
		'uninstall_plugins',
		'template',
		'stylesheet',
		'current_theme',
		'cron',
		'rewrite_rules',
		'db_version',
		'db_upgraded',
		'initial_db_version',
		'upload_path',
		'upload_url_path',
		'sidebars_widgets',
		'nav_menu_options',
		'permalink_structure',
		'category_base',
		'tag_base',
		'auth_key',
		'auth_salt',
		'secure_auth_key',
		'secure_auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
		'wp_force_deactivated_plugins',
		'can_compress_scripts',
	);

	/**
	 * Name patterns that are never written: role tables of any blog prefix, auto-update lists, per-theme mods, widget
	 * instances, any name starting with flitagent (FlitAgent's settings) and WPCode storage.
	 */
	const BLOCKED_PATTERN = '/(^|_)user_roles$|^auto_update_|^theme_mods_|^widget_|^flitagent|^wpcode_|^_transient_|^_site_transient_/';

	/**
	 * Core options without a REST registration that are safe to change, with their schemas. Checkbox options are
	 * integers 0 or 1, as core stores them.
	 */
	const CURATED = array(
		'blogname'                      => array( 'type' => 'string' ),
		'blogdescription'               => array( 'type' => 'string' ),
		'timezone_string'               => array( 'type' => 'string' ),
		'gmt_offset'                    => array(
			'type'    => 'number',
			'minimum' => -12,
			'maximum' => 14,
		),
		'date_format'                   => array( 'type' => 'string' ),
		'time_format'                   => array( 'type' => 'string' ),
		'start_of_week'                 => array(
			'type'    => 'integer',
			'minimum' => 0,
			'maximum' => 6,
		),
		'posts_per_page'                => array(
			'type'    => 'integer',
			'minimum' => 1,
		),
		'posts_per_rss'                 => array(
			'type'    => 'integer',
			'minimum' => 1,
		),
		'rss_use_excerpt'               => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'show_on_front'                 => array(
			'type' => 'string',
			'enum' => array( 'posts', 'page' ),
		),
		'page_on_front'                 => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'page_for_posts'                => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'blog_public'                   => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'default_comment_status'        => array(
			'type' => 'string',
			'enum' => array( 'open', 'closed' ),
		),
		'default_ping_status'           => array(
			'type' => 'string',
			'enum' => array( 'open', 'closed' ),
		),
		'default_pingback_flag'         => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'require_name_email'            => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'comment_registration'          => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'close_comments_for_old_posts'  => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'close_comments_days_old'       => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'thread_comments'               => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'thread_comments_depth'         => array(
			'type'    => 'integer',
			'minimum' => 2,
			'maximum' => 10,
		),
		'page_comments'                 => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'comments_per_page'             => array(
			'type'    => 'integer',
			'minimum' => 1,
		),
		'default_comments_page'         => array(
			'type' => 'string',
			'enum' => array( 'newest', 'oldest' ),
		),
		'comment_order'                 => array(
			'type' => 'string',
			'enum' => array( 'asc', 'desc' ),
		),
		'comments_notify'               => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'moderation_notify'             => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'comment_moderation'            => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'comment_previously_approved'   => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'comment_max_links'             => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'show_avatars'                  => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'avatar_rating'                 => array(
			'type' => 'string',
			'enum' => array( 'G', 'PG', 'R', 'X' ),
		),
		'avatar_default'                => array(
			'type' => 'string',
			'enum' => array( 'mystery', 'blank', 'gravatar_default', 'identicon', 'wavatar', 'monsterid', 'retro', 'robohash' ),
		),
		'thumbnail_size_w'              => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'thumbnail_size_h'              => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'thumbnail_crop'                => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
		'medium_size_w'                 => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'medium_size_h'                 => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'large_size_w'                  => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'large_size_h'                  => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'uploads_use_yearmonth_folders' => array(
			'type' => 'integer',
			'enum' => array( 0, 1 ),
		),
	);

	/**
	 * Scalar setting types the tools accept.
	 */
	const SCALAR_TYPES = array( 'string', 'integer', 'number', 'boolean' );

	/**
	 * Why a name is never writable, or null. Case-insensitive, because option names are matched case-insensitively by
	 * the database collation on most sites.
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	public static function refusal( string $name ): ?string {
		$lower = strtolower( $name );
		if ( in_array( $lower, self::BLOCKED, true ) || 1 === preg_match( self::BLOCKED_PATTERN, $lower ) ) {
			return sprintf( 'The option "%s" is protected: it controls site identity, access, code loading or FlitAgent itself, or has its own tool.', $name );
		}
		if ( Redactor::is_secret_key( $name ) ) {
			return sprintf( 'The option "%s" is protected: its name looks like it holds a secret (password, key, salt, token).', $name );
		}
		return null;
	}

	/**
	 * Schema of a writable option, or null when it is not on the list: a registered setting shown in REST with a scalar
	 * type (its REST schema merged in), or a curated core option. Call refusal() first.
	 *
	 * @param string $name Option name.
	 * @return array<string, mixed>|null
	 */
	public static function schema( string $name ): ?array {
		$settings = get_registered_settings();
		if ( isset( $settings[ $name ] ) && is_array( $settings[ $name ] ) ) {
			$setting = $settings[ $name ];
			if ( ! empty( $setting['show_in_rest'] ) ) {
				$schema = array( 'type' => isset( $setting['type'] ) ? $setting['type'] : 'string' );
				if ( is_array( $setting['show_in_rest'] ) && isset( $setting['show_in_rest']['schema'] ) && is_array( $setting['show_in_rest']['schema'] ) ) {
					$schema = array_merge( $schema, $setting['show_in_rest']['schema'] );
				}
				return in_array( $schema['type'], self::SCALAR_TYPES, true ) ? $schema : null;
			}
		}
		return isset( self::CURATED[ $name ] ) ? self::CURATED[ $name ] : null;
	}

	/**
	 * Converts a string value to the schema's type and validates enum, minimum and maximum.
	 *
	 * @param string               $name   Option name, for messages.
	 * @param string               $value  Value as sent.
	 * @param array<string, mixed> $schema Schema from schema().
	 * @return string|int|float|bool
	 * @throws ToolError When the value does not fit.
	 */
	public static function convert( string $name, string $value, array $schema ) {
		switch ( $schema['type'] ) {
			case 'integer':
				if ( 1 !== preg_match( '/^-?\d{1,18}$/D', $value ) ) {
					throw new ToolError( sprintf( 'The value of %s must be an integer, for example "10".', esc_textarea( $name ) ) );
				}
				$converted = (int) $value;
				break;
			case 'number':
				if ( 1 !== preg_match( '/^-?\d{1,15}(\.\d{1,15})?$/D', $value ) ) {
					throw new ToolError( sprintf( 'The value of %s must be a number, for example "5.5".', esc_textarea( $name ) ) );
				}
				$converted = false === strpos( $value, '.' ) ? (int) $value : (float) $value;
				break;
			case 'boolean':
				$map = array(
					'true'  => true,
					'1'     => true,
					'false' => false,
					'0'     => false,
				);
				if ( ! isset( $map[ $value ] ) ) {
					throw new ToolError( sprintf( 'The value of %s must be true or false.', esc_textarea( $name ) ) );
				}
				$converted = $map[ $value ];
				break;
			default:
				$converted = $value;
		}//end switch

		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $converted, $schema['enum'], true ) ) {
			throw new ToolError( sprintf( 'The value of %s must be one of: %s.', esc_textarea( $name ), esc_textarea( implode( ', ', array_map( 'strval', $schema['enum'] ) ) ) ) );
		}
		if ( isset( $schema['minimum'] ) && is_numeric( $converted ) && $converted < $schema['minimum'] ) {
			throw new ToolError( sprintf( 'The value of %s must be at least %s.', esc_textarea( $name ), esc_textarea( $schema['minimum'] ) ) );
		}
		if ( isset( $schema['maximum'] ) && is_numeric( $converted ) && $converted > $schema['maximum'] ) {
			throw new ToolError( sprintf( 'The value of %s must be at most %s.', esc_textarea( $name ), esc_textarea( $schema['maximum'] ) ) );
		}
		return $converted;
	}
}
