<?php
/**
 * Core plugin upgrader, run quietly.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Plugins;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Ops\ErrorText;

defined( 'ABSPATH' ) || exit;

/**
 * Installs, replaces and deletes plugin files through core: `Plugin_Upgrader` with the non-interactive
 * `WP_Ajax_Upgrader_Skin`, and `delete_plugins()`. Output printed by core or by plugin code is discarded, errors become
 * tool errors without file paths or markup, a `.maintenance` file the run created is removed, and core's plugin caches
 * are cleared afterwards.
 *
 * Updates (and update rollbacks) run the steps of core's `bulk_upgrade()` for one plugin with the verified package (see
 * update_steps()). Unlike `upgrade()`, they keep an active plugin active and turn maintenance mode on while that
 * plugin's files are replaced; from WordPress 6.3 core restores the old files when the copy fails.
 */
final class Upgrader {

	/**
	 * Returns a Plugin_Upgrader (or a stand-in) with a quiet skin.
	 *
	 * @var callable
	 */
	private $factory;

	/**
	 * Path of the maintenance file.
	 *
	 * @var string
	 */
	private $maintenance;

	/**
	 * Constructor.
	 *
	 * @param callable|null $factory     Returns the upgrader; core's Plugin_Upgrader with WP_Ajax_Upgrader_Skin by default.
	 * @param string        $maintenance Maintenance file; ABSPATH . '.maintenance' by default.
	 */
	public function __construct( ?callable $factory = null, string $maintenance = '' ) {
		$this->factory     = null === $factory ? array( self::class, 'core_upgrader' ) : $factory;
		$this->maintenance = '' === $maintenance ? ABSPATH . '.maintenance' : $maintenance;
	}

	/**
	 * Core's upgrader with the non-interactive skin.
	 *
	 * @return object
	 */
	public static function core_upgrader() {
		Site::load_admin();
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		return new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
	}

	/**
	 * Installs a package that is not installed yet.
	 *
	 * @param string $package Verified package URL.
	 * @return string Installed plugin file.
	 * @throws ToolError When core fails.
	 */
	public function install( string $package ): string {
		$upgrader = call_user_func( $this->factory );
		$result   = $this->run(
			static function () use ( $upgrader, $package ) {
				return $upgrader->install( $package, array( 'overwrite_package' => false ) );
			},
			'Installing the plugin'
		);
		$this->check( $result, $upgrader, 'Installing the plugin' );
		$plugin = $upgrader->plugin_info();
		if ( ! is_string( $plugin ) || '' === $plugin ) {
			throw new ToolError( 'WordPress unpacked the package but found no plugin in it.' );
		}
		return $plugin;
	}

	/**
	 * Replaces an installed plugin's files with a package (an update, or a rollback to an earlier version).
	 *
	 * @param string $plugin  Plugin file.
	 * @param string $package Verified package URL.
	 * @return array<string, string> Warnings (code => message): a listener of upgrader_process_complete that failed after
	 *                               the files were replaced.
	 * @throws ToolError When core fails.
	 */
	public function replace( string $plugin, string $package ): array {
		$upgrader = call_user_func( $this->factory );
		$warnings = array();
		$result   = $this->run(
			static function () use ( $upgrader, $plugin, $package, &$warnings ) {
				return self::update_steps( $upgrader, $plugin, $package, $warnings );
			},
			'Updating the plugin'
		);
		$this->check( $result, $upgrader, 'Updating the plugin' );
		return $warnings;
	}

	/**
	 * Core's `Plugin_Upgrader::bulk_upgrade()` for one plugin, through the upgrader's public methods, with the verified
	 * package given to `run()` directly: bulk_upgrade() reads the package from the update-plugins transient, which a
	 * WordPress.org plugin may not filter. Same steps: filesystem connection, maintenance mode while an active plugin's
	 * files are replaced, `delete_old_plugin` and `check_package`, `temp_backup` (from WordPress 6.3, when the copy fails,
	 * core restores the old files at the end of the request), and `upgrader_process_complete` with `action: update`. The WordPress and PHP checks bulk_upgrade()
	 * makes from the directory data are made in the tool's assessment.
	 *
	 * @param object $upgrader Plugin_Upgrader (or a stand-in).
	 * @param string $plugin   Plugin file.
	 * @param string $package  Verified package URL.
	 * @param array  $warnings Warnings, filled in (code => message).
	 * @return mixed run()'s result, false when the filesystem is unavailable.
	 */
	private static function update_steps( $upgrader, string $plugin, string $package, array &$warnings ) {
		$upgrader->init();
		$upgrader->bulk = true;
		$upgrader->upgrade_strings();
		add_filter( 'upgrader_clear_destination', array( $upgrader, 'delete_old_plugin' ), 10, 4 );
		$upgrader->skin->header();
		try {
			if ( ! $upgrader->fs_connect( array( WP_CONTENT_DIR, WP_PLUGIN_DIR ) ) ) {
				return false;
			}
			$upgrader->skin->bulk_header();
			// As bulk_upgrade() does, for upgrader_process_complete listeners (core's skins allow these properties).
			$upgrader->skin->plugin_info   = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, true );
			$upgrader->skin->plugin_active = is_plugin_active( $plugin );
			$result                        = self::copy( $upgrader, $plugin, $package );
			wp_clean_plugins_cache( true );
			$extra   = array(
				'action'  => 'update',
				'type'    => 'plugin',
				'bulk'    => true,
				'plugins' => array( $plugin ),
			);
			$failure = self::complete( $upgrader, $extra );
			if ( '' !== $failure ) {
				$warnings['listener_failed'] = sprintf( "WordPress replaced the plugin's files, but a plugin listening to upgrader_process_complete failed afterwards: %s Listeners after it (other plugins' post-update tasks, WordPress's update checks) may not have run.", $failure );
			}
			$upgrader->skin->bulk_footer();
			self::forget_failure_email( $plugin, $result );
			return $result;
		} finally {
			$upgrader->skin->footer();
			remove_filter( 'upgrader_clear_destination', array( $upgrader, 'delete_old_plugin' ) );
		}//end try
	}

	/**
	 * Fires upgrader_process_complete. WordPress's dispatcher has no cleanup when a listener throws: every hook the
	 * exception passed through stays on $wp_current_filter, and its WP_Hook stays marked as running with its nesting
	 * level raised (a later apply_filters() of it then passes values wrongly). On a failure the stack is cut back to
	 * where it was and each of those hooks is rebuilt from its callbacks with WordPress's own
	 * WP_Hook::build_preinitialized_hooks(). Callbacks a listener removed for its own run and meant to add back stay
	 * removed for the rest of the request; the warning says listeners may not have run.
	 *
	 * @param object               $upgrader Upgrader.
	 * @param array<string, mixed> $extra    Hook data.
	 * @return string The listener's error, or '' when all of them ran.
	 */
	private static function complete( $upgrader, array $extra ): string {
		global $wp_filter, $wp_current_filter;
		$depth = is_array( $wp_current_filter ) ? count( $wp_current_filter ) : 0;
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook, fired as bulk_upgrade() fires it.
			do_action( 'upgrader_process_complete', $upgrader, $extra );
			return '';
		} catch ( \Throwable $e ) {
			$entered = is_array( $wp_current_filter ) ? array_unique( array_slice( $wp_current_filter, $depth ) ) : array();
			if ( is_array( $wp_current_filter ) ) {
				array_splice( $wp_current_filter, $depth );
			}
			// A hook still running in an outer frame keeps its object (that run iterates it); `all` is repaired too.
			$outer = is_array( $wp_current_filter ) ? $wp_current_filter : array();
			foreach ( array_unique( array_merge( array( 'upgrader_process_complete', 'all' ), $entered ) ) as $hook ) {
				if ( in_array( $hook, $outer, true ) ) {
					continue;
				}
				if ( class_exists( 'WP_Hook' ) && isset( $wp_filter[ $hook ] ) && $wp_filter[ $hook ] instanceof \WP_Hook ) {
					$rebuilt = \WP_Hook::build_preinitialized_hooks( array( $hook => $wp_filter[ $hook ]->callbacks ) );
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the same callbacks, without the state the failure left.
					$wp_filter[ $hook ] = $rebuilt[ $hook ];
				}
			}
			return ErrorText::of( $e );
		}//end try
	}

	/**
	 * As bulk_upgrade() does: after a successful update, forgets the plugin's last auto-update failure email, so a later
	 * auto-update failure is mailed again.
	 *
	 * @param string $plugin Plugin file.
	 * @param mixed  $result run()'s result.
	 */
	private static function forget_failure_email( string $plugin, $result ): void {
		if ( ! $result || is_wp_error( $result ) ) {
			return;
		}
		$emails = get_option( 'auto_plugin_theme_update_emails', array() );
		if ( is_array( $emails ) && isset( $emails[ $plugin ] ) ) {
			unset( $emails[ $plugin ] );
			update_option( 'auto_plugin_theme_update_emails', $emails );
		}
	}

	/**
	 * The copy of update_steps(): the package into the plugin's folder, with core's temporary backup, in maintenance
	 * mode while the plugin is active.
	 *
	 * @param object $upgrader Plugin_Upgrader (or a stand-in).
	 * @param string $plugin   Plugin file.
	 * @param string $package  Verified package URL.
	 * @return mixed run()'s result.
	 */
	private static function copy( $upgrader, string $plugin, string $package ) {
		$maintenance = is_multisite() || is_plugin_active( $plugin );
		if ( $maintenance ) {
			$upgrader->maintenance_mode( true );
		}
		$upgrader->update_count   = 1;
		$upgrader->update_current = 1;
		add_filter( 'upgrader_source_selection', array( $upgrader, 'check_package' ) );
		try {
			return $upgrader->run(
				array(
					'package'           => $package,
					'destination'       => WP_PLUGIN_DIR,
					'clear_destination' => true,
					'clear_working'     => true,
					'is_multi'          => true,
					'hook_extra'        => array(
						'plugin'      => $plugin,
						'temp_backup' => array(
							'slug' => dirname( $plugin ),
							'src'  => WP_PLUGIN_DIR,
							'dir'  => 'plugins',
						),
					),
				)
			);
		} finally {
			remove_filter( 'upgrader_source_selection', array( $upgrader, 'check_package' ) );
			if ( $maintenance ) {
				$upgrader->maintenance_mode( false );
			}
		}//end try
	}

	/**
	 * Deletes an inactive plugin with core's delete_plugins(), which runs its uninstall routine.
	 *
	 * @param string $plugin Plugin file.
	 * @throws ToolError When core fails.
	 */
	public function delete( string $plugin ): void {
		Site::load_admin();
		$result = $this->run(
			static function () use ( $plugin ) {
				return delete_plugins( array( $plugin ) );
			},
			'Deleting the plugin'
		);
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'Deleting the plugin failed: ' . esc_textarea( ErrorText::clean( $result->get_error_message() ) ) );
		}
		if ( true !== $result ) {
			throw new ToolError( 'Deleting the plugin failed: WordPress could not access the filesystem.' );
		}
	}

	/**
	 * Runs a core call: discards printed output, turns exceptions into tool errors, removes a maintenance file the run
	 * left behind and clears core's plugin caches.
	 *
	 * @param callable $call  Core call.
	 * @param string   $doing What it does, for messages.
	 * @return mixed
	 * @throws ToolError When the call throws.
	 */
	private function run( callable $call, string $doing ) {
		$existed = file_exists( $this->maintenance );
		$level   = ob_get_level();
		ob_start();
		try {
			return $call();
		} catch ( \Throwable $e ) {
			throw new ToolError( sprintf( '%s failed: %s', esc_textarea( $doing ), esc_textarea( ErrorText::of( $e ) ) ) );
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			if ( ! $existed && file_exists( $this->maintenance ) ) {
				wp_delete_file( $this->maintenance );
			}
			wp_clean_plugins_cache();
		}
	}

	/**
	 * Throws a tool error for a failed upgrader result, with the skin's messages.
	 *
	 * @param mixed  $result   Upgrader result.
	 * @param object $upgrader Upgrader.
	 * @param string $doing    What it did, for messages.
	 * @throws ToolError When the result is a failure.
	 */
	private function check( $result, $upgrader, string $doing ): void {
		if ( is_wp_error( $result ) ) {
			throw new ToolError( sprintf( '%s failed: %s', esc_textarea( $doing ), esc_textarea( ErrorText::clean( $result->get_error_message() ) ) ) );
		}
		if ( false === $result || null === $result ) {
			$messages = '';
			if ( isset( $upgrader->skin ) && is_object( $upgrader->skin ) && method_exists( $upgrader->skin, 'get_error_messages' ) ) {
				$messages = trim( (string) $upgrader->skin->get_error_messages() );
			}
			throw new ToolError( sprintf( '%s failed: %s', esc_textarea( $doing ), '' === $messages ? 'WordPress gave no reason.' : esc_textarea( ErrorText::clean( $messages ) ) ) );
		}
	}
}
