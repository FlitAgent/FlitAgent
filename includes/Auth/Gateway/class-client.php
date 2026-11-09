<?php
/**
 * The AI app behind a gateway request.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * The identity in the `FlitAgent-Client` header: base64url (no padding) of `{"id":…,"name":…,"scope":"read"|"write"}`.
 * The header is only trusted after the request signature, which covers its hash, has verified. WordPress-free.
 */
final class Client {

	/**
	 * Longest header value, in bytes.
	 */
	const MAX_HEADER = 1024;

	/**
	 * Longest app name kept, in characters.
	 */
	const MAX_NAME = 100;

	/**
	 * What the recorded client name adds to the app's name. Receipts and changes keep it; wp-admin shows the name
	 * without it (display_name()).
	 */
	const VIA = ' (via flitagent.com)';

	/**
	 * App id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * App name, cleaned.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Scope the app was granted.
	 *
	 * @var string
	 */
	private $scope;

	/**
	 * Constructor.
	 *
	 * @param string $id    App id.
	 * @param string $name  Cleaned name.
	 * @param string $scope `read` or `write`.
	 */
	private function __construct( string $id, string $name, string $scope ) {
		$this->id    = $id;
		$this->name  = $name;
		$this->scope = $scope;
	}

	/**
	 * Parses a header value, or null when it is not a valid client identity.
	 *
	 * @param string $value Header value as sent.
	 */
	public static function from_header( string $value ): ?self {
		if ( strlen( $value ) > self::MAX_HEADER ) {
			return null;
		}
		$json = Signature::decode( $value );
		if ( '' === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || array() === $data || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			return null;
		}
		if ( ! isset( $data['id'], $data['name'], $data['scope'] ) || ! is_string( $data['id'] ) || ! is_string( $data['name'] ) || ! is_string( $data['scope'] ) || '' === $data['id'] ) {
			return null;
		}
		if ( 'read' !== $data['scope'] && 'write' !== $data['scope'] ) {
			return null;
		}
		$name = preg_replace( '/[\p{Cc}\p{Cf}]+/u', '', $data['name'] );
		$name = null === $name ? null : preg_replace( '/\s+/u', ' ', $name );
		if ( null === $name ) {
			return null;
		}
		$name = trim( mb_substr( trim( $name ), 0, self::MAX_NAME, 'UTF-8' ) );
		if ( '' === $name ) {
			$name = 'AI app';
		}
		return new self( $data['id'], $name, $data['scope'] );
	}

	/**
	 * App id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Cleaned app name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Scope the app was granted.
	 */
	public function scope(): string {
		return $this->scope;
	}

	/**
	 * The recorded client id: `fa:` and the first 40 hex digits of the SHA-256 of the connection id, a newline and
	 * the app id. Every user of an app sends the same app id (Claude's CIMD URL), so binding the connection keeps a
	 * re-pair (to another flitagent.com account) from inheriting the old connection's approvals and idempotent replays.
	 *
	 * @param string $connection_id Connection the request was signed for.
	 */
	public function client_id( string $connection_id ): string {
		return 'fa:' . substr( hash( 'sha256', $connection_id . "\n" . $this->id ), 0, 40 );
	}

	/**
	 * The recorded client name.
	 */
	public function client_name(): string {
		return $this->name . self::VIA;
	}

	/**
	 * A recorded client name as wp-admin shows it: the app's name, without the " (via flitagent.com)" every gateway
	 * request's name ends with (the stored value keeps it, for receipts and the audit record). Any other name is
	 * returned as stored.
	 *
	 * @param string $recorded The stored client name.
	 */
	public static function display_name( string $recorded ): string {
		$length = strlen( self::VIA );
		if ( strlen( $recorded ) > $length && self::VIA === substr( $recorded, -$length ) ) {
			return substr( $recorded, 0, -$length );
		}
		return $recorded;
	}
}
