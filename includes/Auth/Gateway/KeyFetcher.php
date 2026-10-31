<?php
/**
 * Fetches the gateway's published key set.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads `<gateway>/public-key` (a key set, see KeySet) with one GET that follows no
 * redirects, is capped at MAX_BYTES and TIMEOUT seconds and is pinned to a checked address with cURL's
 * `CURLOPT_RESOLVE` (Pinned).
 *
 * A public gateway goes through the SSRF rules of `Auth\Net\Target::check()` (every DNS
 * address must be public, ports 80/443/8080) and `wp_safe_remote_get()`. Only when the configured gateway URL itself is
 * loopback (development) is the request made to loopback: `localhost` is pinned to 127.0.0.1, then to ::1 if that
 * connection fails, without a DNS lookup, so a loopback allowance can never reach anything but this machine.
 *
 * In a `local` or `development` environment with `FLITAGENT_GATEWAY_ALLOW_PRIVATE` (Config::allows_private()), a gateway
 * host the SSRF rules refuse is fetched anyway when every address it resolves to is public, private (10/8, 172.16/12,
 * 192.168/16, 100.64/10, fc00::/7) or loopback; link-local (cloud metadata), unspecified, multicast and reserved
 * addresses stay refused, and the request is still pinned. `FLITAGENT_GATEWAY_CAINFO` (Config::cainfo()) replaces the CA
 * bundle in the same environments only; neither constant turns TLS verification off.
 */
final class KeyFetcher {

	/**
	 * Largest key document, in bytes.
	 */
	const MAX_BYTES = 8192;

	/**
	 * Request timeout, in seconds.
	 */
	const TIMEOUT = 10;

	/**
	 * The gateway's published key set.
	 *
	 * @param string $gateway Gateway URL.
	 * @return KeySet
	 * @throws \RuntimeException When the keys cannot be fetched safely or are not a valid key set; a Net\Refusal when the
	 *                           SSRF rules (Net\Target) refuse the gateway host.
	 */
	public static function fetch( string $gateway ): KeySet {
		$gateway = Config::normalize( $gateway );
		if ( '' === $gateway ) {
			throw new \RuntimeException( 'The gateway URL must use HTTPS.' );
		}

		$target   = Pinned::target( $gateway . '/public-key' );
		$response = Pinned::request(
			'GET',
			$target['url'],
			$target['pins'],
			$target['safe'],
			array(
				'timeout'             => self::TIMEOUT,
				'limit_response_size' => self::MAX_BYTES + 1,
				'headers'             => array( 'Accept' => 'application/json' ),
				'user-agent'          => 'FlitAgent/' . FLITAGENT_VERSION . ' (key set)',
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Could not reach the gateway: ' . esc_textarea( $response->get_error_message() ) );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || strlen( $body ) > self::MAX_BYTES ) {
			throw new \RuntimeException( 'The gateway did not return its public key.' );
		}
		return KeySet::from_json( $body );
	}

	/**
	 * The CURLOPT_RESOLVE entries of the last pinned request of any kind (key fetch or nudge). For tests.
	 *
	 * @return string[]
	 */
	public static function last_pin(): array {
		return Pinned::last_pin();
	}
}
