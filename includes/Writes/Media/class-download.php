<?php
/**
 * SSRF-safe image download for upload_media_from_url.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Checks remote URLs and downloads images from them.
 *
 * A URL is fetched only if it is http(s) without user info, on port 80, 443 or 8080, and its host is a canonical IP
 * literal or a host name whose every address (DNS A and AAAA records plus the system resolver) is public (Address).
 * The connection is pinned to a checked address with cURL's `CURLOPT_RESOLVE`, so a DNS answer that changes after the
 * check cannot redirect it. Redirects are not followed by the HTTP client: each hop is checked and pinned again. Core's
 * `wp_safe_remote_get()` checks stay on as a second layer. The download is streamed to a temporary file, capped at
 * max_bytes(), and kept only if its bytes are a raster image type the site allows. SVG is never accepted.
 */
final class Download {

	/**
	 * Default size cap: 10 MB.
	 */
	const DEFAULT_MAX_BYTES = 10485760;

	/**
	 * Redirects followed before giving up.
	 */
	const MAX_REDIRECTS = 5;

	/**
	 * Request timeout, in seconds.
	 */
	const TIMEOUT = 30;

	/**
	 * Ports a URL may use (core's `wp_http_validate_url()` allows the same).
	 */
	const PORTS = array( 80, 443, 8080 );

	/**
	 * Raster image types that may be stored (if the site also allows them). Detected from the bytes.
	 */
	const RASTER = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic', 'image/bmp', 'image/tiff' );

	/**
	 * Largest download in bytes: the `flitagent_media_max_bytes` filter, default 10 MB. Anything but a positive integer
	 * falls back to the default.
	 *
	 * @return int
	 */
	public static function max_bytes(): int {
		$value = apply_filters( 'flitagent_media_max_bytes', self::DEFAULT_MAX_BYTES );
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$value = (int) $value;
			return $value > 0 ? $value : self::DEFAULT_MAX_BYTES;
		}
		return self::DEFAULT_MAX_BYTES;
	}

	/**
	 * Checks a URL and resolves its host. Read-only: makes DNS lookups, never an HTTP request.
	 *
	 * @param string $url URL.
	 * @return array{url: string, scheme: string, host: string, port: int, addresses: string[]} Normalized URL (no
	 *         fragment), lower-case scheme and host (IPv6 in brackets), effective port and every address.
	 * @throws Refusal `blocked_url` when the URL may not be fetched.
	 */
	public static function target( string $url ): array {
		if ( strlen( $url ) > 2048 || 1 === preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			throw new Refusal( 'blocked_url', 'The URL contains whitespace, control characters or backslashes, or is longer than 2048 characters.' );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			throw new Refusal( 'blocked_url', 'Only absolute http:// and https:// URLs can be downloaded.' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			throw new Refusal( 'blocked_url', 'URLs with a user name or password are not downloaded.' );
		}
		$scheme = strtolower( $parts['scheme'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		if ( ! in_array( $port, self::PORTS, true ) ) {
			throw new Refusal( 'blocked_url', sprintf( 'Port %d is not allowed; use port 80, 443 or 8080.', (int) $port ) );
		}

		$host    = rtrim( strtolower( $parts['host'] ), '.' );
		$literal = Address::literal( $host );
		if ( null !== $literal ) {
			$addresses = array( $literal );
			$host      = false !== strpos( $literal, ':' ) ? '[' . $literal . ']' : $literal;
		} elseif ( '' === $host || 1 !== preg_match( '/^[a-z0-9.-]+$/', $host ) || Address::is_ambiguous( $host ) ) {
			throw new Refusal( 'blocked_url', 'The URL host must be a host name (IDN in punycode) or a standard IPv4 or bracketed IPv6 address.' );
		} else {
			$addresses = self::resolve( $host );
			if ( array() === $addresses ) {
				throw new Refusal( 'blocked_url', sprintf( 'The host %s could not be resolved.', esc_textarea( $host ) ) );
			}
		}

		foreach ( $addresses as $address ) {
			if ( ! Address::is_public( $address ) ) {
				throw new Refusal( 'blocked_url', sprintf( 'The host %s resolves to a non-public address (%s); only public internet addresses can be downloaded from.', esc_textarea( $host ), esc_textarea( $address ) ) );
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
	 * Downloads an image to a temporary file. The caller owns (moves or deletes) the returned file; on failure nothing
	 * is left behind.
	 *
	 * @param string $url       URL.
	 * @param int    $max_bytes Size cap.
	 * @return array{file: string, mime: string, bytes: int, name: string, url: string}
	 * @throws Refusal When the URL, a redirect, the response, the size or the type is refused.
	 */
	public static function fetch( string $url, int $max_bytes ): array {
		if ( defined( 'WP_PROXY_HOST' ) && defined( 'WP_PROXY_PORT' ) ) {
			throw new Refusal( 'blocked_url', 'This site sends HTTP requests through a proxy, so the download cannot be pinned to a checked address. FlitAgent does not download media through a proxy.' );
		}
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) ) {
			throw new Refusal( 'blocked_url', 'The PHP cURL extension is not available, so the download cannot be pinned to a checked address.' );
		}

		$file = wp_tempnam( 'flitagent-media' );
		if ( ! $file ) {
			throw new Refusal( 'download_failed', 'Could not create a temporary file for the download.' );
		}

		try {
			for ( $redirects = 0; ; $redirects++ ) {
				$target   = self::target( $url );
				$response = self::pinned_get( $target, $file, $max_bytes );
				$status   = (int) wp_remote_retrieve_response_code( $response );
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				if ( $status >= 300 && $status < 400 && '' !== $location ) {
					if ( $redirects >= self::MAX_REDIRECTS ) {
						throw new Refusal( 'download_failed', sprintf( 'The URL redirects more than %d times.', self::MAX_REDIRECTS ) );
					}
					$url = self::location( $target, $location );
					continue;
				}
				if ( 200 !== $status ) {
					throw new Refusal( 'download_failed', sprintf( 'The URL returned HTTP %d, not the image.', $status ) );
				}
				break;
			}

			clearstatcache( true, $file );
			$bytes = (int) filesize( $file );
			if ( $bytes > $max_bytes ) {
				throw new Refusal( 'too_large', sprintf( 'The file is larger than the %d byte limit.', $max_bytes ) );
			}

			$mime = 0 === $bytes ? false : wp_get_image_mime( $file );
			if ( ! is_string( $mime ) || ! in_array( $mime, self::RASTER, true ) ) {
				throw new Refusal( 'unsupported_type', 'The file is not a raster image (JPEG, PNG, GIF, WebP, AVIF, HEIC, BMP or TIFF). SVG and other files are not accepted.' );
			}
			if ( ! in_array( $mime, get_allowed_mime_types(), true ) ) {
				throw new Refusal( 'unsupported_type', sprintf( 'This site does not allow %s uploads.', $mime ) );
			}

			return array(
				'file'  => $file,
				'mime'  => $mime,
				'bytes' => $bytes,
				'name'  => self::file_name( $url, $mime ),
				'url'   => $url,
			);
		} catch ( Refusal $e ) {
			wp_delete_file( $file );
			throw $e;
		}//end try
	}

	/**
	 * Every address of a host: DNS A and AAAA records and the system resolver's IPv4 answers (which include the hosts
	 * file), without duplicates. Also used by the gateway's pinned requests (Auth\Gateway\Pinned: the key fetch and the nudge), which apply their own rules.
	 *
	 * @param string $host Host name.
	 * @return string[]
	 */
	public static function resolve( string $host ): array {
		// PHP 8 throws on a null byte in a host name, and no host has one.
		if ( false !== strpos( $host, "\0" ) ) {
			return array();
		}
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
	 * One GET, not following redirects, streamed to the file and pinned to the target's first IPv4 address (or first
	 * IPv6 address when there is none). The pin is applied only to this request, through a one-time token.
	 *
	 * @param array<string, mixed> $target    Checked target.
	 * @param string               $file      Temporary file.
	 * @param int                  $max_bytes Size cap.
	 * @return array<string, mixed> Response.
	 * @throws Refusal When the request fails or could not be pinned.
	 */
	private static function pinned_get( array $target, string $file, int $max_bytes ): array {
		$address = $target['addresses'][0];
		foreach ( $target['addresses'] as $candidate ) {
			if ( false === strpos( $candidate, ':' ) ) {
				$address = $candidate;
				break;
			}
		}
		$resolve = trim( $target['host'], '[]' ) . ':' . $target['port'] . ':' . ( false !== strpos( $address, ':' ) ? '[' . $address . ']' : $address );
		$token   = bin2hex( random_bytes( 16 ) );
		$pinned  = new \ArrayObject( array( 'done' => false ) );

		$hook = static function ( $handle, $request = array() ) use ( $token, $resolve, $pinned ) {
			if ( ! is_array( $request ) || ! isset( $request['flitagent_pin'] ) || ! is_string( $request['flitagent_pin'] ) || ! hash_equals( $token, $request['flitagent_pin'] ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
				return;
			}
			// Pinning the connection needs the cURL handle WordPress hands to http_api_curl; the HTTP API has no equivalent.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- see above.
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $resolve ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- see above.
			curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
			if ( defined( 'CURLOPT_PROTOCOLS' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- see above.
				curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
			}
			$pinned['done'] = true;
		};

		add_action( 'http_api_curl', $hook, 10, 3 );
		try {
			$response = wp_safe_remote_get(
				$target['url'],
				array(
					'timeout'             => self::TIMEOUT,
					'redirection'         => 0,
					'reject_unsafe_urls'  => true,
					'stream'              => true,
					'filename'            => $file,
					'limit_response_size' => $max_bytes + 1,
					'cookies'             => array(),
					'headers'             => array( 'Accept' => 'image/*' ),
					'user-agent'          => 'FlitAgent/' . FLITAGENT_VERSION . ' (upload_media_from_url)',
					'flitagent_pin'       => $token,
				)
			);
		} finally {
			remove_action( 'http_api_curl', $hook, 10 );
		}

		if ( ! $pinned['done'] ) {
			throw new Refusal( 'blocked_url', 'The download could not be pinned to the checked address because WordPress did not use its cURL transport, so it was discarded.' );
		}
		if ( is_wp_error( $response ) ) {
			throw new Refusal( 'download_failed', 'Could not download the file: ' . esc_textarea( $response->get_error_message() ) );
		}
		return $response;
	}

	/**
	 * Resolves a Location header against the URL that redirected. The result is checked again by target().
	 *
	 * @param array<string, mixed> $target   Target that redirected.
	 * @param string               $location Location header.
	 * @return string
	 */
	private static function location( array $target, string $location ): string {
		if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $location ) || 1 === preg_match( '/[\x00-\x20\x7f\\\\]/', $location ) ) {
			return $location;
		}
		if ( 0 === strpos( $location, '//' ) ) {
			return $target['scheme'] . ':' . $location;
		}
		$origin = self::origin( $target['scheme'], $target['host'], $target['port'] );
		if ( 0 === strpos( $location, '/' ) ) {
			return $origin . $location;
		}
		$path = (string) wp_parse_url( $target['url'], PHP_URL_PATH );
		return $origin . substr( $path, 0, (int) strrpos( $path, '/' ) + 1 ) . $location;
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

	/**
	 * A safe file name: the URL's last path segment without its extension, plus the extension of the detected type.
	 *
	 * @param string $url  Final URL.
	 * @param string $mime Detected type.
	 * @return string
	 */
	private static function file_name( string $url, string $mime ): string {
		$base = rawurldecode( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$stem = sanitize_file_name( (string) preg_replace( '/\.[^.]*$/', '', $base ) );
		$stem = '' === $stem ? 'image' : substr( $stem, 0, 100 );
		$ext  = wp_get_default_extension_for_mime_type( $mime );
		return $stem . '.' . ( is_string( $ext ) && '' !== $ext ? $ext : substr( $mime, 6 ) );
	}
}
