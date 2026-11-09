<?php
/**
 * Health triage rules.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Triage;

use FlitAgent\Tools\SiteText;
use FlitAgent\Writes\Summary;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a snapshot of the site (see SiteSnapshot) into findings: what is wrong, how serious it is, and which FlitAgent
 * tool fixes it. Pure: no WordPress calls, so every rule is testable on plain arrays.
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
	 * Cron hooks run_cron_event refuses: automatic updates.
	 */
	const REFUSED_CRON_HOOKS = array( 'wp_maybe_auto_update' );

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
			self::inactive_themes( $snapshot['themes'], (string) $snapshot['default_theme'] ),
			self::woocommerce( $snapshot['woo'] ?? null )
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
			$single = false === strpos( $plugin['file'], '/' );
			$detail = SiteText::message( '%s %s is installed; %s is available.', $plugin['name'], $plugin['version'], $plugin['update'] );
			if ( $own ) {
				$detail .= ' FlitAgent does not update itself: update it in wp-admin (Plugins).';
			} elseif ( $single ) {
				$detail .= ' It is a single file in the plugins folder, so FlitAgent cannot update it: update it in wp-admin (Plugins).';
			} elseif ( ! $plugin['active'] ) {
				$detail .= ' The plugin is inactive, but its code can still be reached; update or delete it.';
			}
			$out[] = self::finding(
				'plugin_update:' . $plugin['file'],
				$plugin['active'] ? 'critical' : 'warning',
				SiteText::message( 'Update available for %s', $plugin['name'] ),
				$detail,
				$own || $single ? null : self::fix( 'update_plugin', array( 'plugin' => $plugin['file'] ) )
			);
		}//end foreach
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
				SiteText::message( '%s %s is installed; %s is available.%s', $theme['name'], $theme['version'], $theme['update'], $in_use ? ' The site uses this theme.' : '' ),
				self::fix( 'update_theme', array( 'slug' => $theme['stylesheet'] ) )
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
				SiteText::message( 'WordPress %s is available. Back up the site before updating; a core update cannot be rolled back.', $version ),
				self::fix( 'update_core', array( 'version' => $version ) )
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
				sprintf( 'The .maintenance file in the WordPress folder is %d minutes old, so an update probably failed part-way. Check that plugins, themes and WordPress are complete, then delete the .maintenance file (by FTP or your host\'s file manager).', (int) floor( ( $now - $since ) / 60 ) ),
				null
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
				'WP_DEBUG is on and WP_DEBUG_DISPLAY is not off, so errors and file paths can appear on public pages. In wp-config.php set WP_DEBUG_DISPLAY to false (and WP_DEBUG_LOG to true to keep a log), or turn WP_DEBUG off.',
				null
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
				'DISABLE_WP_CRON is set, so WordPress relies on a server cron job, and scheduled tasks are overdue. Ask your host to run wp-cron.php (or `wp cron event run --due-now`) every few minutes.',
				null
			);
		}
		return $out;
	}

	/**
	 * An overdue cron event, fixed by run_cron_event unless that tool refuses the hook.
	 *
	 * @param string $hook      Hook.
	 * @param int    $timestamp When it was due.
	 * @param int    $late      Seconds late.
	 * @return array<string, mixed>
	 */
	private static function overdue( string $hook, int $timestamp, int $late ): array {
		$refused = in_array( $hook, self::REFUSED_CRON_HOOKS, true ) || 0 === strpos( $hook, 'flitagent_' );
		$fix     = array(
			'hook'     => $hook,
			'next_run' => gmdate( 'c', $timestamp ),
		);

		return self::finding(
			'cron_overdue:' . $hook . ':' . $timestamp,
			'warning',
			SiteText::message( 'Scheduled task %s is overdue', $hook ),
			sprintf( 'It was due %d hours ago.%s', (int) floor( $late / 3600 ), $refused ? ' FlitAgent does not run this task; WordPress runs it on its next cron run.' : '' ),
			$refused ? null : self::fix( 'run_cron_event', $fix )
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
					'Open Tools → Site Health in wp-admin once so WordPress runs its own checks; triage then includes their result.',
					null
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
				sprintf( 'The last Site Health check found %s. See Tools → Site Health in wp-admin for details.', Summary::count( (int) $counts['critical'], 'critical issue', 'critical issues' ) ),
				null
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
				'Inactive plugins still have code on the server. Delete it if the site no longer needs it.',
				self::fix( 'delete_plugin', array( 'plugin' => $plugin['file'] ) )
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
				'Unused themes still have code on the server. Delete it if the site no longer needs it; the default theme is kept as a fallback.',
				self::fix( 'delete_theme', array( 'slug' => $theme['stylesheet'] ) )
			);
		}
		return $out;
	}

	/**
	 * WooCommerce findings: a pending database update, failed and on-hold orders in the last 7 days, outdated template
	 * overrides and products out of stock.
	 *
	 * @param array<string, mixed>|null $woo WooCommerce facts, or null when WooCommerce is not active.
	 * @return array<int, array<string, mixed>>
	 */
	private static function woocommerce( ?array $woo ): array {
		if ( null === $woo ) {
			return array();
		}
		$out = array();
		if ( $woo['db_update_pending'] ) {
			$out[] = self::finding(
				'woo_db_update',
				'critical',
				'WooCommerce needs a database update',
				'WooCommerce was updated but its database was not. Until the update runs, parts of the store can misbehave. Run it from the notice in wp-admin after taking a backup.',
				null
			);
		}
		if ( $woo['failed_7d'] > 0 ) {
			$out[] = self::finding(
				'woo_failed_orders',
				'warning',
				sprintf( '%d failed orders in the last 7 days', $woo['failed_7d'] ),
				'Failed orders usually mean declined payments or a payment gateway problem. Check the orders and the gateway settings.',
				self::fix( 'list_orders', array( 'status' => 'failed' ) )
			);
		}
		if ( $woo['on_hold_7d'] > 0 ) {
			$out[] = self::finding(
				'woo_on_hold_orders',
				'warning',
				sprintf( '%d orders on hold from the last 7 days', $woo['on_hold_7d'] ),
				'On-hold orders wait for someone to confirm payment (for example a bank transfer) before they are processed.',
				self::fix( 'list_orders', array( 'status' => 'on-hold' ) )
			);
		}
		if ( array() !== $woo['outdated_templates'] ) {
			$files = array();
			foreach ( $woo['outdated_templates'] as $template ) {
				$files[] = SiteText::message( '%s (%s, WooCommerce has %s)', $template['file'], $template['version'], $template['core_version'] );
			}
			$out[] = self::finding(
				'woo_outdated_templates',
				'warning',
				sprintf( '%d outdated WooCommerce template overrides in the theme', count( $files ) ),
				'The theme overrides WooCommerce templates that have changed since; the store can show old markup or break. Ask the theme developer to update: ' . implode( '; ', $files ) . '.',
				null
			);
		}
		if ( $woo['out_of_stock_total'] > 0 ) {
			$out[] = self::finding(
				'woo_out_of_stock',
				'info',
				sprintf( '%d products out of stock', $woo['out_of_stock_total'] ),
				sprintf( '%d published products are out of stock, including: %s.', $woo['out_of_stock_total'], implode( ', ', array_map( array( SiteText::class, 'plain' ), $woo['out_of_stock_names'] ) ) ),
				self::fix( 'list_products', array( 'stock_status' => 'outofstock' ) )
			);
		}
		return $out;
	}

	/**
	 * A finding.
	 *
	 * @param string                    $id       Stable id.
	 * @param string                    $severity critical, warning or info.
	 * @param string                    $title    One line.
	 * @param string                    $detail   What and what to do.
	 * @param array<string, mixed>|null $fix      Tool and arguments, or null for advice only.
	 * @return array<string, mixed>
	 */
	private static function finding( string $id, string $severity, string $title, string $detail, ?array $fix ): array {
		return array(
			'id'       => $id,
			'severity' => $severity,
			'title'    => $title,
			'detail'   => $detail,
			'fix'      => $fix,
		);
	}

	/**
	 * A fix: a FlitAgent tool and its arguments (the client adds idempotency_key for write tools).
	 *
	 * @param string               $tool      Tool name.
	 * @param array<string, mixed> $arguments Arguments.
	 * @return array<string, mixed>
	 */
	private static function fix( string $tool, array $arguments ): array {
		return array(
			'tool'      => $tool,
			'arguments' => $arguments,
		);
	}
}
