<?php
/**
 * Ed25519 signing strings and verification for the gateway.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * The exact bytes a gateway signs, and the signature check. WordPress-free.
 *
 * A request signature covers the newline-joined `FLITAGENT-REQUEST-V2`, the method (upper case), the path (`/flitagent/v1/mcp`),
 * the lower-case hex SHA-256 of the raw body, the timestamp, the nonce, the connection id, the key id and the lower-case
 * hex SHA-256 of the `FlitAgent-Client` value as sent. A pairing result signature covers `FLITAGENT-PAIR-V1` and a newline followed by the result's JSON bytes.
 * Keys and signatures travel as base64url without padding.
 *
 * Verification calls the global `sodium_crypto_sign_verify_detached()`: ext-sodium, or the sodium_compat polyfill that
 * WordPress core loads when the extension is missing (PHP 7.4). Without either, every signature fails.
 */
final class Signature {

	const REQUEST_PREFIX = 'FLITAGENT-REQUEST-V2';
	const PAIRING_PREFIX = "FLITAGENT-PAIR-V1\n";

	/**
	 * The signed message of a request.
	 *
	 * @param string $method     HTTP method.
	 * @param string $path       REST route as received.
	 * @param string $body       Raw body.
	 * @param string $timestamp  Timestamp header, as sent.
	 * @param string $nonce      Nonce header.
	 * @param string $connection Connection header.
	 * @param string $kid        Key id header.
	 * @param string $client     Client header, as sent.
	 * @return string
	 */
	public static function request_message( string $method, string $path, string $body, string $timestamp, string $nonce, string $connection, string $kid, string $client ): string {
		return implode( "\n", array( self::REQUEST_PREFIX, strtoupper( $method ), $path, hash( 'sha256', $body ), $timestamp, $nonce, $connection, $kid, hash( 'sha256', $client ) ) );
	}

	/**
	 * The signed message of a pairing result.
	 *
	 * @param string $json Pairing result JSON, as sent.
	 * @return string
	 */
	public static function pairing_message( string $json ): string {
		return self::PAIRING_PREFIX . $json;
	}

	/**
	 * Whether the signature is a valid Ed25519 signature of the message by the key. Never throws.
	 *
	 * @param string $message    Message.
	 * @param string $signature  Raw 64-byte signature.
	 * @param string $public_key Raw 32-byte public key.
	 * @return bool
	 */
	public static function verify( string $message, string $signature, string $public_key ): bool {
		if ( 64 !== strlen( $signature ) || 32 !== strlen( $public_key ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		try {
			return true === sodium_crypto_sign_verify_detached( $signature, $message, $public_key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $raw Bytes.
	 * @return string
	 */
	public static function encode( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodes strict, canonical base64url without padding; '' for anything else.
	 *
	 * @param string $encoded Encoded value.
	 * @return string
	 */
	public static function decode( string $encoded ): string {
		if ( '' === $encoded || 1 !== preg_match( '/^[A-Za-z0-9_-]+\z/', $encoded ) || 1 === strlen( $encoded ) % 4 ) {
			return '';
		}
		$raw = base64_decode( strtr( $encoded, '-_', '+/' ), true );
		return false === $raw || self::encode( $raw ) !== $encoded ? '' : $raw;
	}
}
