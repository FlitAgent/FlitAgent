<?php
/**
 * Tool: get_user_meta.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a user's meta, with secret and personal values masked. Never returns user-table fields such as the password
 * hash or activation key.
 */
final class UserMeta implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_user_meta';
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
		return array(
			'name'         => $this->name(),
			'title'        => 'Read FlitAgent user meta',
			'description'  => 'From the FlitAgent WordPress plugin: reads a user\'s meta by user ID (roles and capabilities, preferences and plugin data), like WP-CLI `user meta list`. Pass key to read one meta key. Never returns the password hash, activation key or email; session tokens, application passwords and other secret-looking values are masked. Requires an administrator login.',
			'inputSchema'  => PostMeta::input_schema( 'user_id', 'User ID.' ),
			'outputSchema' => PostMeta::output_schema( 'user_id' ),
			'annotations'  => Annotations::read_only( 'Read FlitAgent user meta' ),
		);
	}

	/**
	 * Reads the meta.
	 *
	 * @param array<string, mixed> $arguments `user_id` and optional `key`.
	 * @return array<string, mixed>
	 * @throws ToolError When an argument is invalid or the user does not exist.
	 */
	public function call( array $arguments ): array {
		list( $user_id, $key ) = MetaEntries::arguments( $this->name(), 'user_id', $arguments );

		if ( false === get_userdata( $user_id ) ) {
			throw new ToolError( sprintf( 'No user found with ID %d.', (int) $user_id ) );
		}

		$meta = get_user_meta( $user_id, $key, false );

		return array(
			'user_id' => $user_id,
			'meta'    => MetaEntries::entries( is_array( $meta ) ? $meta : array(), $key ),
		);
	}
}
