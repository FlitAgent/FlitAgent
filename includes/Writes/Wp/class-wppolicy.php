<?php
/**
 * Write policy from WordPress options.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Wp;

use FlitAgent\Writes\Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the FlitAgent settings. Access for AI tools is Read and propose changes on a site that never saved it
 * (DEFAULT_ENABLED); a saved value turns writes on only when it is exactly on, and nothing is auto-approved unless an
 * exact value says so.
 */
final class WpPolicy implements Policy {

	/**
	 * What the page name of a change's link starts with (approve_url()); the change's UUID follows.
	 */
	const CHANGE_PAGE = 'flitagent-change-';

	/**
	 * Access for AI tools: "1" is Read and propose changes, "0" Read only. A site that never saved it reads
	 * DEFAULT_ENABLED.
	 */
	const OPTION_ENABLED = 'flitagent_writes_enabled';

	/**
	 * What a site that never saved OPTION_ENABLED gets: Read and propose changes. Every change still waits in Approvals
	 * unless an administrator ticked its tool for auto-approve, and nothing reaches the site before it connects.
	 */
	const DEFAULT_ENABLED = '1';

	/**
	 * List of tool names an administrator enabled auto-approve for. Default none.
	 */
	const OPTION_AUTO = 'flitagent_auto_approve';

	/**
	 * Approval expiry in minutes (5-60, default 15).
	 */
	const OPTION_TTL = 'flitagent_approval_ttl';

	/**
	 * "1" drops the tables and options on uninstall. On until an administrator saves it unticked ("0"), so a site that
	 * never saved it reads DEFAULT_REMOVE_DATA.
	 */
	const OPTION_REMOVE_DATA = 'flitagent_remove_data_on_uninstall';

	/**
	 * What a site that never saved OPTION_REMOVE_DATA gets: on.
	 */
	const DEFAULT_REMOVE_DATA = '1';

	/**
	 * Every option FlitAgent's write layer stores.
	 */
	const OPTIONS = array( self::OPTION_ENABLED, self::OPTION_AUTO, self::OPTION_TTL, self::OPTION_REMOVE_DATA, Tables::VERSION_OPTION );

	/**
	 * Default approval expiry, minutes.
	 */
	const DEFAULT_TTL = 15;

	/**
	 * Whether writes are on (Read and propose changes), DEFAULT_ENABLED on a site that never saved the setting.
	 */
	public function writes_enabled(): bool {
		return self::is_on( get_option( self::OPTION_ENABLED, self::DEFAULT_ENABLED ) );
	}

	/**
	 * Whether this is multisite.
	 */
	public function is_multisite(): bool {
		return is_multisite();
	}

	/**
	 * Whether auto-approve is enabled for a tool (exact names in a stored list only).
	 *
	 * @param string $tool Tool name.
	 * @return bool
	 */
	public function auto_approves( string $tool ): bool {
		$tools = get_option( self::OPTION_AUTO, array() );
		return is_array( $tools ) && in_array( $tool, $tools, true );
	}

	/**
	 * Approval expiry in seconds, from minutes clamped to 5-60.
	 */
	public function approval_ttl(): int {
		return self::ttl_minutes() * 60;
	}

	/**
	 * Approval expiry in minutes, clamped to 5-60.
	 */
	public static function ttl_minutes(): int {
		return self::clamp_minutes( get_option( self::OPTION_TTL, self::DEFAULT_TTL ) );
	}

	/**
	 * A stored or submitted expiry as whole minutes from 5 to 60; anything non-numeric is the default.
	 *
	 * @param mixed $minutes Value.
	 * @return int
	 */
	public static function clamp_minutes( $minutes ): int {
		if ( ! is_numeric( $minutes ) ) {
			return self::DEFAULT_TTL;
		}
		return max( 5, min( 60, (int) $minutes ) );
	}

	/**
	 * The link to a change, as an AI app gets it in a tool result. It is built outside the administrator's browser session,
	 * so it cannot carry a nonce that session accepts: Admin::change_link() sends it on to the change with a fresh one. The
	 * earlier form of this link (`page=flitagent-approvals&change=<id>`) opens the Approvals list.
	 *
	 * @param string $change_id Change UUID.
	 * @return string
	 */
	public function approve_url( string $change_id ): string {
		return admin_url( 'admin.php?page=' . self::CHANGE_PAGE . rawurlencode( $change_id ) );
	}

	/**
	 * Whether a stored flag is on.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	public static function is_on( $value ): bool {
		return in_array( $value, array( '1', 1, true ), true );
	}
}
