<?php
/**
 * Per-run token for the custom header check.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * A short-lived token, `<expiry>.<random>.<HMAC-SHA256>`, minted once per "Test connection" run by a user who passed
 * the access gate and the nonce check. It is signed with a site secret instead of stored, so nothing is written to the
 * database, and it grants nothing: the diagnostics route only answers whether a test header arrived.
 */
final class DiagnosticsToken {

	/**
	 * Lifetime in seconds.
	 */
	const TTL = 120;

	/**
	 * Mints a token.
	 *
	 * @param string $secret Signing secret.
	 * @param int    $now    Current Unix time.
	 * @param string $random Random hex, fresh for every run.
	 * @return string
	 */
	public static function mint( string $secret, int $now, string $random ): string {
		$payload = ( $now + self::TTL ) . '.' . $random;
		return $payload . '.' . self::sign( $secret, $payload );
	}

	/**
	 * Whether a token was minted with this secret and has not expired.
	 *
	 * @param string $secret Signing secret.
	 * @param string $token  Token.
	 * @param int    $now    Current Unix time.
	 */
	public static function verify( string $secret, string $token, int $now ): bool {
		if ( 1 !== preg_match( '/^(\d{1,12})\.([a-f0-9]{32})\.([a-f0-9]{64})$/', $token, $parts ) ) {
			return false;
		}
		if ( ! hash_equals( self::sign( $secret, $parts[1] . '.' . $parts[2] ), $parts[3] ) ) {
			return false;
		}
		return (int) $parts[1] >= $now;
	}

	/**
	 * HMAC of a payload.
	 *
	 * @param string $secret  Secret.
	 * @param string $payload Payload.
	 */
	private static function sign( string $secret, string $payload ): string {
		return hash_hmac( 'sha256', 'flitagent-diagnostics|' . $payload, $secret );
	}
}
