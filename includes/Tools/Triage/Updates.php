<?php
/**
 * Update readiness rules.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Triage;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a snapshot of pending updates (see UpdateSnapshot) into a readiness report: what each update is, whether this
 * site meets its requirements, a recommended order, and the backup status. Pure: no WordPress calls.
 */
final class Updates {

	/**
	 * Seconds after which the last known backup counts as old.
	 */
	const BACKUP_MAX_AGE = 86400;

	/**
	 * Id of the core update.
	 */
	// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- A lower-case machine id, not the product name.
	const CORE_ID = 'wordpress';

	/**
	 * The readiness report.
	 *
	 * @param array<string, mixed> $snapshot Snapshot from UpdateSnapshot::read().
	 * @return array<string, mixed>
	 */
	public static function run( array $snapshot ): array {
		$wp  = (string) $snapshot['wordpress'];
		$php = (string) $snapshot['php'];

		$updates = array();
		foreach ( $snapshot['plugins'] as $plugin ) {
			$blocked   = 0 === strpos( $plugin['file'], 'flitagent/' ) ? 'FlitAgent does not update itself: update it in wp-admin (Plugins).' : self::requirements( $plugin['name'], $plugin['requires'], $plugin['requires_php'], $wp, $php );
			$updates[] = self::item( 'plugin', $plugin['file'], $plugin['name'], $plugin['version'], $plugin['new_version'], (bool) $plugin['active'], $plugin['requires'], $plugin['requires_php'], (string) $plugin['tested'], $wp, $blocked );
		}
		foreach ( $snapshot['themes'] as $theme ) {
			$blocked   = self::requirements( $theme['name'], $theme['requires'], $theme['requires_php'], $wp, $php );
			$updates[] = self::item( 'theme', $theme['stylesheet'], $theme['name'], $theme['version'], $theme['new_version'], (bool) $theme['active'], $theme['requires'], $theme['requires_php'], '', $wp, $blocked );
		}
		if ( null !== $snapshot['core'] ) {
			$core      = $snapshot['core'];
			$blocked   = self::requirements( 'WordPress ' . $core['version'], '', (string) $core['php_version'], $wp, $php );
			$updates[] = self::item( 'core', self::CORE_ID, 'WordPress', $wp, $core['version'], true, '', (string) $core['php_version'], '', $wp, $blocked );
		}

		usort(
			$updates,
			static function ( array $a, array $b ): int {
				return self::order( $a ) <=> self::order( $b );
			}
		);

		$blocked = count(
			array_filter(
				$updates,
				static function ( array $update ): bool {
					return 'blocked' === $update['status'];
				}
			)
		);
		$backup  = self::backup( $snapshot['backup'], (int) $snapshot['now'] );

		return array(
			'summary'  => array(
				'total'   => count( $updates ),
				'ready'   => count( $updates ) - $blocked,
				'blocked' => $blocked,
			),
			'backup'   => $backup,
			'warnings' => null === $backup['warning'] ? array() : array( $backup['warning'] ),
			'updates'  => $updates,
		);
	}

	/**
	 * One update.
	 *
	 * @param string      $type         plugin, theme or core.
	 * @param string      $id           Plugin file, theme stylesheet, or CORE_ID.
	 * @param string      $name         Name.
	 * @param string      $from         Installed version.
	 * @param string      $to           New version.
	 * @param bool        $active       Whether the site uses it.
	 * @param string      $requires     Required WordPress version, or ''.
	 * @param string      $requires_php Required PHP version, or ''.
	 * @param string      $tested       Tested up to, or ''.
	 * @param string      $wp           Installed WordPress version.
	 * @param string|null $blocked      Why it cannot be applied, or null.
	 * @return array<string, mixed>
	 */
	private static function item( string $type, string $id, string $name, string $from, string $to, bool $active, string $requires, string $requires_php, string $tested, string $wp, ?string $blocked ): array {
		return array(
			'type'         => $type,
			'id'           => $id,
			'name'         => $name,
			'installed'    => $from,
			'new_version'  => $to,
			'jump'         => self::jump( $type, $from, $to ),
			'active'       => $active,
			'requires_wp'  => $requires,
			'requires_php' => $requires_php,
			'tested_up_to' => $tested,
			'untested'     => '' !== $tested && version_compare( self::branch( $tested ), self::branch( $wp ), '<' ),
			'status'       => null === $blocked ? 'ready' : 'blocked',
			'reason'       => $blocked,
		);
	}

	/**
	 * Why this site cannot take the update, or null.
	 *
	 * @param string $name         What is updated.
	 * @param string $requires     Required WordPress, or ''.
	 * @param string $requires_php Required PHP, or ''.
	 * @param string $wp           Installed WordPress.
	 * @param string $php          Running PHP.
	 * @return string|null
	 */
	private static function requirements( string $name, string $requires, string $requires_php, string $wp, string $php ): ?string {
		if ( '' !== $requires && version_compare( $wp, $requires, '<' ) ) {
			return sprintf( '%s needs WordPress %s; this site runs %s. Update WordPress first.', $name, $requires, $wp );
		}
		if ( '' !== $requires_php && version_compare( $php, $requires_php, '<' ) ) {
			return sprintf( '%s needs PHP %s; this site runs %s. Ask your host to upgrade PHP.', $name, $requires_php, $php );
		}
		return null;
	}

	/**
	 * Major or minor: core compares x.y, plugins and themes the first number.
	 *
	 * @param string $type plugin, theme or core.
	 * @param string $from Installed.
	 * @param string $to   New.
	 * @return string
	 */
	private static function jump( string $type, string $from, string $to ): string {
		if ( 'core' === $type ) {
			return self::branch( $from ) === self::branch( $to ) ? 'minor' : 'major';
		}
		return (int) $from === (int) $to ? 'minor' : 'major';
	}

	/**
	 * Sort key: ready before blocked, core last, inactive before active, minor before major, plugins before themes.
	 *
	 * @param array<string, mixed> $update Update.
	 * @return array<int, int|string>
	 */
	private static function order( array $update ): array {
		return array(
			'blocked' === $update['status'] ? 1 : 0,
			'core' === $update['type'] ? 1 : 0,
			$update['active'] ? 1 : 0,
			'major' === $update['jump'] ? 1 : 0,
			'theme' === $update['type'] ? 1 : 0,
			$update['name'],
		);
	}

	/**
	 * Backup status: ok, stale, unknown (plugin found, time unreadable) or none. Never blocks an update.
	 *
	 * @param array{plugin: string|null, last_backup: string|null} $backup Backup facts.
	 * @param int                                                  $now    Current time.
	 * @return array<string, mixed>
	 */
	private static function backup( array $backup, int $now ): array {
		$plugin = $backup['plugin'];
		$last   = $backup['last_backup'];
		if ( null === $plugin ) {
			$status  = 'none';
			$warning = 'No known backup plugin is active. Back up the site before updating; FlitAgent does not perform updates and cannot undo one.';
		} elseif ( null === $last ) {
			$status  = 'unknown';
			$warning = sprintf( 'FlitAgent cannot read when %s last backed up. Check that a backup from the last 24 hours exists before updating.', $plugin );
		} elseif ( $now - (int) strtotime( $last ) > self::BACKUP_MAX_AGE ) {
			$status  = 'stale';
			$warning = sprintf( 'The last %s backup is more than 24 hours old (%s). Run a backup before updating.', $plugin, $last );
		} else {
			$status  = 'ok';
			$warning = null;
		}
		return array(
			'plugin'      => $plugin,
			'last_backup' => $last,
			'status'      => $status,
			'warning'     => $warning,
		);
	}

	/**
	 * The x.y branch of a version.
	 *
	 * @param string $version Version.
	 * @return string
	 */
	private static function branch( string $version ): string {
		$parts = explode( '.', $version );
		return (int) $parts[0] . '.' . ( isset( $parts[1] ) ? (int) $parts[1] : 0 );
	}
}
