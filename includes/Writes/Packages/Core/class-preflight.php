<?php
/**
 * Checks a core update must pass before it is offered for approval.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Core;

use FlitAgent\Writes\Assessment;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses up front what core would otherwise discover mid-update or answer with an interactive prompt: file changes
 * disabled, missing capability, multisite, a version-controlled install, a filesystem that needs credentials, another
 * core update running, and PHP or MySQL below the target's requirements. Read-only, except that
 * `get_filesystem_method()` probes the directory with a temporary file, as wp-admin's Updates screen does.
 */
final class Preflight {

	/**
	 * How long core's `core_updater` lock lasts, in seconds (Core_Upgrader::upgrade() uses 15 minutes).
	 */
	const LOCK_TTL = 900;

	/**
	 * A refusal for the site, or null.
	 *
	 * @return Assessment|null
	 */
	public static function site(): ?Assessment {
		if ( ! wp_is_file_mod_allowed( 'flitagent_core_update' ) ) {
			return Assessment::prohibited( 'file_mods_disallowed', 'File changes are disabled on this site (DISALLOW_FILE_MODS or the file_mod_allowed filter), so WordPress cannot be updated.' );
		}
		if ( ! current_user_can( 'update_core' ) ) {
			return Assessment::prohibited( 'forbidden', 'Updating WordPress needs the update_core capability.' );
		}
		if ( is_multisite() ) {
			return Assessment::prohibited( 'multisite', 'Core updates on multisite networks are not offered; update from Network Admin > Updates.' );
		}
		self::load();
		if ( ( new \WP_Automatic_Updater() )->is_vcs_checkout( ABSPATH ) ) {
			return Assessment::prohibited( 'vcs', 'WordPress is installed from version control (.git, .svn, .hg or .bzr found), so it is not updated in place; update it through version control.' );
		}
		$method = (string) get_filesystem_method( array(), ABSPATH );
		if ( 'direct' !== $method ) {
			return Assessment::prohibited( 'filesystem', sprintf( 'WordPress can only write its files here through %s, which needs credentials FlitAgent cannot supply (it needs direct file access). Update from Dashboard > Updates in wp-admin instead.', $method ) );
		}
		$lock = get_option( 'core_updater.lock' );
		if ( is_numeric( $lock ) && time() - (int) $lock < self::LOCK_TTL ) {
			return Assessment::prohibited( 'locked', 'Another core update is in progress (core_updater lock). Wait 15 minutes from its start and try again.' );
		}
		return null;
	}

	/**
	 * PHP and database compatibility with an offer, and a refusal when either is too old.
	 *
	 * @param object $offer Offer.
	 * @return array{compatibility: array<string, array<string, mixed>>, refusal: Assessment|null}
	 */
	public static function requirements( $offer ): array {
		global $wpdb;

		$version  = (string) $offer->current;
		$php_need = isset( $offer->php_version ) ? (string) $offer->php_version : '';
		$php      = (string) phpversion();
		$db_need  = isset( $offer->mysql_version ) ? (string) $offer->mysql_version : '';
		$db       = (string) $wpdb->db_version();
		$php_ok   = '' === $php_need || version_compare( $php, $php_need, '>=' );
		$is_mysql = ! empty( $wpdb->is_mysql );
		$db_ok    = ! $is_mysql || '' === $db_need || version_compare( $db, $db_need, '>=' );
		$database = array(
			'current'  => $db,
			'required' => $db_need,
			'ok'       => $db_ok,
		);
		if ( ! $is_mysql ) {
			$database['note'] = 'Not MySQL (database drop-in); not checked, as core does not check it either.';
		}

		$problems = array();
		if ( ! $php_ok ) {
			$problems[] = sprintf( 'WordPress %s requires PHP %s or newer; this site runs PHP %s.', $version, $php_need, $php );
		}
		if ( ! $db_ok ) {
			$problems[] = sprintf( 'WordPress %s requires MySQL %s or newer; this site\'s database is %s.', $version, $db_need, $db );
		}

		return array(
			'compatibility' => array(
				'php'      => array(
					'current'  => $php,
					'required' => $php_need,
					'ok'       => $php_ok,
				),
				'database' => $database,
			),
			'refusal'       => array() === $problems ? null : Assessment::prohibited( 'requirements', implode( ' ', $problems ) ),
		);
	}

	/**
	 * Loads the admin code the checks need, when the request has not (REST requests do not load wp-admin).
	 */
	private static function load(): void {
		if ( ! function_exists( 'get_filesystem_method' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! class_exists( 'WP_Automatic_Updater', false ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		}
	}
}
