<?php
/**
 * Caller identity port.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * Who is calling: the WordPress user and the AI app (client) named in the request flitagent.com signed.
 */
interface Identity {

	/**
	 * Current user id (0 when logged out).
	 */
	public function user_id(): int;

	/**
	 * The signed request's app id, or '' without a signed request.
	 */
	public function client_id(): string;

	/**
	 * The signed request's app name, or ''.
	 */
	public function client_name(): string;

	/**
	 * Whether the caller passes the protected-tool gate (`manage_options`) and the credential may write.
	 */
	public function can_write(): bool;

	/**
	 * Whether the caller passes the protected-tool gate (`manage_options`), whatever the credential's scope: enough to
	 * read changes and receipts (`get_change`, `list_receipts`), not to write.
	 */
	public function can_review(): bool;
}
