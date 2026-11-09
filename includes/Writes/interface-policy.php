<?php
/**
 * Site policy port.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * Site settings that govern writes.
 */
interface Policy {

	/**
	 * Whether writes are turned on (`flitagent_writes_enabled`, default on: Read and propose changes).
	 */
	public function writes_enabled(): bool;

	/**
	 * Whether this is a multisite install (writes are refused there for now).
	 */
	public function is_multisite(): bool;

	/**
	 * Whether an administrator enabled auto-approve for a tool. Gateway still applies a write automatically only when it
	 * is reversible and auto-approvable.
	 *
	 * @param string $tool Tool name.
	 * @return bool
	 */
	public function auto_approves( string $tool ): bool;

	/**
	 * Seconds an approval stays valid for apply_change.
	 */
	public function approval_ttl(): int;

	/**
	 * The wp-admin URL where a human reviews a change.
	 *
	 * @param string $change_id Change UUID.
	 * @return string
	 */
	public function approve_url( string $change_id ): string;
}
