<?php
/**
 * Removes secrets from data before a tool returns it.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces values stored under secret-looking keys with a mask, at any depth.
 */
final class Redactor {

	/**
	 * What a redacted value becomes.
	 */
	const MASK = '[redacted]';

	/**
	 * Key names that hold secrets: passwords, keys, salts, tokens, hashes, nonces, sessions, credentials, cookies.
	 */
	const SECRET_KEY_PATTERN = '/password|passwd|passphrase|(^|[^a-z])pass([^a-z]|$)|secret|token|(api|private|auth|access|license|secure|logged_in|nonce|encryption|signing|activation)[_-]?key|salt|hash|nonce|session|credential|cookie/i';

	/**
	 * Whether a key name looks like it holds a secret.
	 *
	 * @param string|int $key Key or option name.
	 * @return bool
	 */
	public static function is_secret_key( $key ): bool {
		return is_string( $key ) && 1 === preg_match( self::SECRET_KEY_PATTERN, $key );
	}

	/**
	 * Masks non-empty strings and lists stored under secret-looking keys. Booleans, numbers, null and empty strings are
	 * kept: they reveal nothing.
	 *
	 * @param mixed $data Data to clean.
	 * @return mixed
	 */
	public static function redact( $data ) {
		if ( is_object( $data ) ) {
			$data = get_object_vars( $data );
		}
		if ( ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $data as $key => $value ) {
			if ( self::is_secret_key( $key ) && self::is_revealing( $value ) ) {
				$data[ $key ] = self::MASK;
				continue;
			}
			$data[ $key ] = self::redact( $value );
		}

		return $data;
	}

	/**
	 * A secret-looking name in source text: any name containing password, passwd, passphrase, secret, token, salt,
	 * credential or an api/private/access/auth/license/secure/encryption/signing key (any case), or an upper-case
	 * constant ending in `_KEY`. Lower-case `meta_key` or `cache_key` are ordinary code and are kept.
	 */
	const TEXT_NAME = '(?<![A-Za-z0-9_\-])(?:(?i:[a-z0-9_\-]*(?:password|passwd|passphrase|secret|token|salt|credential|(?:api|private|access|auth|license|secure|encryption|signing)[_\-]?key)[a-z0-9_\-]*)|[A-Z0-9_]*_KEY)(?![A-Za-z0-9_\-])';

	/**
	 * A complete single- or double-quoted string literal, with backslash escapes.
	 */
	const TEXT_QUOTED = '(?:\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")';

	/**
	 * Masks secret values in source text (file content): `define()` of secret-looking constants, quoted values assigned
	 * to secret-looking names (PHP, JS, JSON, YAML), unquoted `NAME=value` / `NAME: value` lines (env, ini, YAML),
	 * private key blocks, credentials in URLs and well-known token shapes.
	 *
	 * @param string $text Text to clean.
	 * @return string|null The cleaned text, or null when a pattern failed (e.g. a PCRE limit): callers must then refuse
	 *                     to return the text rather than return it unredacted.
	 */
	public static function redact_text( string $text ): ?string {
		// Keeps the text before a string literal (group 1) and the quotes around it (group $literal), masking the rest.
		$quoted = static function ( int $literal ): callable {
			return static function ( array $found ) use ( $literal ): string {
				$quote = $found[ $literal ][0];
				return $found[1] . $quote . self::MASK . $quote;
			};
		};

		$rules = array(
			// The value of a define() whose constant name looks secret.
			'/(\bdefine\s*\(\s*([\'"])' . self::TEXT_NAME . '\2\s*,\s*)(' . self::TEXT_QUOTED . ')/' => $quoted( 3 ),
			// A quoted value after a secret-looking name and =, => or : (PHP variables and array keys, JSON, JS, YAML).
			'/((?:[\'"])?' . self::TEXT_NAME . '[\'"]?\s*(?:=>|=|:)\s*)(' . self::TEXT_QUOTED . ')/' => $quoted( 2 ),
			// NAME=value and NAME: value lines (env, ini, YAML), unquoted.
			'/^([ \t]*(?:export[ \t]+)?' . self::TEXT_NAME . '[ \t]*[:=][ \t]*)(?![\'"\s])[^\r\n]+/m' => '$1' . self::MASK,
			'/-----BEGIN [A-Z0-9 ]*PRIVATE KEY(?: BLOCK)?-----.*?(?:-----END [A-Z0-9 ]*PRIVATE KEY(?: BLOCK)?-----|\z)/s' => self::MASK,
			'/\b([a-z][a-z0-9+.\-]*:\/\/)[^\s\/@:]+:[^\s\/@]+@/i' => '$1' . self::MASK . '@',
			'/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}/' => self::MASK,
			'/\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})/' => self::MASK,
			'/\bAKIA[0-9A-Z]{16}\b/'                     => self::MASK,
			'/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/' => self::MASK,
			'/\b(Authorization[ \t]*:[ \t]*)[^\r\n]+/i'  => '$1' . self::MASK,
			'/\b(Bearer[ \t]+)[A-Za-z0-9+\/_.=~\-]{8,}/' => '$1' . self::MASK,
		);

		foreach ( $rules as $pattern => $replacement ) {
			$text = is_string( $replacement ) ? preg_replace( $pattern, $replacement, $text ) : preg_replace_callback( $pattern, $replacement, $text );
			if ( null === $text ) {
				return null;
			}
		}

		return $text;
	}

	/**
	 * Whether a value could reveal a secret.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_revealing( $value ): bool {
		if ( is_string( $value ) ) {
			return '' !== $value;
		}
		return is_array( $value ) || is_object( $value );
	}
}
