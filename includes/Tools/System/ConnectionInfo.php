<?php
/**
 * The get_connection_info tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\System;

use FlitAgent\Mcp\Tool;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the caller who they are connected as and what they can reach (the connected-user part of WPVibe site_info).
 */
final class ConnectionInfo implements Tool {

	/**
	 * Capabilities reported for the caller.
	 */
	const CAPABILITIES = array( 'manage_options', 'edit_posts', 'edit_others_posts', 'publish_posts', 'upload_files', 'edit_theme_options', 'activate_plugins', 'install_plugins', 'list_users', 'moderate_comments' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_connection_info';
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return Tool::ACCESS_PUBLIC;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		return Definition::read_only(
			$this->name(),
			'Get FlitAgent connection info',
			'tells you who this connection is logged in as (login, roles, key capabilities; user is null when not logged in), whether protected FlitAgent tools are available, and the active theme. WordPress and FlitAgent versions are included only for administrators. Call it first when a tool seems missing.'
		);
	}

	/**
	 * Reports the connection.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array(), $this->name() );

		$current = wp_get_current_user();
		$user    = null;
		if ( $current && ! empty( $current->ID ) ) {
			$capabilities = array();
			foreach ( self::CAPABILITIES as $capability ) {
				$capabilities[ $capability ] = current_user_can( $capability );
			}
			$user = array(
				'login'        => (string) $current->user_login,
				'roles'        => array_values( (array) $current->roles ),
				'capabilities' => $capabilities,
			);
		}

		$admin = current_user_can( 'manage_options' );
		$theme = wp_get_theme();

		return array(
			'user'              => $user,
			'protected_tools'   => $admin,
			'wordpress_version' => $admin ? (string) get_bloginfo( 'version' ) : null,
			'flitagent_version' => $admin && defined( 'FLITAGENT_VERSION' ) ? FLITAGENT_VERSION : null,
			'active_theme'      => array(
				'name'        => (string) $theme->get( 'Name' ),
				'slug'        => (string) get_stylesheet(),
				'version'     => (string) $theme->get( 'Version' ),
				'block_theme' => method_exists( $theme, 'is_block_theme' ) ? (bool) $theme->is_block_theme() : false,
			),
		);
	}
}
