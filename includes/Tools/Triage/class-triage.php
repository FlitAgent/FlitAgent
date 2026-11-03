<?php
/**
 * Health triage rules.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Triage;

use FlitAgent\Tools\SiteText;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a snapshot of the site (see SiteSnapshot) into findings: what is wrong, how serious it is, and what to do about
 * it in wp-admin. Pure: no WordPress calls, so every rule is testable on plain arrays.
 */
final class Triage {

	/**
	 * Severities, most serious first.
	 */
	const SEVERITIES = array( 'critical', 'warning', 'info' );

	/**
	 * Seconds a cron event may be late before it is reported.
	 */
	const CRON_GRACE = 3600;

	/**
	 * Seconds maintenance mode may last before it counts as stuck. WordPress itself ignores a .maintenance file older
	 * than 10 minutes, but the file is still a sign that an update failed.
	 */
	const MAINTENANCE_GRACE = 600;

	/**
	 * Findings for a snapshot.
	 *
	 * @param array<string, mixed> $snapshot Snapshot from SiteSnapshot::read().
	 * @return array{summary: array<string, int>, findings: array<int, array<string, mixed>>}
	 */
	public static function run( array $snapshot ): array {
		$findings = array_merge(
			self::plugin_updates( $snapshot['plugins'] ),
			self::theme_updates( $snapshot['themes'] ),
			self::core_update( $snapshot['core_update'] ),
			self::maintenance( $snapshot['maintenance_since'], (int) $snapshot['now'] ),
			self::debug_display( (bool) $snapshot['debug'], (bool) $snapshot['debug_display'] ),
			self::cron( $snapshot['cron'], (bool) $snapshot['disable_wp_cron'], (int) $snapshot['now'] ),
			self::site_health( $snapshot['site_health'] ),
			self::inactive_plugins( $snapshot['plugins'] ),
			self::inactive_themes( $snapshot['themes'], (string) $snapshot['default_theme'] )
		);

		// Order by severity, keeping each severity's findings in the order above (usort is not stable before PHP 8).
		$sorted = array();
		foreach ( self::SEVERITIES as $severity ) {
			foreach ( $findings as $finding ) {
				if ( $severity === $finding['severity'] ) {
					$sorted[] = $finding;
				}
			}
		}

		$summary = array_fill_keys( self::SEVERITIES, 0 );
		foreach ( $sorted as $finding ) {
			++$summary[ $finding['severity'] ];
		}

		return array(
			'summary'  => $summary,
			'findings' => $sorted,
		);
	}

	/**
	 * One finding per plugin with a cached update: critical when active.
	 *
	 * @param array<int, array<string, mixed>> $plugins Plugins.
	 * @return array<int, array<string, mixed>>
	 */
	private static function plugin_updates( array $plugins ): array {
		$out = array();
		foreach ( $plugins as $plugin ) {
			if ( null === $plugin['update'] ) {
				continue;
			}
			$own    = 0 === strpos( $plugin['file'], 'flitagent/' );
			$detail = SiteText::message( '%s %s is installed; %s is available.', $plugin['name'], $plugin['version'], $plugin['update'] );
			if ( $own ) {
				$detail .= ' FlitAgent does not update itself: update it in wp-admin (Plugins).';
			} elseif ( ! $plugin['active'] ) {
				$detail .= ' The plugin is inactive, but its code can still be reached. Update or delete it in wp-admin (Plugins).';
			} else {
				$detail .= ' Update it in wp-admin (Plugins).';
			}
			$out[] = self::finding(
				'plugin_update:' . $plugin['file'],
				$plugin['active'] ? 'critical' : 'warning',
				SiteText::message( 'Update available for %s', $plugin['name'] ),
				$detail
			);
		}
		return $out;
	}

	/**
	 * One finding per theme with a cached update: critical for the active theme and its parent.
	 *
	 * @param array<int, array<string, mixed>> $themes Themes.
	 * @return array<int, array<string, mixed>>
	 */
	private static function theme_updates( array $themes ): array {
		$out = array();
		foreach ( $themes as $theme ) {
			if ( null === $theme['update'] ) {
				continue;
			}
			$in_use = $theme['active'] || $theme['parent'];
			$out[]  = self::finding(
				'theme_update:' . $theme['stylesheet'],
				$in_use ? 'critical' : 'warning',
				SiteText::message( 'Update available for the %s theme', $theme['name'] ),
				SiteText::message( '%s %s is installed; %s is available.%s', $theme['name'], $theme['version'], $theme['update'], $in_use ? ' The site uses this theme.' : '' ) . ' Update it in wp-admin (Appearance, Themes).'
			);
		}
		return $out;
	}

	/**
	 * The offered core update.
	 *
	 * @param string|null $version Offered version.
	 * @return array<int, array<string, mixed>>
	 */
	private static function core_update( ?string $version ): array {
		if ( null === $version ) {
			return array();
		}
		return array(
			self::finding(
				'core_update',
				'warning',
				'WordPress update available',
				SiteText::message( 'WordPress %s is available. Back up the site, then update it in wp-admin (Dashboard, Updates).', $version )
			),
		);
	}

	/**
	 * Maintenance mode left on by a failed or interrupted update.
	 *
	 * @param int|null $since When the .maintenance file was written (Unix time), null when there is none.
	 * @param int      $now   Current time.
	 * @return array<int, array<string, mixed>>
	 */
	private static function maintenance( ?int $since, int $now ): array {
		if ( null === $since || $now - $since <= self::MAINTENANCE_GRACE ) {
			return array();
		}
		return array(
			self::finding(
				'maintenance_stuck',
				'critical',
				'Maintenance mode is stuck',
				sprintf( 'The .maintenance file in the WordPress folder is %d minutes old, so an update probably failed part-way. Check that plugins, themes and WordPress are complete, then delete the .maintenance file (by FTP or your host\'s file manager).', (int) floor( ( $now - $since ) / 60 ) )
			),
		);
	}

	/**
	 * PHP errors shown to visitors.
	 *
	 * @param bool $debug   WP_DEBUG.
	 * @param bool $display WP_DEBUG_DISPLAY (WordPress defaults it to true).
	 * @return array<int, array<string, mixed>>
	 */
	private static function debug_display( bool $debug, bool $display ): array {
		if ( ! $debug || ! $display ) {
			return array();
		}
		return array(
			self::finding(
				'debug_display',
				'warning',
				'PHP errors are shown to visitors',
				'WP_DEBUG is on and WP_DEBUG_DISPLAY is not off, so errors and file paths can appear on public pages. In wp-config.php set WP_DEBUG_DISPLAY to false (and WP_DEBUG_LOG to true to keep a log), or turn WP_DEBUG off.'
			),
		);
	}

	/**
	 * Cron events more than an hour late, and DISABLE_WP_CRON without a working replacement.
	 *
	 * @param array<int, array{hook: string, timestamp: int}> $events   Scheduled events.
	 * @param bool                                            $disabled DISABLE_WP_CRON.
	 * @param int                                             $now      Current time.
	 * @return array<int, array<string, mixed>>
	 */
	private static function cron( array $events, bool $disabled, int $now ): array {
		$out = array();
		foreach ( $events as $event ) {
			$late = $now - (int) $event['timestamp'];
			if ( $late <= self::CRON_GRACE ) {
				continue;
			}
			$out[] = self::overdue( (string) $event['hook'], (int) $event['timestamp'], $late );
		}

		if ( $disabled && array() !== $out ) {
			$out[] = self::finding(
				'cron_disabled',
				'warning',
				'WP-Cron is disabled and tasks are not running',
				'DISABLE_WP_CRON is set, so WordPress relies on a server cron job, and scheduled tasks are overdue. Ask your host to run wp-cron.php (or `wp cron event run --due-now`) every few minutes.'
			);
		}
		return $out;
	}

	/**
	 * An overdue cron event.
	 *
	 * @param string $hook      Hook.
	 * @param int    $timestamp When it was due.
	 * @param int    $late      Seconds late.
	 * @return array<string, mixed>
	 */
	private static function overdue( string $hook, int $timestamp, int $late ): array {
		return self::finding(
			'cron_overdue:' . $hook . ':' . $timestamp,
			'warning',
			SiteText::message( 'Scheduled task %s is overdue', $hook ),
			sprintf( 'It was due %d hours ago. WordPress runs it on its next cron run; if it keeps coming back, ask your host or a developer to check WP-Cron.', (int) floor( $late / 3600 ) )
		);
	}

	/**
	 * The last Site Health result WordPress saved.
	 *
	 * @param array<string, int>|null $counts Saved counts, null when there is none.
	 * @return array<int, array<string, mixed>>
	 */
	private static function site_health( ?array $counts ): array {
		if ( null === $counts ) {
			return array(
				self::finding(
					'site_health_missing',
					'info',
					'No Site Health result saved yet',
					'Open Tools → Site Health in wp-admin once so WordPress runs its own checks; triage then includes their result.'
				),
			);
		}
		if ( (int) $counts['critical'] < 1 ) {
			return array();
		}
		return array(
			self::finding(
				'site_health_critical',
				'warning',
				'Site Health reports critical issues',
				sprintf( 'The last Site Health check found %s. See Tools → Site Health in wp-admin for details.', self::count( (int) $counts['critical'], 'critical issue', 'critical issues' ) )
			),
		);
	}

	/**
	 * Installed plugins that are not active.
	 *
	 * @param array<int, array<string, mixed>> $plugins Plugins.
	 * @return array<int, array<string, mixed>>
	 */
	private static function inactive_plugins( array $plugins ): array {
		$out = array();
		foreach ( $plugins as $plugin ) {
			if ( $plugin['active'] ) {
				continue;
			}
			$out[] = self::finding(
				'inactive_plugin:' . $plugin['file'],
				'info',
				SiteText::message( '%s is installed but inactive', $plugin['name'] ),
				'Inactive plugins still have code on the server. Delete it in wp-admin (Plugins) if the site no longer needs it.'
			);
		}
		return $out;
	}

	/**
	 * Installed themes the site does not use: not active, not the active theme's parent, not the default theme.
	 *
	 * @param array<int, array<string, mixed>> $themes        Themes.
	 * @param string                           $default_theme WP_DEFAULT_THEME, kept as a fallback.
	 * @return array<int, array<string, mixed>>
	 */
	private static function inactive_themes( array $themes, string $default_theme ): array {
		$out = array();
		foreach ( $themes as $theme ) {
			if ( $theme['active'] || $theme['parent'] || $default_theme === $theme['stylesheet'] ) {
				continue;
			}
			$out[] = self::finding(
				'inactive_theme:' . $theme['stylesheet'],
				'info',
				SiteText::message( 'The %s theme is installed but unused', $theme['name'] ),
				'Unused themes still have code on the server. Delete it in wp-admin (Appearance, Themes) if the site no longer needs it; the default theme is kept as a fallback.'
			);
		}
		return $out;
	}

	/**
	 * A finding.
	 *
	 * @param string $id       Stable id.
	 * @param string $severity critical, warning or info.
	 * @param string $title    One line.
	 * @param string $detail   What and what to do in wp-admin.
	 * @return array<string, mixed>
	 */
	private static function finding( string $id, string $severity, string $title, string $detail ): array {
		return array(
			'id'       => $id,
			'severity' => $severity,
			'title'    => $title,
			'detail'   => $detail,
		);
	}

	/**
	 * A count with its noun in the singular or the plural: "1 critical issue", "2 critical issues". Findings are English
	 * by design (the model reads them), so this is not translated.
	 *
	 * @param int    $n    Count.
	 * @param string $one  Singular noun.
	 * @param string $many Plural noun.
	 * @return string
	 */
	private static function count( int $n, string $one, string $many ): string {
		return $n . ' ' . ( 1 === $n ? $one : $many );
	}
}
