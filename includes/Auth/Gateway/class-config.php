<?php
/**
 * The configured gateway URL.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

use FlitAgent\Writes\Media\Address;

defined( 'ABSPATH' ) || exit;

/**
 * Gateway authentication is on by default and points at flitagent.com. The `FLITAGENT_GATEWAY_URL` constant and the
 * `flitagent_gateway_url` filter override the gateway, and `FLITAGENT_MCP_URL` / `flitagent_mcp_url` override the MCP
 * address, for development and staging. Overrides must be HTTPS; plain HTTP is accepted only for loopback hosts
 * (`localhost`, `127.0.0.0/8`). A URL may have a path but no user info, query or fragment, and its host must be a name or
 * an IPv4 address: WordPress's redirect check refuses every host with a `:`, so an IPv6 literal could never be redirected
 * to.
 *
 * Two more constants exist for developing against a local flitagent.com and are ignored unless `wp_get_environment_type()`
 * is `local` or `development`: `FLITAGENT_GATEWAY_ALLOW_PRIVATE` (true) lets the key-set request reach a gateway host that
 * resolves to a private or loopback address, and `FLITAGENT_GATEWAY_CAINFO` (path to a readable CA bundle) is the bundle
 * that request's TLS verification trusts instead of WordPress's. Neither turns TLS verification off.
 */
final class Config {

	const CONSTANT        = 'FLITAGENT_GATEWAY_URL';
	const FILTER          = 'flitagent_gateway_url';
	const DEFAULT_URL     = 'https://flitagent.com/connect';
	const MCP_CONSTANT    = 'FLITAGENT_MCP_URL';
	const MCP_FILTER      = 'flitagent_mcp_url';
	const DEFAULT_MCP_URL = 'https://mcp.flitagent.com/';
	const GUIDE_CLAUDE    = 'https://flitagent.com/connect/#claude';
	const GUIDE_CHATGPT   = 'https://flitagent.com/connect/#chatgpt';
	const GUIDE_MANUAL    = 'https://flitagent.com/connect/#manual';

	const ALLOW_PRIVATE_CONSTANT = 'FLITAGENT_GATEWAY_ALLOW_PRIVATE';
	const CAINFO_CONSTANT        = 'FLITAGENT_GATEWAY_CAINFO';
	const DEVELOPMENT            = array( 'local', 'development' );

	/**
	 * The normalized gateway URL: the constant, else the default, then the filter. '' only for an invalid override.
	 *
	 * @return string
	 */
	public static function url(): string {
		$raw = defined( self::CONSTANT ) ? constant( self::CONSTANT ) : self::DEFAULT_URL;

		/**
		 * Filters the FlitAgent gateway URL (default https://flitagent.com/connect).
		 *
		 * @param string $url Gateway URL: HTTPS, or HTTP on a loopback host for development.
		 */
		$raw = apply_filters( 'flitagent_gateway_url', is_string( $raw ) ? $raw : '' );
		return is_string( $raw ) ? self::normalize( $raw ) : '';
	}

	/**
	 * The normalized MCP address: the constant, else the default, then the filter; an invalid value falls back to the default.
	 *
	 * @return string
	 */
	public static function mcp_url(): string {
		$raw = defined( self::MCP_CONSTANT ) ? constant( self::MCP_CONSTANT ) : self::DEFAULT_MCP_URL;

		/**
		 * Filters the FlitAgent MCP address (default https://mcp.flitagent.com/).
		 *
		 * @param string $url MCP URL: HTTPS, or HTTP on a loopback host for development.
		 */
		$raw = apply_filters( 'flitagent_mcp_url', is_string( $raw ) ? $raw : '' );
		$url = is_string( $raw ) ? self::normalize( $raw ) : '';
		if ( '' === $url ) {
			return self::DEFAULT_MCP_URL;
		}
		// An address with no path is the server root: keep its slash, which normalize() drops.
		return null === wp_parse_url( $url, PHP_URL_PATH ) ? $url . '/' : $url;
	}

	/**
	 * The `scheme://host[:port]` of a (normalized) URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '';
		}
		return strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * The flitagent.com account dashboard for a gateway.
	 *
	 * @param string $gateway Gateway URL.
	 * @return string
	 */
	public static function account_url( string $gateway ): string {
		return self::origin( $gateway ) . '/account/';
	}

	/**
	 * The flitagent.com page that checks a site's connection.
	 *
	 * @param string $gateway       Gateway URL.
	 * @param string $connection_id Connection id.
	 * @return string
	 */
	public static function check_url( string $gateway, string $connection_id ): string {
		return self::origin( $gateway ) . '/account/sites/check/?connection=' . rawurlencode( $connection_id );
	}

	/**
	 * Normalizes a gateway URL (lower-case scheme and host, no trailing slash), or '' when it is not allowed.
	 *
	 * @param string $raw URL.
	 * @return string
	 */
	public static function normalize( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 2048 || 1 === preg_match( '/[\x00-\x20\x7f\\\\]/', $raw ) ) {
			return '';
		}
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$literal = Address::literal( $host );
		if ( null !== $literal && false !== strpos( $literal, ':' ) ) {
			return '';
		}
		if ( null === $literal && ( 1 !== preg_match( '/^([a-z0-9-]+\.)*[a-z0-9-]+$/', $host ) || Address::is_ambiguous( $host ) ) ) {
			return '';
		}
		if ( 'http' === $scheme && ! self::is_loopback_host( $host ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * Whether a (normalized) gateway URL is on a loopback host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_loopback( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && self::is_loopback_host( strtolower( $host ) );
	}

	/**
	 * Whether the gateway may be on a private or loopback address: `FLITAGENT_GATEWAY_ALLOW_PRIVATE` is true and the
	 * environment is `local` or `development`. Always false in any other environment, whatever the constant says.
	 *
	 * @return bool
	 */
	public static function allows_private(): bool {
		return defined( self::ALLOW_PRIVATE_CONSTANT ) && true === constant( self::ALLOW_PRIVATE_CONSTANT ) && self::is_development();
	}

	/**
	 * The CA bundle the key-set request trusts instead of WordPress's: `FLITAGENT_GATEWAY_CAINFO` when the environment is
	 * `local` or `development` and the value names a readable file, else ''.
	 *
	 * @return string
	 */
	public static function cainfo(): string {
		if ( ! defined( self::CAINFO_CONSTANT ) || ! self::is_development() ) {
			return '';
		}
		$path = constant( self::CAINFO_CONSTANT );
		return is_string( $path ) && '' !== $path && self::readable_file( $path ) ? $path : '';
	}

	/**
	 * Whether `wp_get_environment_type()` is `local` or `development`.
	 *
	 * @return bool
	 */
	private static function is_development(): bool {
		return in_array( wp_get_environment_type(), self::DEVELOPMENT, true );
	}

	/**
	 * Whether a path is a readable file, without a warning when open_basedir (set by WordPress Studio and many hosts) keeps
	 * it out of reach: such a path is simply not readable.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function readable_file( string $path ): bool {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Only for these two calls, only warnings, and restored in finally.
		set_error_handler(
			static function (): bool {
				return true;
			},
			E_WARNING
		);
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable -- WP_Filesystem has no readability check before it is set up.
			return is_file( $path ) && is_readable( $path );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Whether a URL host is `localhost`, a canonical IPv4 address in 127.0.0.0/8 or `[::1]`. `[::1]` matters only for
	 * this site's own addresses (ConnectScreen::is_local()); normalize() refuses IPv6 literals in gateway URLs.
	 *
	 * @param string $host Lower-case URL host.
	 * @return bool
	 */
	private static function is_loopback_host( string $host ): bool {
		if ( 'localhost' === $host ) {
			return true;
		}
		$literal = Address::literal( $host );
		return '::1' === $literal || ( null !== $literal && 0 === strpos( $literal, '127.' ) );
	}
}
