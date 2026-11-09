<?php
/**
 * The list_roles tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\System;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Lists roles and their capabilities (WP-CLI `role list` / `cap list`).
 */
final class Roles implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_roles';
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		return Definition::read_only(
			$this->name(),
			'List roles with FlitAgent',
			'lists user roles with slug, display name and the capabilities each grants (sorted). With role, returns one role, like WP-CLI cap list.',
			array(
				'role' => array(
					'type'        => 'string',
					'description' => 'Role slug, e.g. "editor".',
				),
			)
		);
	}

	/**
	 * Lists the roles.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the role does not exist.
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array( 'role' ), $this->name() );
		$only = Arguments::string( $arguments, 'role', false );

		$all = wp_roles()->roles;
		if ( null !== $only ) {
			if ( ! isset( $all[ $only ] ) ) {
				throw new ToolError( sprintf( 'Role "%s" not found. Known roles: %s.', esc_textarea( $only ), esc_textarea( implode( ', ', array_keys( $all ) ) ) ) );
			}
			$all = array( $only => $all[ $only ] );
		}

		$roles = array();
		foreach ( $all as $slug => $role ) {
			$capabilities = array_keys( array_filter( (array) ( $role['capabilities'] ?? array() ) ) );
			sort( $capabilities );
			$roles[] = array(
				'slug'         => (string) $slug,
				'name'         => (string) ( $role['name'] ?? $slug ),
				'capabilities' => array_map( 'strval', $capabilities ),
			);
		}

		return array( 'roles' => $roles );
	}
}
