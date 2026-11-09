<?php
/**
 * Fetches the gateway's published key set.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads `<gateway>/public-key` (a key set, see KeySet) with one GET that follows no redirects, is capped at
 * MAX_BYTES and TIMEOUT seconds and is pinned to a checked address (Pinned: the media downloader's SSRF rules for a
 * public gateway, loopback only for a loopback gateway, and the development allowances of Config).
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
	 * @throws \RuntimeException When the keys cannot be fetched safely or are not a valid key set (a Refusal when the
	 *                           media downloader's SSRF rules refuse the gateway host).
	 */
	public static function fetch( string $gateway ): KeySet {
		$gateway = Config::normalize( $gateway );
		if ( '' === $gateway ) {
			throw new \RuntimeException( 'The gateway URL must use HTTPS.' );
		}
		$target = Pinned::target( $gateway . '/public-key' );

		$body = self::pinned_get( $target['url'], $target['pins'], $target['safe'] );
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

	/**
	 * One GET, no redirects, pinned to the checked address (Pinned::request()).
	 *
	 * @param string   $url  URL.
	 * @param string[] $pins CURLOPT_RESOLVE entries, tried in order.
	 * @param bool     $safe Whether to use wp_safe_remote_get() (public gateways).
	 * @return string Body.
	 * @throws \RuntimeException When the request fails, is not pinned, or does not answer 200 within the cap.
	 */
	private static function pinned_get( string $url, array $pins, bool $safe ): string {
		$response = Pinned::request(
			'GET',
			$url,
			$pins,
			$safe,
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
		return $body;
	}
}
