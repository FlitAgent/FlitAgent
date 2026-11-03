<?php
/**
 * Which backup plugin protects the site.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Reports whether a known backup plugin is active and, for UpdraftPlus, when it last backed up. Reads options only.
 */
final class Backup {

	/**
	 * Known backup plugins by directory.
	 */
	const BACKUP_PLUGINS = array(
		'updraftplus'                => 'UpdraftPlus',
		'jetpack-backup'             => 'Jetpack VaultPress Backup',
		'vaultpress'                 => 'VaultPress',
		'blogvault-real-time-backup' => 'BlogVault',
		'backwpup'                   => 'BackWPup',
		'all-in-one-wp-migration'    => 'All-in-One WP Migration',
		'duplicator'                 => 'Duplicator',
		'duplicator-pro'             => 'Duplicator Pro',
		'wpvivid-backuprestore'      => 'WPvivid Backup',
	);

	/**
	 * The first active known backup plugin, and its last backup time in ISO 8601 (UTC) when FlitAgent can read it.
	 *
	 * @return array{plugin: ?string, last_backup: ?string}
	 */
	public static function detect(): array {
		foreach ( self::active_plugins() as $file ) {
			$dir = dirname( $file );
			if ( ! isset( self::BACKUP_PLUGINS[ $dir ] ) ) {
				continue;
			}
			return array(
				'plugin'      => self::BACKUP_PLUGINS[ $dir ],
				'last_backup' => 'updraftplus' === $dir ? self::updraft_last_backup() : null,
			);
		}
		return array(
			'plugin'      => null,
			'last_backup' => null,
		);
	}

	/**
	 * Active plugin files.
	 *
	 * @return string[]
	 */
	private static function active_plugins(): array {
		$active = get_option( 'active_plugins', array() );
		return is_array( $active ) ? array_values( array_filter( $active, 'is_string' ) ) : array();
	}

	/**
	 * UpdraftPlus's last backup time, in ISO 8601 (UTC), or null.
	 */
	private static function updraft_last_backup(): ?string {
		$last = get_option( 'updraft_last_backup' );
		return is_array( $last ) && isset( $last['backup_time'] ) && is_numeric( $last['backup_time'] ) ? gmdate( 'c', (int) $last['backup_time'] ) : null;
	}
}
