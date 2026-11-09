<?php
/**
 * Informational checks for the approver: backups and untested extensions.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Reports whether a known backup plugin is active (and, for UpdraftPlus, when it last backed up), and which active
 * plugins and themes declare a "Tested up to" below the target branch. Never blocks the update.
 */
final class Readiness {

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
	 * Backup status.
	 *
	 * @return array{plugin: string|null, last_backup: string|null, note: string}
	 */
	public static function backup(): array {
		foreach ( self::active_plugins() as $file ) {
			$dir = dirname( $file );
			if ( ! isset( self::BACKUP_PLUGINS[ $dir ] ) ) {
				continue;
			}
			$name = self::BACKUP_PLUGINS[ $dir ];
			$last = 'updraftplus' === $dir ? self::updraft_last_backup() : null;
			return array(
				'plugin'      => $name,
				'last_backup' => $last,
				'note'        => null === $last ? sprintf( 'FlitAgent cannot read when %s last backed up; check it before approving.', $name ) : 'Check that the last backup is recent before approving.',
			);
		}
		return array(
			'plugin'      => null,
			'last_backup' => null,
			'note'        => 'No known backup plugin is active. A backup is recommended but not required.',
		);
	}

	/**
	 * Active plugins and themes whose "Tested up to" is below the target's major branch. Unknown values are skipped.
	 *
	 * @param string $target Target version.
	 * @return array<int, array{kind: string, name: string, id: string, tested_up_to: string}>
	 */
	public static function not_tested( string $target ): array {
		$found   = array();
		$plugins = self::plugins_dir();
		foreach ( self::active_plugins() as $file ) {
			if ( '.' === dirname( $file ) ) {
				continue;
			}
			$tested = get_file_data( $plugins . '/' . dirname( $file ) . '/readme.txt', array( 'tested' => 'Tested up to' ) )['tested'];
			if ( self::below( $tested, $target ) ) {
				$name    = get_file_data( $plugins . '/' . $file, array( 'name' => 'Plugin Name' ) )['name'];
				$found[] = array(
					'kind'         => 'plugin',
					'name'         => '' === $name ? $file : wp_strip_all_tags( $name ),
					'id'           => $file,
					'tested_up_to' => $tested,
				);
			}
		}
		foreach ( array_unique( array( (string) get_stylesheet(), (string) get_template() ) ) as $stylesheet ) {
			$theme  = wp_get_theme( $stylesheet );
			$tested = get_file_data( $theme->get_stylesheet_directory() . '/style.css', array( 'tested' => 'Tested up to' ) )['tested'];
			if ( self::below( $tested, $target ) ) {
				$found[] = array(
					'kind'         => 'theme',
					'name'         => wp_strip_all_tags( (string) $theme->get( 'Name' ) ),
					'id'           => $stylesheet,
					'tested_up_to' => $tested,
				);
			}
		}
		return $found;
	}

	/**
	 * Major branch (`x.y`) of a version.
	 *
	 * @param string $version Version.
	 */
	public static function branch( string $version ): string {
		$parts = explode( '.', $version );
		return (int) $parts[0] . '.' . ( isset( $parts[1] ) ? (int) $parts[1] : 0 );
	}

	/**
	 * Whether a declared "Tested up to" is below the target's branch.
	 *
	 * @param string $tested Declared value.
	 * @param string $target Target version.
	 */
	private static function below( string $tested, string $target ): bool {
		$tested = trim( $tested );
		return 1 === preg_match( '/^\d+(\.\d+)*$/', $tested ) && version_compare( self::branch( $tested ), self::branch( $target ), '<' );
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

	/**
	 * The plugins directory.
	 */
	private static function plugins_dir(): string {
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			return rtrim( (string) WP_PLUGIN_DIR, '/\\' );
		}
		return ( defined( 'WP_CONTENT_DIR' ) ? rtrim( (string) WP_CONTENT_DIR, '/\\' ) : ABSPATH . 'wp-content' ) . '/plugins';
	}
}
