<?php
/**
 * User and role write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Users;

defined( 'ABSPATH' ) || exit;

/**
 * Users, roles and capabilities (package D). Every tool needs an administrator's approval.
 */
final class Provider implements \FlitAgent\Tools\Provider {

	/**
	 * No read tools.
	 *
	 * @return \FlitAgent\Mcp\Tool[]
	 */
	public function reads(): array {
		return array();
	}

	/**
	 * Write tools.
	 *
	 * @return \FlitAgent\Writes\WriteTool[]
	 */
	public function writes(): array {
		return array(
			new CreateUser(),
			new UpdateUser(),
			new SendPasswordReset(),
			new SetUserRole(),
			new DeleteUser(),
			new SetUserMeta(),
			new DeleteUserMeta(),
			new CreateRole(),
			new DeleteRole(),
			new SetRoleCapabilities(),
			new SetUserCapabilities(),
		);
	}
}
