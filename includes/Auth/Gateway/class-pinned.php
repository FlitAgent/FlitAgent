<?php
/**
 * The pinned request the plugin makes to flitagent.com on its own (the key set fetch and the nudge).
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

use FlitAgent\Auth\Net\Address;
use FlitAgent\Auth\Net\Refusal;
use FlitAgent\Auth\Net\Target;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a gateway URL's host and makes one request to it that follows no redirects and is pinned to a checked address
 * with cURL's `CURLOPT_RESOLVE`.
 *
 * A public gateway goes through the SSRF rules of `Auth\Net\Target::check()` (every DNS address must be public,
 * ports 80/443/8080) and `wp_safe_remote_get()` / `wp_safe_remote_post()`. Only when the URL
 * itself is loopback (development) is the request made to loopback: `localhost` is pinned to 127.0.0.1, then to ::1 if
 * that connection fails, without a DNS lookup, so a loopback allowance can never reach anything but this machine.
 *
 * In a `local` or `development` environment with `FLITAGENT_GATEWAY_ALLOW_PRIVATE` (Config::allows_private()), a gateway
 * host the SSRF rules refuse is reached anyway when every address it resolves to is public, private (10/8, 172.16/12,
 * 192.168/16, 100.64/10, fc00::/7) or loopback; link-local (cloud metadata), unspecified, multicast and reserved
 * addresses stay refused, and the request is still pinned. `FLITAGENT_GATEWAY_CAINFO` (Config::cainfo()) replaces the CA
 * bundle in the same environments only; neither constant turns TLS verification off.
 *
 * Callers check the gateway URL first (Config::normalize(): HTTPS, or HTTP on a loopback host).
 */
final class Pinned {

	/**
	 * The CURLOPT_RESOLVE entry of the last request (for tests).
	 *
	 * @var string[]
	 */
	private static $last_pin = array();

	/**
	 * Where a request to a gateway URL goes: the URL, the CURLOPT_RESOLVE entries to try in order, and whether the
	 * request goes through wp_safe_remote_*() (public gateways).
	 *
	 * @param string $url Gateway URL (already checked by Config::normalize(), with a path).
	 * @return array{url: string, pins: string[], safe: bool}
	 * @throws Refusal When the SSRF rules (Auth\Net\Target) refuse the host. In development (Config::allows_private()),
	 *                 a plain \RuntimeException when the host cannot be resolved or resolves to addresses it may not reach.
	 */
	public static function target( string $url ): array {
		if ( Config::is_loopback( $url ) ) {
			$parts = wp_parse_url( $url );
			$host  = strtolower( (string) $parts['host'] );
			$port  = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $parts['scheme'] ? 443 : 80 );
			// Local servers listen on 127.0.0.1, ::1 or both (WordPress Studio: ::1 only), so localhost tries both, in order.
			$addresses = 'localhost' === $host ? array( '127.0.0.1', '[::1]' ) : array( $host );
			$pins      = array();
			foreach ( $addresses as $address ) {
				$pins[] = trim( $host, '[]' ) . ':' . $port . ':' . $address;
			}
			return array(
				'url'  => $url,
				'pins' => $pins,
				'safe' => false,
			);
		}

		try {
			$target = Target::check( $url );
			$safe   = true;
		} catch ( Refusal $e ) {
			if ( ! Config::allows_private() ) {
				throw $e;
			}
			$target = self::development_target( $url );
			$safe   = false;
		}
		$address = $target['addresses'][0];
		foreach ( $target['addresses'] as $candidate ) {
			if ( false === strpos( $candidate, ':' ) ) {
				$address = $candidate;
				break;
			}
		}
		return array(
			'url'  => $target['url'],
			'pins' => array( trim( $target['host'], '[]' ) . ':' . $target['port'] . ':' . ( false !== strpos( $address, ':' ) ? '[' . $address . ']' : $address ) ),
			'safe' => $safe,
		);
	}

	/**
	 * One GET or POST, no redirects, pinned to the checked address through a one-time token. With several pins
	 * (localhost: IPv4 then IPv6) the next one is tried only when the connection itself failed.
	 *
	 * @param string   $method `GET` or `POST`.
	 * @param string   $url    URL (from target()).
	 * @param string[] $pins   CURLOPT_RESOLVE entries, tried in order (from target()).
	 * @param bool     $safe   Whether to use wp_safe_remote_get() / wp_safe_remote_post() (public gateways).
	 * @param array    $args   Request arguments (timeout, limit_response_size, headers, user-agent, body); the redirect,
	 *                         cookie, URL-safety, pin and CA arguments are set here.
	 * @return array|\WP_Error The response, or the last connection error.
	 * @throws \RuntimeException When the method is neither GET nor POST, there is no pin, or the request could not be pinned.
	 */
	public static function request( string $method, string $url, array $pins, bool $safe, array $args ) {
		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) || array() === $pins ) {
			throw new \RuntimeException( 'The gateway request needs GET or POST and a checked address.' );
		}
		self::$last_pin = array();
		$pins           = array_values( $pins );
		foreach ( $pins as $i => $pin ) {
			$response = self::pinned_request( $method, $url, (string) $pin, $safe, $args );
			if ( ! is_wp_error( $response ) || count( $pins ) - 1 === $i ) {
				break;
			}
		}
		return $response;
	}

	/**
	 * The CURLOPT_RESOLVE entries of the last request. For tests.
	 *
	 * @return string[]
	 */
	public static function last_pin(): array {
		return self::$last_pin;
	}

	/**
	 * The target of a gateway on a private host (development only, see Config::allows_private()): the host's every
	 * address must be public, private or loopback. Any port.
	 *
	 * @param string $url Gateway URL.
	 * @return array{url: string, host: string, port: int, addresses: string[]}
	 * @throws \RuntimeException When the host cannot be resolved or resolves to another kind of address.
	 */
	private static function development_target( string $url ): array {
		$parts   = wp_parse_url( $url );
		$scheme  = strtolower( (string) $parts['scheme'] );
		$host    = strtolower( (string) $parts['host'] );
		$port    = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		$literal = Address::literal( $host );
		// Config::normalize() allows only host names and IPv4 literals, so $host needs no brackets.
		$addresses = null !== $literal ? array( $literal ) : Target::resolve( $host );
		if ( array() === $addresses ) {
			throw new \RuntimeException( sprintf( 'The gateway host %s could not be resolved.', esc_textarea( $host ) ) );
		}
		foreach ( $addresses as $address ) {
			if ( ! Address::is_public( $address ) && ! Address::is_local_network( $address ) ) {
				throw new \RuntimeException( sprintf( 'The gateway host %s resolves to %s, which is neither a public, a private nor a loopback address.', esc_textarea( $host ), esc_textarea( $address ) ) );
			}
		}
		return array(
			'url'       => $url,
			'host'      => $host,
			'port'      => $port,
			'addresses' => $addresses,
		);
	}

	/**
	 * One pinned request.
	 *
	 * @param string $method `GET` or `POST`.
	 * @param string $url    URL.
	 * @param string $pin    CURLOPT_RESOLVE entry.
	 * @param bool   $safe   Whether to use wp_safe_remote_get() / wp_safe_remote_post() (public gateways).
	 * @param array  $args   Request arguments.
	 * @return array|\WP_Error Response.
	 * @throws \RuntimeException When the request could not be pinned.
	 */
	private static function pinned_request( string $method, string $url, string $pin, bool $safe, array $args ) {
		$token  = bin2hex( random_bytes( 16 ) );
		$pinned = new \ArrayObject( array( 'done' => false ) );

		$hook = static function ( $handle, $request = array() ) use ( $token, $pin, $pinned ) {
			if ( ! is_array( $request ) || ! isset( $request['flitagent_pin'] ) || ! is_string( $request['flitagent_pin'] ) || ! hash_equals( $token, $request['flitagent_pin'] ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
				return;
			}
			// Pinning the connection needs the cURL handle WordPress hands to http_api_curl; the HTTP API has no equivalent.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- see above.
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $pin ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- see above.
			curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
			if ( defined( 'CURLOPT_PROTOCOLS' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- see above.
				curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
			}
			$pinned['done'] = true;
		};

		$args['redirection']        = 0;
		$args['reject_unsafe_urls'] = $safe;
		$args['cookies']            = array();
		$args['flitagent_pin']      = $token;
		unset( $args['sslcertificates'], $args['sslverify'] );
		$cainfo = Config::cainfo();
		if ( '' !== $cainfo ) {
			// A local development CA (local and development environments only); this does not turn verification off.
			$args['sslcertificates'] = $cainfo;
		}

		add_action( 'http_api_curl', $hook, 10, 3 );
		try {
			// A loopback or private gateway (development only, see Config) is refused by wp_safe_remote_*(); it is pinned above.
			if ( 'POST' === $method ) {
				$response = $safe ? wp_safe_remote_post( $url, $args ) : wp_remote_post( $url, $args );
			} else {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- see above; the public path uses wp_safe_remote_get().
				$response = $safe ? wp_safe_remote_get( $url, $args ) : wp_remote_get( $url, $args );
			}
		} finally {
			remove_action( 'http_api_curl', $hook, 10 );
		}

		if ( ! $pinned['done'] ) {
			throw new \RuntimeException( 'The gateway request could not be pinned to the checked address because WordPress did not use its cURL transport.' );
		}
		self::$last_pin = array( $pin );
		return $response;
	}
}
