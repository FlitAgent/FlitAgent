<?php
/**
 * Which URLs the gateway key fetch may connect to.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Net;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a remote URL and resolves its host (the SSRF rules of 1.0.0's media downloader, unchanged).
 *
 * A URL may be fetched only if it is http(s) without user info, on port 80, 443 or 8080, and its host is a canonical IP
 * literal or a host name whose every address (DNS A and AAAA records plus the system resolver) is public (Address).
 * The caller pins the connection to a checked address (Auth\Gateway\KeyFetcher uses cURL's `CURLOPT_RESOLVE`), so a
 * DNS answer that changes after the check cannot redirect it.
 */
final class Target {

	/**
	 * Ports a URL may use (core's `wp_http_validate_url()` allows the same).
	 */
	const PORTS = array( 80, 443, 8080 );

	/**
	 * Checks a URL and resolves its host. Read-only: makes DNS lookups, never an HTTP request.
	 *
	 * @param string $url URL.
	 * @return array{url: string, scheme: string, host: string, port: int, addresses: string[]} Normalized URL (no
	 *         fragment), lower-case scheme and host (IPv6 in brackets), effective port and every address.
	 * @throws Refusal `blocked_url` when the URL may not be fetched.
	 */
	public static function check( string $url ): array {
		if ( strlen( $url ) > 2048 || 1 === preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			throw new Refusal( 'The URL contains whitespace, control characters or backslashes, or is longer than 2048 characters.' );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			throw new Refusal( 'Only absolute http:// and https:// URLs can be downloaded.' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			throw new Refusal( 'URLs with a user name or password are not downloaded.' );
		}
		$scheme = strtolower( $parts['scheme'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		if ( ! in_array( $port, self::PORTS, true ) ) {
			throw new Refusal( sprintf( 'Port %d is not allowed; use port 80, 443 or 8080.', (int) $port ) );
		}

		$host    = rtrim( strtolower( $parts['host'] ), '.' );
		$literal = Address::literal( $host );
		if ( null !== $literal ) {
			$addresses = array( $literal );
			$host      = false !== strpos( $literal, ':' ) ? '[' . $literal . ']' : $literal;
		} elseif ( '' === $host || 1 !== preg_match( '/^[a-z0-9.-]+$/', $host ) || Address::is_ambiguous( $host ) ) {
			throw new Refusal( 'The URL host must be a host name (IDN in punycode) or a standard IPv4 or bracketed IPv6 address.' );
		} else {
			$addresses = self::resolve( $host );
			if ( array() === $addresses ) {
				throw new Refusal( sprintf( 'The host %s could not be resolved.', esc_textarea( $host ) ) );
			}
		}

		foreach ( $addresses as $address ) {
			if ( ! Address::is_public( $address ) ) {
				throw new Refusal( sprintf( 'The host %s resolves to a non-public address (%s); only public internet addresses can be downloaded from.', esc_textarea( $host ), esc_textarea( $address ) ) );
			}
		}

		return array(
			'url'       => self::origin( $scheme, $host, $port ) . ( isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ),
			'scheme'    => $scheme,
			'host'      => $host,
			'port'      => $port,
			'addresses' => $addresses,
		);
	}

	/**
	 * Every address of a host: DNS A and AAAA records and the system resolver's IPv4 answers (which include the hosts
	 * file), without duplicates. Also used by the development gateway target (Auth\Gateway\KeyFetcher), which applies its own rules.
	 *
	 * @param string $host Host name.
	 * @return string[]
	 */
	public static function resolve( string $host ): array {
		$addresses = array();
		// A failed lookup warns, and nothing tells beforehand; an empty answer is handled below.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Only for these two calls, only warnings, and restored in finally.
		set_error_handler(
			static function (): bool {
				return true;
			},
			E_WARNING
		);
		try {
			$records = dns_get_record( $host, DNS_A | DNS_AAAA );
			$system  = gethostbynamel( $host );
		} finally {
			restore_error_handler();
		}
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			if ( isset( $record['ip'] ) ) {
				$addresses[] = (string) $record['ip'];
			} elseif ( isset( $record['ipv6'] ) ) {
				$addresses[] = (string) $record['ipv6'];
			}
		}
		foreach ( is_array( $system ) ? $system : array() as $address ) {
			$addresses[] = (string) $address;
		}

		$unique = array();
		foreach ( $addresses as $address ) {
			// An invalid answer is kept as it is, and fails the public check.
			$packed = Address::packed( $address );
			$key    = false === $packed ? $address : (string) inet_ntop( $packed );
			if ( ! in_array( $key, $unique, true ) ) {
				$unique[] = $key;
			}
		}
		return $unique;
	}

	/**
	 * `scheme://host`, with the port only when it is not the scheme's default.
	 *
	 * @param string $scheme Scheme.
	 * @param string $host   Host.
	 * @param int    $port   Port.
	 * @return string
	 */
	private static function origin( string $scheme, string $host, int $port ): string {
		return $scheme . '://' . $host . ( ( 'https' === $scheme ? 443 : 80 ) === $port ? '' : ':' . $port );
	}
}
