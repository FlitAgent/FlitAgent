<?php
/**
 * Tells flitagent.com that something changed on the site.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * The nudge: one unsigned `POST {origin}/wp-json/flitclient/v1/nudge` whose JSON body is only the connection id
 * (`{"connection":"fc_…"}`), sent when an administrator disconnects or sets up an AI tool, disconnects the site, or
 * deletes the plugin. flitagent.com answers 202 and then asks the site, with a signed request, what changed; the nudge
 * itself proves nothing, carries no site address or user data, and its answer is never shown or retried.
 *
 * The request is KeyFetcher's (Pinned: SSRF-checked and pinned, no redirects), capped at TIMEOUT seconds and MAX_BYTES.
 * send() never throws: any failure is false.
 */
final class Nudge {

	/**
	 * The nudge route on the gateway's origin.
	 */
	const PATH = '/wp-json/flitclient/v1/nudge';

	/**
	 * Request timeout, in seconds.
	 */
	const TIMEOUT = 2;

	/**
	 * Largest answer read, in bytes (the answer is ignored).
	 */
	const MAX_BYTES = 1024;

	/**
	 * Gateway URL.
	 *
	 * @var string
	 */
	private $gateway;

	/**
	 * Fn( string $method, string $url, string[] $pins, bool $safe, array $args ): array|\WP_Error.
	 *
	 * @var callable
	 */
	private $request;

	/**
	 * Constructor.
	 *
	 * @param string        $gateway Gateway URL (Config::url()).
	 * @param callable|null $request Pinned request; defaults to Pinned::request().
	 */
	public function __construct( string $gateway, ?callable $request = null ) {
		$this->gateway = $gateway;
		$this->request = $request ?? array( Pinned::class, 'request' );
	}

	/**
	 * The nudge URL of a gateway: its origin and PATH, or '' when the gateway URL has no origin.
	 *
	 * @param string $gateway Gateway URL.
	 * @return string
	 */
	public static function url( string $gateway ): string {
		$origin = Config::origin( $gateway );
		return '' === $origin ? '' : $origin . self::PATH;
	}

	/**
	 * Sends the nudge for a connection.
	 *
	 * @param string $connection_id Connection id (`fc_` and 32 lowercase hex digits).
	 * @return bool True only when flitagent.com answered 2xx; false for any other answer, an error, a refusal or a skip.
	 */
	public function send( string $connection_id ): bool {
		if ( 1 !== preg_match( Connection::ID_PATTERN, $connection_id ) ) {
			return false;
		}
		$gateway = Config::normalize( $this->gateway );
		$url     = '' === $gateway ? '' : self::url( $gateway );
		if ( '' === $url ) {
			return false;
		}
		try {
			$target   = Pinned::target( $url );
			$response = call_user_func(
				$this->request,
				'POST',
				$target['url'],
				$target['pins'],
				$target['safe'],
				array(
					'timeout'             => self::TIMEOUT,
					'limit_response_size' => self::MAX_BYTES + 1,
					'headers'             => array( 'Content-Type' => 'application/json' ),
					'user-agent'          => self::user_agent(),
					'body'                => (string) wp_json_encode( array( 'connection' => $connection_id ) ),
				)
			);
		} catch ( \Throwable $e ) {
			// Refused, unresolvable or not pinned: the nudge is best effort and never stops the administrator's action.
			return false;
		}
		if ( ! is_array( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * The nudge's User-Agent: `FlitAgent/{version} (nudge)`. The version is FLITAGENT_VERSION, or the `Version` header of
	 * flitagent.php when the constant is not defined (an uninstall that did not load the plugin).
	 *
	 * @return string
	 */
	public static function user_agent(): string {
		$version = defined( 'FLITAGENT_VERSION' ) ? constant( 'FLITAGENT_VERSION' ) : null;
		if ( ! is_string( $version ) || '' === $version ) {
			$header  = get_file_data( dirname( __DIR__, 3 ) . '/flitagent.php', array( 'version' => 'Version' ) );
			$version = is_array( $header ) && isset( $header['version'] ) ? (string) $header['version'] : '';
		}
		return 'FlitAgent/' . $version . ' (nudge)';
	}
}
