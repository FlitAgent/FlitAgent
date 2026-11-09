<?php
/**
 * Backup plugin detection for plugin package previews.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Plugins;

defined( 'ABSPATH' ) || exit;

/**
 * Names the active backup plugins in a preview, with the last backup time when the plugin keeps it in an option FlitAgent
 * can read. Informational only: FlitAgent does not require, run or verify a backup.
 */
final class Backups {

	/**
	 * Known backup plugins: plugin file => name.
	 */
	const KNOWN = array(
		'updraftplus/updraftplus.php'                     => 'UpdraftPlus',
		'jetpack-backup/jetpack-backup.php'               => 'Jetpack VaultPress Backup',
		'vaultpress/vaultpress.php'                       => 'VaultPress',
		'blogvault-real-time-backup/blogvault.php'        => 'BlogVault',
		'backwpup/backwpup.php'                           => 'BackWPup',
		'all-in-one-wp-migration/all-in-one-wp-migration.php' => 'All-in-One WP Migration',
		'duplicator/duplicator.php'                       => 'Duplicator',
		'duplicator-pro/duplicator-pro.php'               => 'Duplicator Pro',
		'wpvivid-backuprestore/wpvivid-backuprestore.php' => 'WPvivid',
	);

	/**
	 * One sentence for the preview.
	 *
	 * @return string
	 */
	public static function status(): string {
		$found = array();
		foreach ( self::KNOWN as $plugin => $name ) {
			if ( ! is_plugin_active( $plugin ) ) {
				continue;
			}
			$time    = self::last_backup( $plugin );
			$found[] = sprintf( '%s (%s)', $name, null === $time ? 'last backup time not readable here' : 'last backup ' . gmdate( 'Y-m-d H:i', $time ) . ' UTC' );
		}
		if ( array() === $found ) {
			return 'No backup plugin detected. FlitAgent does not require one.';
		}
		return implode( '; ', $found ) . '. FlitAgent does not require a backup.';
	}

	/**
	 * Last backup time a plugin stores in an option, or null.
	 *
	 * @param string $plugin Plugin file.
	 * @return int|null
	 */
	private static function last_backup( string $plugin ): ?int {
		if ( 'updraftplus/updraftplus.php' === $plugin ) {
			$last = get_option( 'updraft_last_backup' );
			return is_array( $last ) && isset( $last['backup_time'] ) && is_numeric( $last['backup_time'] ) && (int) $last['backup_time'] > 0 ? (int) $last['backup_time'] : null;
		}
		if ( 'backwpup/backwpup.php' === $plugin ) {
			$latest = 0;
			$jobs   = get_site_option( 'backwpup_jobs' );
			foreach ( is_array( $jobs ) ? $jobs : array() as $job ) {
				if ( is_array( $job ) && isset( $job['lastrun'] ) && is_numeric( $job['lastrun'] ) ) {
					$latest = max( $latest, (int) $job['lastrun'] );
				}
			}
			return $latest > 0 ? $latest : null;
		}
		return null;
	}
}
