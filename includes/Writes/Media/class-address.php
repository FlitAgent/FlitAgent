<?php
/**
 * Which network addresses media downloads may reach.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies IP addresses for the media downloader. Only globally routable unicast addresses are public: everything
 * that can reach the site's own machine, its network or cloud metadata, and every special-purpose range, is blocked.
 * IPv6 must be global unicast (2000::/3) and outside the special ranges inside it; IPv4-mapped, IPv4-compatible,
 * NAT64, 6to4 and Teredo addresses are blocked rather than decoded.
 */
final class Address {

	/**
	 * Blocked IPv4 ranges => why (IANA special-purpose registry, RFC 6890 and updates).
	 */
	const BLOCKED_V4 = array(
		'0.0.0.0/8'       => 'this network',
		'10.0.0.0/8'      => 'private',
		'100.64.0.0/10'   => 'shared address space (CGNAT)',
		'127.0.0.0/8'     => 'loopback',
		'169.254.0.0/16'  => 'link local, including cloud metadata (169.254.169.254)',
		'172.16.0.0/12'   => 'private',
		'192.0.0.0/24'    => 'IETF protocol assignments',
		'192.0.2.0/24'    => 'documentation (TEST-NET-1)',
		'192.88.99.0/24'  => '6to4 relay anycast',
		'192.168.0.0/16'  => 'private',
		'198.18.0.0/15'   => 'benchmarking',
		'198.51.100.0/24' => 'documentation (TEST-NET-2)',
		'203.0.113.0/24'  => 'documentation (TEST-NET-3)',
		'224.0.0.0/4'     => 'multicast',
		'240.0.0.0/4'     => 'reserved, including broadcast',
	);

	/**
	 * Blocked ranges inside IPv6 global unicast (2000::/3) => why.
	 */
	const BLOCKED_V6 = array(
		'2001::/23'     => 'IETF protocol assignments, including Teredo (2001::/32)',
		'2001:db8::/32' => 'documentation',
		'2002::/16'     => '6to4',
		'3fff::/20'     => 'documentation (RFC 9637)',
	);

	/**
	 * An address in packed form, or false for anything else. filter_var() decides first, so inet_pton() cannot warn or, on a
	 * null byte, throw, and the answer does not depend on the C library: some read a zone (`fe80::1%en0`) or leading zeros
	 * (`010.0.0.1`), and PHP before 8 reads up to a null byte.
	 *
	 * @param string $ip IP address, without brackets.
	 * @return string|false
	 */
	public static function packed( string $ip ) {
		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? false : inet_pton( $ip );
	}

	/**
	 * Whether a connection to this address stays on the public internet.
	 *
	 * @param string $ip IP address, without brackets.
	 * @return bool
	 */
	public static function is_public( string $ip ): bool {
		$packed = self::packed( $ip );
		if ( false === $packed ) {
			return false;
		}
		if ( 4 === strlen( $packed ) ) {
			return ! self::in_any( $packed, self::BLOCKED_V4 );
		}
		if ( ! self::in_range( $packed, '2000::/3' ) ) {
			return false;
		}
		return ! self::in_any( $packed, self::BLOCKED_V6 );
	}

	/**
	 * Local-network ranges a development gateway may use (Auth\Gateway\Pinned, local and development environments
	 * only) => why. Never consulted by the media downloader.
	 */
	const LOCAL_NETWORK = array(
		'10.0.0.0/8'     => 'private',
		'100.64.0.0/10'  => 'shared address space (CGNAT, Tailscale)',
		'127.0.0.0/8'    => 'loopback',
		'172.16.0.0/12'  => 'private',
		'192.168.0.0/16' => 'private',
		'::1/128'        => 'loopback',
		'fc00::/7'       => 'unique local',
	);

	/**
	 * Whether an address is on this machine or a private network (LOCAL_NETWORK). Link-local addresses, cloud metadata
	 * among them, are not.
	 *
	 * @param string $ip IP address, without brackets.
	 * @return bool
	 */
	public static function is_local_network( string $ip ): bool {
		$packed = self::packed( $ip );
		return false !== $packed && self::in_any( $packed, self::LOCAL_NETWORK );
	}

	/**
	 * The IP address a URL host spells, or null for a host name. IPv4 must be a canonical dotted quad; IPv6 must be in
	 * brackets and is returned without them, lower case.
	 *
	 * @param string $host URL host.
	 * @return string|null
	 */
	public static function literal( string $host ) {
		if ( 1 === preg_match( '/^\[([0-9a-f:.]+)\]$/Di', $host, $match ) ) {
			$packed = self::packed( $match[1] );
			return false !== $packed && 16 === strlen( $packed ) ? (string) inet_ntop( $packed ) : null;
		}
		return self::is_canonical_ipv4( $host ) ? $host : null;
	}

	/**
	 * Whether a host would be read as an IPv4 address in a form other than a canonical dotted quad (e.g. `2130706433`,
	 * `0x7f.1`, `127.1`, `0177.0.0.1`). HTTP clients and resolvers disagree on those, so they are refused.
	 *
	 * @param string $host URL host.
	 * @return bool
	 */
	public static function is_ambiguous( string $host ): bool {
		$labels = explode( '.', rtrim( $host, '.' ) );
		$last   = (string) end( $labels );
		return 1 === preg_match( '/^(0x[0-9a-f]*|[0-9]+)$/i', $last ) && ! self::is_canonical_ipv4( $host );
	}

	/**
	 * Whether the value is four decimal octets without leading zeros.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_canonical_ipv4( string $value ): bool {
		return 1 === preg_match( '/^(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}$/D', $value );
	}

	/**
	 * Whether a packed address is in one of the ranges.
	 *
	 * @param string                $packed Packed address.
	 * @param array<string, string> $ranges CIDR range => why.
	 * @return bool
	 */
	private static function in_any( string $packed, array $ranges ): bool {
		foreach ( array_keys( $ranges ) as $range ) {
			if ( self::in_range( $packed, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a packed address is in a CIDR range of the same family.
	 *
	 * @param string $packed Packed address.
	 * @param string $range  CIDR range.
	 * @return bool
	 */
	private static function in_range( string $packed, string $range ): bool {
		list( $base, $bits ) = explode( '/', $range );
		$network             = (string) inet_pton( $base );
		if ( strlen( $network ) !== strlen( $packed ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $packed, 0, $bytes ) !== substr( $network, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
		return ( ord( $packed[ $bytes ] ) & $mask ) === ( ord( $network[ $bytes ] ) & $mask );
	}
}
