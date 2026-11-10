<?php
/**
 * Quiet wrapper around core's theme upgrader and delete_theme().
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Themes;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\AdminCode;
use FlitAgent\Writes\Buffers;

defined( 'ABSPATH' ) || exit;

/**
 * Runs core's Theme_Upgrader with the non-interactive WP_Ajax_Upgrader_Skin (what wp-admin's theme AJAX handlers use)
 * and core's delete_theme(). Everything they print is buffered and discarded; upgrader and skin errors become a
 * ToolError with a safe message. Theme caches are always cleaned, and a `.maintenance` file the run created is always
 * removed, also on failure.
 */
class Upgrader {

	/**
	 * Builds the upgrader: `function (): object` with install(), fs_connect(), maintenance_mode() and a `skin`.
	 *
	 * @var callable
	 */
	private $factory;

	/**
	 * Constructor.
	 *
	 * @param callable|null $factory Builds the upgrader, for tests.
	 */
	public function __construct( ?callable $factory = null ) {
		$this->factory = $factory ? $factory : array( self::class, 'core_upgrader' );
	}

	/**
	 * Core's Theme_Upgrader with a quiet skin.
	 *
	 * @return object
	 */
	public static function core_upgrader() {
		Site::load_admin();
		AdminCode::load();
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		return new \Theme_Upgrader( new \WP_Ajax_Upgrader_Skin() );
	}

	/**
	 * Installs a package into the themes folder.
	 *
	 * @param string $package     WordPress.org package URL (already checked).
	 * @param bool   $overwrite   Replace the installed copy (update, rollback).
	 * @param bool   $maintenance Put the site in maintenance mode during the swap (the theme is in use).
	 * @throws ToolError When the upgrader fails.
	 */
	public function install( string $package, bool $overwrite, bool $maintenance ): void {
		$marker   = ABSPATH . '.maintenance';
		$existed  = file_exists( $marker );
		$upgrader = call_user_func( $this->factory );
		$error    = '';
		$enabled  = false;
		$level    = ob_get_level();
		ob_start();
		try {
			if ( $maintenance ) {
				$connected = $upgrader->fs_connect( array( get_theme_root() ) );
				if ( true !== $connected ) {
					throw new ToolError( 'WordPress could not open the themes folder for writing; nothing was changed.' );
				}
				$upgrader->maintenance_mode( true );
				$enabled = true;
			}
			$result = $upgrader->install(
				$package,
				array(
					'overwrite_package'  => $overwrite,
					'clear_update_cache' => true,
				)
			);
			$error  = self::error_of( $result, $upgrader );
		} finally {
			Buffers::discard( $level );
			if ( ( $enabled || file_exists( $marker ) ) && ! $existed ) {
				ob_start();
				$upgrader->maintenance_mode( false );
				Buffers::discard( $level );
			}
			wp_clean_themes_cache();
		}//end try
		if ( '' !== $error ) {
			throw new ToolError( 'WordPress could not install the theme package: ' . esc_textarea( $error ) );
		}
	}

	/**
	 * Deletes an installed theme with core's delete_theme().
	 *
	 * @param string $slug Directory name.
	 * @throws ToolError When the theme could not be deleted.
	 */
	public function delete( string $slug ): void {
		Site::load_admin();
		AdminCode::load();
		$level = ob_get_level();
		ob_start();
		try {
			$result = delete_theme( $slug );
		} finally {
			Buffers::discard( $level );
			wp_clean_themes_cache();
		}
		if ( is_wp_error( $result ) ) {
			throw new ToolError( 'WordPress could not delete the theme: ' . esc_textarea( Site::clean( (string) $result->get_error_message() ) ) );
		}
		if ( true !== $result ) {
			throw new ToolError( 'WordPress could not delete the theme: the filesystem is not writable.' );
		}
	}

	/**
	 * Error message of an upgrader run, like wp_ajax_install_theme() reads it, or ''.
	 *
	 * @param mixed  $result   install() result.
	 * @param object $upgrader Upgrader.
	 * @return string
	 */
	private static function error_of( $result, $upgrader ): string {
		if ( is_wp_error( $result ) ) {
			return Site::clean( (string) $result->get_error_message() );
		}
		$skin     = isset( $upgrader->skin ) ? $upgrader->skin : null;
		$messages = is_object( $skin ) && method_exists( $skin, 'get_error_messages' ) ? trim( (string) $skin->get_error_messages() ) : '';
		if ( '' !== $messages ) {
			return Site::clean( $messages );
		}
		return true === $result ? '' : 'the filesystem is not writable or the package could not be unpacked.';
	}
}
