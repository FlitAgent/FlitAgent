<?php
/**
 * Backup plugin detection for package previews.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Themes;

defined( 'ABSPATH' ) || exit;

/**
 * Names the known backup plugins that are active (by plugin file) and their last backup time where the plugin stores
 * it in a readable option (UpdraftPlus, BackWPup). FlitAgent does not require, start or verify a backup.
 */
final class Backups {

	/**
	 * Known backup plugins: plugin file => name.
	 */
	const PLUGINS = array(
		'updraftplus/updraftplus.php'                     => 'UpdraftPlus',
		'jetpack-backup/jetpack-backup.php'               => 'Jetpack VaultPress Backup',
		'vaultpress/vaultpress.php'                       => 'VaultPress',
		'blogvault-real-time-backup/blogvault.php'        => 'BlogVault',
		'backwpup/backwpup.php'                           => 'BackWPup',
		'backwpup-pro/backwpup.php'                       => 'BackWPup Pro',
		'all-in-one-wp-migration/all-in-one-wp-migration.php' => 'All-in-One WP Migration',
		'duplicator/duplicator.php'                       => 'Duplicator',
		'duplicator-pro/duplicator-pro.php'               => 'Duplicator Pro',
		'wpvivid-backuprestore/wpvivid-backuprestore.php' => 'WPvivid Backup',
	);

	/**
	 * Backup status for a preview: `plugin` (names, or 'none'), `last_backup` and `note`.
	 *
	 * @return array<string, string>
	 */
	public static function status(): array {
		$active = get_option( 'active_plugins', array() );
		$active = is_array( $active ) ? $active : array();
		$found  = array();
		foreach ( self::PLUGINS as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$found[ $file ] = $name;
			}
		}
		if ( array() === $found ) {
			return array(
				'plugin'      => 'none',
				'last_backup' => 'unknown',
				'note'        => 'No known backup plugin is active. A backup is not required, but nothing restores the theme files if this goes wrong except the rollback described here.',
			);
		}
		$last = 'not readable';
		foreach ( $found as $file => $name ) {
			$time = self::last_backup( $file );
			if ( $time > 0 ) {
				$last = gmdate( 'Y-m-d H:i', $time ) . ' UTC (' . $name . ')';
				break;
			}
		}
		return array(
			'plugin'      => implode( ', ', $found ),
			'last_backup' => $last,
			'note'        => 'A backup plugin is active; a backup is not required. FlitAgent does not start or check backups.',
		);
	}

	/**
	 * Last backup time a plugin stores, or 0.
	 *
	 * @param string $file Plugin file.
	 * @return int
	 */
	private static function last_backup( string $file ): int {
		if ( 'updraftplus/updraftplus.php' === $file ) {
			$last = get_option( 'updraft_last_backup', array() );
			return is_array( $last ) && isset( $last['backup_time'] ) ? (int) $last['backup_time'] : 0;
		}
		if ( 0 === strpos( $file, 'backwpup' ) ) {
			$jobs = get_site_option( 'backwpup_jobs', array() );
			$runs = is_array( $jobs ) ? array_column( array_filter( $jobs, 'is_array' ), 'lastrun' ) : array();
			return array() === $runs ? 0 : (int) max( $runs );
		}
		return 0;
	}
}
