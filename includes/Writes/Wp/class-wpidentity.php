<?php
/**
 * Caller identity from WordPress.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Wp;

use FlitAgent\Auth\Context;
use FlitAgent\Writes\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * The current user and the AI app named in the request flitagent.com signed (`Auth\Context`). Read at call time,
 * after the gateway authenticator has run.
 */
final class WpIdentity implements Identity {

	/**
	 * Current user id.
	 */
	public function user_id(): int {
		return (int) get_current_user_id();
	}

	/**
	 * The signed request's app id (`fa:...`), or '' without a signed request.
	 */
	public function client_id(): string {
		return Context::current()->client_id();
	}

	/**
	 * The signed request's app name, or ''.
	 */
	public function client_name(): string {
		return Context::current()->client_name();
	}

	/**
	 * The protected-tool gate, limited by the credential's scope.
	 */
	public function can_write(): bool {
		return Context::current()->allows_writes() && current_user_can( 'manage_options' );
	}

	/**
	 * The protected-tool gate alone: a read-scoped app may still read changes and receipts.
	 */
	public function can_review(): bool {
		return current_user_can( 'manage_options' );
	}
}
