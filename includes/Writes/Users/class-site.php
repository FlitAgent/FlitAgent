<?php
/**
 * WordPress reads and rails shared by the user and role write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Users;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only helpers: targets with object-level capability checks, roles, the lockout rail (no change may leave the site
 * without an administrator or take an administrator-equivalent capability from the caller), the escalation rail (no
 * one may grant a capability they do not hold), validation of capability names, and state fingerprints.
 */
final class Site {



	/**
	 * Capability names: ASCII letters, digits, `_` and `-`.
	 */
	const CAP_PATTERN = '/^[A-Za-z0-9_-]{1,191}$/D';

	/**
	 * Most capabilities one call may add or remove.
	 */
	const MAX_CAPS = 100;

	/**
	 * Fields of a user that make up its fingerprint.
	 */
	const USER_STATE = array( 'user_login', 'user_email', 'display_name', 'first_name', 'last_name', 'nickname', 'description', 'user_url' );

	/**
	 * Every role: slug => array( 'name' => string, 'capabilities' => array ).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function roles(): array {
		$roles = wp_roles()->roles;
		return is_array( $roles ) ? $roles : array();
	}

	/**
	 * The calling user's ID.
	 */
	public static function caller_id(): int {
		return (int) get_current_user_id();
	}

	/**
	 * A user the caller may act on with a meta capability (`edit_user`, `promote_user`, `delete_user`), or a refusal
	 * that reads the same for a missing and a forbidden user.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_cap Meta capability checked against the user.
	 * @return object|Assessment
	 */
	public static function target( int $user_id, string $meta_cap ) {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user || ! current_user_can( $meta_cap, $user_id ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'User %d was not found or you cannot change it.', $user_id ) );
		}
		return $user;
	}

	/**
	 * A user's raw capabilities (role slugs and capabilities of their own).
	 *
	 * @param object $user User.
	 * @return array<string, mixed>
	 */
	public static function caps_of( $user ): array {
		return isset( $user->caps ) && is_array( $user->caps ) ? $user->caps : array();
	}

	/**
	 * The user's capabilities that are not roles.
	 *
	 * @param array<string, mixed>                $caps  Raw capabilities.
	 * @param array<string, array<string, mixed>> $roles Roles.
	 * @return array<string, mixed>
	 */
	public static function own_caps( array $caps, array $roles ): array {
		return array_diff_key( $caps, array_flip( Privilege::roles_of( $caps, $roles ) ) );
	}

	/**
	 * Refusal when a change would leave no administrator (Privilege::ADMINISTRATOR) or would take any
	 * administrator-equivalent capability from the caller; null when it is fine. Computed from stored roles and raw
	 * capabilities before and after the change.
	 *
	 * @param array<int, array<string, mixed>>         $caps_after  User ID => raw capabilities after the change (only
	 *                                                              users whose capabilities change).
	 * @param array<string, array<string, mixed>>|null $roles_after Roles after the change, or null when unchanged.
	 * @param int[]                                    $deleted     Users the change deletes.
	 * @return Assessment|null
	 */
	public static function lockout( array $caps_after, ?array $roles_after = null, array $deleted = array() ): ?Assessment {
		$roles  = self::roles();
		$after  = null === $roles_after ? $roles : $roles_after;
		$caller = self::caller_id();

		$candidates = self::candidates();
		$before_ids = array();
		$after_ids  = array();
		foreach ( $candidates as $id => $caps ) {
			$was = Privilege::effective( $caps, $roles );
			$now = in_array( $id, $deleted, true ) ? array() : Privilege::effective( isset( $caps_after[ $id ] ) ? $caps_after[ $id ] : $caps, $after );
			if ( Privilege::is_administrator( $was ) ) {
				$before_ids[] = $id;
			}
			if ( Privilege::is_administrator( $now ) ) {
				$after_ids[] = $id;
			}
			if ( $id === $caller && ! in_array( $id, $deleted, true ) ) {
				$lost = array_values( array_diff( Privilege::admin_caps( $was ), Privilege::admin_caps( $now ) ) );
				if ( array() !== $lost ) {
					return Assessment::prohibited( 'caller_lockout', sprintf( 'This would remove administrator capabilities from your own account (%s). Ask another administrator to make this change.', implode( ', ', $lost ) ) );
				}
			}
		}

		if ( array() !== $before_ids && array() === $after_ids ) {
			return Assessment::prohibited( 'last_administrator', 'This would leave the site without an administrator (a user who can manage options and change roles).' );
		}
		return null;
	}

	/**
	 * IDs of the administrators now (Privilege::ADMINISTRATOR), sorted. Part of preconditions so a change approved
	 * while another administrator existed goes stale if that changes.
	 *
	 * @return int[]
	 */
	public static function administrator_ids(): array {
		$roles = self::roles();
		$ids   = array();
		foreach ( self::candidates() as $id => $caps ) {
			if ( Privilege::is_administrator( Privilege::effective( $caps, $roles ) ) ) {
				$ids[] = $id;
			}
		}
		sort( $ids );
		return $ids;
	}

	/**
	 * Capabilities from the list that the caller does not hold: not in their stored roles and capabilities, or, for an
	 * administrator-equivalent one, denied to them by current_user_can() (for example by a plugin that restricts this
	 * caller). A denial that core applies to every user through a site-wide setting (unfiltered uploads not allowed,
	 * file editing or file changes turned off) is not an escalation: the grantee is denied too.
	 *
	 * @param string[] $caps Capabilities to grant.
	 * @return string[]
	 */
	public static function beyond_caller( array $caps ): array {
		$caller    = get_userdata( self::caller_id() );
		$effective = $caller ? Privilege::effective( self::caps_of( $caller ), self::roles() ) : array();
		$missing   = array();
		foreach ( $caps as $cap ) {
			if ( empty( $effective[ $cap ] ) || ( in_array( $cap, Privilege::ADMIN_CAPS, true ) && ! current_user_can( $cap ) && ! self::denied_to_everyone( $cap ) ) ) {
				$missing[] = $cap;
			}
		}
		return $missing;
	}

	/**
	 * Refusal when the target holds administrator-equivalent capabilities the caller lacks: the caller may not take
	 * over (email, password reset), demote, re-capability or delete someone who outranks them. Null for the caller
	 * themselves or a target who does not outrank them.
	 *
	 * @param object $user Target user.
	 * @return Assessment|null
	 */
	public static function outrank_refusal( $user ): ?Assessment {
		if ( self::caller_id() === (int) $user->ID ) {
			return null;
		}
		$higher = self::beyond_caller( Privilege::admin_caps( Privilege::effective( self::caps_of( $user ), self::roles() ) ) );
		if ( array() === $higher ) {
			return null;
		}
		return Assessment::prohibited( 'outranks_caller', sprintf( 'User %d has more administrator capabilities than you (%s), so you cannot change their email, password, role or capabilities, or delete them.', $user->ID, implode( ', ', $higher ) ) );
	}

	/**
	 * Whether core itself denies a capability to everyone: core's own mapping for a logged-out user, with the
	 * `map_meta_cap` filters detached for that one call (see core()). For unfiltered_upload that is exactly the
	 * wp-config.php setting on a single site (writes refuse on multisite, where is_super_admin( 0 ) would look at the
	 * current user). A filter is left out on purpose: its denials say nothing about the user a capability would be
	 * granted to (a filter may deny every current holder and still allow the grantee), so they can never make a
	 * capability count as denied to everyone. Never decided from the caller.
	 *
	 * @param string $cap Capability.
	 * @return bool
	 */
	private static function nobody_can( string $cap ): bool {
		return in_array( 'do_not_allow', self::core( 'map_meta_cap', static fn() => map_meta_cap( $cap, 0 ) ), true );
	}

	/**
	 * Whether file changes are provably off for everyone: core's own setting (DISALLOW_FILE_MODS) turns them off and no
	 * `file_mod_allowed` filter is registered. A filter's answer in this request is the caller's: it may turn file
	 * changes off for the caller only, or back on for someone else, and neither can be told apart here, so with any such
	 * filter the file capabilities are not counted as denied to everyone (fails closed).
	 *
	 * @param string $context Core's usage context.
	 * @return bool
	 */
	private static function file_mods_off( string $context ): bool {
		return ! self::core_file_mods_allowed( $context ) && false === has_filter( 'file_mod_allowed' );
	}

	/**
	 * Whether core allows file changes, from its own setting (DISALLOW_FILE_MODS) with the `file_mod_allowed` filters
	 * detached for that one call: a filter may turn file changes off for this caller's request only, which says nothing
	 * about the user a file capability would be granted to.
	 *
	 * @param string $context Core's usage context.
	 * @return bool
	 */
	private static function core_file_mods_allowed( string $context ): bool {
		return (bool) self::core( 'file_mod_allowed', static fn() => wp_is_file_mod_allowed( $context ) );
	}

	/**
	 * Runs a core function with a hook's callbacks detached, and always puts them back (the same WP_Hook object, so a
	 * run in progress keeps its state). A callback added to or removed from that hook during the call would be lost;
	 * core's two calls used here (map_meta_cap, wp_is_file_mod_allowed) change no hooks.
	 *
	 * @param string   $hook Hook name.
	 * @param callable $call Core call.
	 * @return mixed
	 */
	private static function core( string $hook, callable $call ) {
		global $wp_filter;
		$callbacks = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ] : null;
		unset( $wp_filter[ $hook ] );
		try {
			return $call();
		} finally {
			if ( null !== $callbacks ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- puts back the hook detached above.
				$wp_filter[ $hook ] = $callbacks;
			}
		}
	}

	/**
	 * Whether core's map_meta_cap() denies a capability to every user on this site because of a site-wide setting.
	 *
	 * @param string $cap Capability.
	 * @return bool
	 */
	private static function denied_to_everyone( string $cap ): bool {
		switch ( $cap ) {
			case 'unfiltered_upload':
				return self::nobody_can( 'unfiltered_upload' );
			case 'unfiltered_html':
				return defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML;
			case 'edit_files':
			case 'edit_plugins':
			case 'edit_themes':
				return ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || self::file_mods_off( 'capability_edit_themes' );
			case 'install_plugins':
			case 'update_plugins':
			case 'delete_plugins':
			case 'install_themes':
			case 'update_themes':
			case 'delete_themes':
			case 'update_core':
				return self::file_mods_off( 'capability_update_core' );
		}//end switch
		return false;
	}

	/**
	 * Refusal for granting capabilities: a role name (WordPress would treat it as that role), `do_not_allow`, or one the
	 * caller lacks; null when fine.
	 *
	 * @param string[]                            $caps  Capabilities to grant.
	 * @param array<string, array<string, mixed>> $roles Roles.
	 * @return Assessment|null
	 */
	public static function grant_refusal( array $caps, array $roles ): ?Assessment {
		$role_names = array_values( array_intersect( $caps, array_keys( $roles ) ) );
		if ( array() !== $role_names ) {
			return Assessment::prohibited( 'role_as_capability', sprintf( '%s is a role, not a capability; WordPress would treat it as that role. Use set_user_role instead.', implode( ', ', $role_names ) ) );
		}
		if ( in_array( 'do_not_allow', $caps, true ) ) {
			return Assessment::prohibited( 'invalid_capability', 'do_not_allow is not a capability that can be granted.' );
		}
		$missing = self::beyond_caller( $caps );
		if ( array() !== $missing ) {
			return Assessment::prohibited( 'escalation', sprintf( 'You cannot grant capabilities you do not have yourself (%s).', implode( ', ', $missing ) ) );
		}
		return null;
	}

	/**
	 * Whether a role may be assigned by the caller (core's `editable_roles`).
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	public static function is_editable_role( string $role ): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, applied as get_editable_roles() does.
		$editable = apply_filters( 'editable_roles', self::roles() );
		return is_array( $editable ) && isset( $editable[ $role ] );
	}

	/**
	 * Validated capability names from an argument: unique, in order.
	 *
	 * @param mixed  $value List from the arguments.
	 * @param string $name Argument name for errors.
	 * @return string[]
	 * @throws ToolError When a name is invalid or the list is too long.
	 */
	public static function cap_names( $value, string $name ): array {
		$caps = array_values( array_unique( is_array( $value ) ? array_map( 'strval', $value ) : array() ) );
		if ( count( $caps ) > self::MAX_CAPS ) {
			throw new ToolError( sprintf( '%s may list at most %d capabilities.', esc_textarea( $name ), (int) self::MAX_CAPS ) );
		}
		foreach ( $caps as $cap ) {
			if ( 1 !== preg_match( self::CAP_PATTERN, $cap ) ) {
				throw new ToolError( sprintf( '%s: "%s" is not a capability name (letters, digits, _ and -).', esc_textarea( $name ), esc_textarea( $cap ) ) );
			}
		}
		return $caps;
	}

	/**
	 * Number of posts the user authors, by post type (every status), like the posts wp_delete_user() reassigns.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, int>
	 */
	public static function post_counts( int $user_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only count, prepared; core has no per-type count over every status.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, COUNT(*) AS total FROM {$wpdb->posts} WHERE post_author = %d GROUP BY post_type", $user_id ), ARRAY_A );
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['post_type'] ] = (int) $row['total'];
		}
		ksort( $counts, SORT_STRING );
		return $counts;
	}

	/**
	 * Fingerprint of a user's profile fields, email and raw capabilities.
	 *
	 * @param object $user User.
	 * @return string
	 */
	public static function user_fingerprint( $user ): string {
		$state = array();
		foreach ( self::USER_STATE as $field ) {
			$state[ $field ] = isset( $user->$field ) ? (string) $user->$field : '';
		}
		$state['caps'] = self::caps_of( $user );
		return Canonical::hash( $state );
	}

	/**
	 * Fingerprint of one role's stored entry (null when it does not exist).
	 *
	 * @param string $role Role slug.
	 * @return string
	 */
	public static function role_fingerprint( string $role ): string {
		$roles = self::roles();
		return Canonical::hash( isset( $roles[ $role ] ) ? $roles[ $role ] : null );
	}

	/**
	 * "a, b" or "(none)".
	 *
	 * @param string[] $items Items.
	 * @return string
	 */
	public static function listing( array $items ): string {
		return array() === $items ? '(none)' : implode( ', ', $items );
	}

	/**
	 * Refusal when the caller may not manage roles (`promote_users`); null when they may.
	 *
	 * @return Assessment|null
	 */
	public static function role_manager_refusal(): ?Assessment {
		return current_user_can( 'promote_users' ) ? null : Assessment::prohibited( 'forbidden', 'You cannot manage roles.' );
	}

	/**
	 * A role's capabilities as name => bool (null when the role does not exist).
	 *
	 * @param array<string, array<string, mixed>> $roles Roles.
	 * @param string                              $role  Role slug.
	 * @return array<string, bool>|null
	 */
	public static function role_caps( array $roles, string $role ): ?array {
		if ( ! isset( $roles[ $role ] ) ) {
			return null;
		}
		$caps = array();
		foreach ( (array) $roles[ $role ]['capabilities'] as $cap => $grant ) {
			$caps[ (string) $cap ] = (bool) $grant;
		}
		return $caps;
	}

	/**
	 * Refusal for removing a role: `administrator`, the default role for new users, or a role any user still has
	 * (even as a secondary role); null when it may go.
	 *
	 * @param string $role Role slug.
	 * @return Assessment|null
	 */
	public static function role_removal_refusal( string $role ): ?Assessment {
		if ( 'administrator' === $role ) {
			return Assessment::prohibited( 'protected_role', 'FlitAgent never deletes the administrator role.' );
		}
		if ( (string) get_option( 'default_role' ) === $role ) {
			return Assessment::prohibited( 'default_role', sprintf( 'Role %s is the default role for new users; choose another default role in Settings > General first.', $role ) );
		}
		$users = get_users(
			array(
				'role'   => $role,
				'number' => 1,
				'fields' => 'ID',
			)
		);
		if ( array() !== $users ) {
			return Assessment::prohibited( 'role_in_use', sprintf( 'Role %s is still assigned to users; change their role first.', $role ) );
		}
		return null;
	}

	/**
	 * Map of capability => bool from a JSON snapshot (stored as a string so the receipt keeps it as it was).
	 *
	 * @param mixed $json JSON.
	 * @return array<string, bool>
	 */
	public static function caps_from_json( $json ): array {
		$decoded = is_string( $json ) ? json_decode( $json, true ) : null;
		$caps    = array();
		foreach ( is_array( $decoded ) ? $decoded : array() as $cap => $grant ) {
			if ( is_string( $cap ) && 1 === preg_match( self::CAP_PATTERN, $cap ) ) {
				$caps[ $cap ] = (bool) $grant;
			}
		}
		return $caps;
	}

	/**
	 * Loads wp_delete_user() (wp-admin/includes/user.php) outside wp-admin.
	 */
	public static function load_admin_api(): void {
		if ( ! function_exists( 'wp_delete_user' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/user.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
	}

	/**
	 * Users who may count as administrators now (they can manage options), plus the caller: ID => raw capabilities.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function candidates(): array {
		$found = array();
		foreach ( get_users( array( 'capability' => 'manage_options' ) ) as $user ) {
			$found[ (int) $user->ID ] = self::caps_of( $user );
		}
		$caller = get_userdata( self::caller_id() );
		if ( $caller ) {
			$found[ (int) $caller->ID ] = self::caps_of( $caller );
		}
		return $found;
	}
}
