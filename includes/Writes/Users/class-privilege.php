<?php
/**
 * Privilege rules for the user and role write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Users;

use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rules (no WordPress calls): which capabilities make a user or role administrator-equivalent, who counts as an
 * administrator for the lockout rails, how WordPress combines roles and user capabilities, and which user meta keys
 * FlitAgent never writes.
 */
final class Privilege {

	/**
	 * Capabilities that let a user take over the site or its users. A role or user holding any of them is
	 * administrator-equivalent, so changes involving it are destructive.
	 */
	const ADMIN_CAPS = array( 'manage_options', 'edit_users', 'promote_users', 'delete_users', 'create_users', 'remove_users', 'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins', 'install_themes', 'update_themes', 'delete_themes', 'edit_themes', 'edit_files', 'update_core', 'unfiltered_html', 'unfiltered_upload', 'level_8', 'level_9', 'level_10' );

	/**
	 * Capabilities core gives the `administrator` role (populate_roles()). They are never removed from it.
	 */
	const CORE_ADMINISTRATOR_CAPS = array( 'switch_themes', 'edit_themes', 'activate_plugins', 'edit_plugins', 'edit_users', 'edit_files', 'manage_options', 'moderate_comments', 'manage_categories', 'manage_links', 'upload_files', 'import', 'unfiltered_html', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'edit_pages', 'read', 'level_10', 'level_9', 'level_8', 'level_7', 'level_6', 'level_5', 'level_4', 'level_3', 'level_2', 'level_1', 'level_0', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_private_posts', 'read_private_posts', 'delete_private_pages', 'edit_private_pages', 'read_private_pages', 'delete_users', 'create_users', 'unfiltered_upload', 'edit_dashboard', 'update_plugins', 'delete_plugins', 'install_plugins', 'update_themes', 'install_themes', 'update_core', 'list_users', 'remove_users', 'promote_users', 'edit_theme_options', 'delete_themes', 'export' );

	/**
	 * An administrator for the lockout rails: can still change settings and fix everyone's roles.
	 */
	const ADMINISTRATOR = array( 'manage_options', 'promote_users' );

	/**
	 * User meta keys FlitAgent may write: ASCII letters, digits and `_ . : -`. MySQL compares meta keys case- and
	 * accent-insensitively and ignores trailing spaces, so anything else could reach a protected row.
	 */
	const META_KEY_PATTERN = '^[A-Za-z0-9_.:-]{1,255}$';

	/**
	 * Protected user meta, matched anywhere in the key and in any case (so any blog prefix): roles and capabilities, user
	 * levels, login sessions, application passwords, two-factor and passkey data, and email addresses.
	 */
	const PROTECTED_META = '/capabilities|user_level|session_tokens|application_passwords|two[_-]?factor|2fa|totp|(^|[^a-z])(mfa|otp)([^a-z]|$)|webauthn|passkey|backup[_-]?codes?|recovery[_-]?codes?|e-?mail/i';

	/**
	 * Columns of the users table: never user meta.
	 */
	const USER_FIELDS = array( 'id', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'user_registered', 'user_activation_key', 'user_status', 'display_name', 'spam', 'deleted' );

	/**
	 * Role slugs among a user's raw capabilities. Like WP_User::get_role_caps(), a key that names a role is a role
	 * whatever its value.
	 *
	 * @param array<string, mixed>                $caps  Raw capabilities (`{prefix}capabilities` meta).
	 * @param array<string, array<string, mixed>> $roles Roles: slug => array( 'name', 'capabilities' ).
	 * @return string[]
	 */
	public static function roles_of( array $caps, array $roles ): array {
		$found = array();
		foreach ( array_keys( $caps ) as $key ) {
			if ( isset( $roles[ $key ] ) ) {
				$found[] = (string) $key;
			}
		}
		return $found;
	}

	/**
	 * Effective capabilities: role capabilities merged in role order, then the user's own capabilities on top (core's
	 * `$allcaps`).
	 *
	 * @param array<string, mixed>                $caps  Raw capabilities.
	 * @param array<string, array<string, mixed>> $roles Roles.
	 * @return array<string, mixed>
	 */
	public static function effective( array $caps, array $roles ): array {
		$all = array();
		foreach ( self::roles_of( $caps, $roles ) as $role ) {
			$all = array_merge( $all, isset( $roles[ $role ]['capabilities'] ) ? (array) $roles[ $role ]['capabilities'] : array() );
		}
		return array_merge( $all, $caps );
	}

	/**
	 * Whether effective capabilities make an administrator (manage_options and promote_users).
	 *
	 * @param array<string, mixed> $effective Effective capabilities.
	 * @return bool
	 */
	public static function is_administrator( array $effective ): bool {
		foreach ( self::ADMINISTRATOR as $cap ) {
			if ( empty( $effective[ $cap ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Administrator-equivalent capabilities granted, sorted.
	 *
	 * @param array<string, mixed> $caps Capabilities (effective, role or user).
	 * @return string[]
	 */
	public static function admin_caps( array $caps ): array {
		return array_values( array_intersect( self::granted( $caps ), self::ADMIN_CAPS ) );
	}

	/**
	 * Names of the granted (truthy) capabilities, sorted.
	 *
	 * @param array<string, mixed> $caps Capabilities.
	 * @return string[]
	 */
	public static function granted( array $caps ): array {
		$granted = array();
		foreach ( $caps as $cap => $grant ) {
			if ( ! empty( $grant ) ) {
				$granted[] = (string) $cap;
			}
		}
		sort( $granted, SORT_STRING );
		return $granted;
	}

	/**
	 * Whether FlitAgent refuses to write a user meta key.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_protected_meta_key( string $key ): bool {
		return 1 === preg_match( self::PROTECTED_META, $key )
			|| Redactor::is_secret_key( $key )
			|| in_array( strtolower( $key ), self::USER_FIELDS, true );
	}
}
