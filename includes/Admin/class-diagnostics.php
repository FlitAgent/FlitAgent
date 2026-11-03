<?php
/**
 * Connection diagnostics: the checks behind "Test connection".
 *
 * @package FlitAgent
 */

namespace FlitAgent\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the host problems that break MCP connections. Pure: it is given the site's URLs and an HTTP function, so every
 * pass, warning and fail path runs in unit tests with fake responses; DiagnosticsScreen feeds it from WordPress.
 *
 * Each result is `array( 'check' => id, 'label' => ..., 'status' => pass|warning|fail|skipped, 'message' => ..., 'fix' => ... )`.
 */
final class Diagnostics {

	const PASS    = 'pass';
	const WARNING = 'warning';
	const FAIL    = 'fail';
	const SKIPPED = 'skipped';

	/**
	 * JSON-RPC id of the diagnostics initialize request.
	 */
	const RPC_ID = 'flitagent-diagnostics';

	/**
	 * Site facts: `home`, `mcp`, `probe` (URLs), `pretty_permalinks` (bool), `gateway` (the gateway URL, '' when invalid).
	 *
	 * @var array<string, mixed>
	 */
	private $site;

	/**
	 * HTTP function: ( string $method, string $url, array $headers, string $body ) returning
	 * `array( 'code' => int, 'type' => string, 'body' => string )`, or a string describing why the request failed.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Seconds all of a run's loopbacks may take together (#148). Each check sends its own requests, so one blocked path
	 * never hides another check's result; once the budget is spent, later requests are not sent and their checks say so.
	 * The worst case is the budget plus one request's timeout.
	 */
	const BUDGET = 12;

	/**
	 * Clock: returns seconds as a float (microtime( true ) by default; tests pass their own).
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * When the current run started, by the clock.
	 *
	 * @var float
	 */
	private $started = 0.0;

	/**
	 * Whether the last request was not sent because the budget was spent.
	 *
	 * @var bool
	 */
	private $out_of_time = false;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $site  Site facts.
	 * @param callable             $http  HTTP function.
	 * @param callable|null        $clock Clock, or null for microtime( true ).
	 */
	public function __construct( array $site, callable $http, ?callable $clock = null ) {
		$this->clock = $clock ?? static function (): float {
			return microtime( true );
		};
		$this->site  = $site + array(
			'home'              => '',
			'mcp'               => '',
			'probe'             => '',
			'pretty_permalinks' => false,
			'gateway'           => '',
		);
		$this->http  = $http;
	}

	/**
	 * Runs every check.
	 *
	 * @param string $token Per-run token for the custom header check.
	 * @return array<int, array<string, string>>
	 */
	public function run( string $token ): array {
		$this->started = (float) call_user_func( $this->clock );
		return array(
			$this->https(),
			$this->permalinks(),
			$this->mcp(),
			$this->headers( $token ),
		);
	}

	/**
	 * DIAG-2: the home and MCP URLs use HTTPS.
	 *
	 * @return array<string, string>
	 */
	private function https(): array {
		$label = __( 'HTTPS', 'flitagent' );
		$home  = (string) $this->site['home'];
		$mcp   = (string) $this->site['mcp'];

		// flitagent.com must reach the site over the internet, so a local or private site cannot connect, over HTTPS or not.
		if ( ConnectScreen::is_local( $home, $mcp, (string) $this->site['gateway'] ) ) {
			return self::result( 'https', $label, self::WARNING, __( 'This is a local or private site. flitagent.com must reach the site over the internet, so local and private sites cannot connect.', 'flitagent' ), __( 'Connect from a site on a public https:// address, such as your live site or a staging site.', 'flitagent' ) );
		}

		if ( 'https' === self::scheme( $home ) && 'https' === self::scheme( $mcp ) ) {
			return self::result( 'https', $label, self::PASS, __( 'The site and its MCP URL use HTTPS.', 'flitagent' ) );
		}

		return self::result( 'https', $label, self::FAIL, __( 'The site or its MCP URL uses HTTP. flitagent.com connects only to a public HTTPS address.', 'flitagent' ), __( 'Install a TLS certificate, then change the WordPress Address and Site Address in Settings → General to https://.', 'flitagent' ) );
	}

	/**
	 * DIAG-3: pretty permalinks, which the /wp-json/ MCP URL needs.
	 *
	 * @return array<string, string>
	 */
	private function permalinks(): array {
		$label = __( 'Permalinks', 'flitagent' );
		if ( $this->site['pretty_permalinks'] ) {
			return self::result( 'permalinks', $label, self::PASS, __( 'Pretty permalinks are on, so the /wp-json/ MCP URL works.', 'flitagent' ) );
		}
		// A warning, not a failure (#149): with Plain permalinks the address apps are given uses ?rest_route=, which works,
		// but some hosts handle it badly.
		return self::result( 'permalinks', $label, self::WARNING, __( 'Permalinks are set to Plain, so the connect address uses ?rest_route= instead of /wp-json/. It works, but some hosts handle it badly.', 'flitagent' ), __( 'In Settings → Permalinks, choose any structure other than Plain and save.', 'flitagent' ) );
	}

	/**
	 * DIAG-3: a loopback POST `initialize` to the MCP route returns JSON-RPC with a JSON content type. Catches
	 * firewalls, caches and HTML error pages answering in FlitAgent's place.
	 *
	 * @return array<string, string>
	 */
	private function mcp(): array {
		$label    = __( 'MCP endpoint', 'flitagent' );
		$response = $this->request(
			'POST',
			(string) $this->site['mcp'],
			array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json, text/event-stream',
			),
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => self::RPC_ID,
					'method'  => 'initialize',
					'params'  => array(
						'protocolVersion' => '2025-11-25',
						'capabilities'    => new \stdClass(),
						'clientInfo'      => array(
							'name'    => 'flitagent-diagnostics',
							'version' => '1',
						),
					),
				)
			)
		);
		if ( is_string( $response ) ) {
			return $this->not_run( 'mcp', $label, $response );
		}

		$fix = __( 'Exclude /wp-json/flitagent/ (or ?rest_route=/flitagent/) from page caching, and allow POST requests to it in your firewall or security plugin. A 404 usually means the rewrite rules need saving in Settings → Permalinks.', 'flitagent' );
		// The probe is deliberately unsigned: FlitAgent refuses it with its own generic 401.
		if ( 401 !== $response['code'] ) {
			/* translators: %d: HTTP status code. */
			return self::result( 'mcp', $label, self::FAIL, sprintf( __( 'The MCP route answered HTTP %d to a request flitagent.com did not sign, instead of FlitAgent\'s 401, so something other than FlitAgent is handling it.', 'flitagent' ), $response['code'] ), $fix );
		}
		if ( ! self::is_json_type( $response['type'] ) ) {
			$type = '' === $response['type'] ? __( 'no content type', 'flitagent' ) : $response['type'];
			/* translators: %s: content type, such as text/html. */
			return self::result( 'mcp', $label, self::FAIL, sprintf( __( 'The MCP route answered with %s instead of application/json: a cache, firewall or error page is rewriting the response.', 'flitagent' ), $type ), $fix );
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) || 'flitagent_unauthorized' !== ( $data['code'] ?? null ) ) {
			return self::result( 'mcp', $label, self::FAIL, __( 'The MCP route answered 401, but not with FlitAgent\'s own JSON: a cache, firewall or another plugin is answering instead of FlitAgent.', 'flitagent' ), $fix );
		}

		return self::result( 'mcp', $label, self::PASS, __( 'The MCP route answers with FlitAgent\'s own JSON and refuses requests that flitagent.com did not sign.', 'flitagent' ) );
	}

	/**
	 * A loopback request with this run's `FlitAgent-Diagnostics: <token>` header reaches PHP. flitagent.com sends its
	 * own custom FlitAgent-... headers, so a server that strips them breaks the connection. The diagnostics route
	 * answers only whether the header arrived.
	 *
	 * @param string $token Per-run token.
	 * @return array<string, string>
	 */
	private function headers( string $token ): array {
		$label    = __( 'Custom headers', 'flitagent' );
		$response = $this->request(
			'POST',
			(string) $this->site['probe'],
			array(
				DiagnosticsRoute::HEADER => $token,
				'Content-Type'           => 'application/json',
				'Accept'                 => 'application/json',
			),
			(string) wp_json_encode( array( 'token' => $token ) )
		);
		if ( is_string( $response ) ) {
			return $this->not_run( 'headers', $label, $response );
		}

		$data    = json_decode( $response['body'], true );
		$arrived = is_array( $data ) && array_key_exists( 'headers', $data ) ? $data['headers'] : null;
		if ( 200 !== $response['code'] || ! self::is_json_type( $response['type'] ) || ! is_bool( $arrived ) ) {
			/* translators: %d: HTTP status code. */
			return self::unchecked( self::result( 'headers', $label, self::WARNING, sprintf( __( 'Could not check: the diagnostics route answered HTTP %d without a usable result, so a firewall, cache or another plugin may be handling /wp-json/flitagent/.', 'flitagent' ), $response['code'] ), __( 'Fix the MCP endpoint check first, then test again.', 'flitagent' ) ) );
		}

		if ( $arrived ) {
			return self::result( 'headers', $label, self::PASS, __( 'A custom FlitAgent-Diagnostics header reached WordPress, so the FlitAgent-... headers flitagent.com sends can too.', 'flitagent' ) );
		}

		return self::result(
			'headers',
			$label,
			self::FAIL,
			__( 'The web server removed a custom FlitAgent-... header before it reached WordPress. flitagent.com then cannot reach the tools on this site.', 'flitagent' ),
			__( 'Apache: make sure mod_security or another module does not drop custom request headers. Nginx with PHP-FPM: remove any "proxy_set_header" or "fastcgi_param" rule that clears them. On managed hosting, ask your host to pass custom FlitAgent-... request headers to PHP.', 'flitagent' )
		);
	}

	/**
	 * `scheme://host[:port]` of a URL.
	 *
	 * @param string $url URL.
	 */
	private static function origin( string $url ): string {
		$scheme = self::scheme( $url );
		$port   = wp_parse_url( $url, PHP_URL_PORT );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$port   = is_int( $port ) && ! ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) ? ':' . $port : '';
		return $scheme . '://' . ( is_string( $host ) ? strtolower( $host ) : '' ) . $port;
	}

	/**
	 * Sends one loopback request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Headers.
	 * @param string                $body    Body.
	 * @return array{code: int, type: string, body: string}|string Response, or why it could not be made.
	 */
	private function request( string $method, string $url, array $headers, string $body = '' ) {
		$home = (string) $this->site['home'];
		if ( '' === $home || self::origin( $url ) !== self::origin( $home ) || null !== wp_parse_url( $url, PHP_URL_USER ) ) {
			/* translators: 1: URL that was not requested, 2: home URL. */
			return sprintf( __( '%1$s is not on the site\'s home URL %2$s, so it was not requested', 'flitagent' ), $url, $home );
		}

		$this->out_of_time = false;
		if ( (float) call_user_func( $this->clock ) - $this->started >= self::BUDGET ) {
			$this->out_of_time = true;
			/* translators: %d: seconds. */
			return sprintf( __( 'the checks ran out of time (%d seconds in all), so this request was not sent', 'flitagent' ), self::BUDGET );
		}

		try {
			$response = call_user_func( $this->http, $method, $url, $headers, $body );
		} catch ( \Throwable $e ) {
			$response = $e->getMessage();
		}
		if ( ! is_array( $response ) ) {
			$reason = is_string( $response ) && '' !== $response ? $response : __( 'unknown error', 'flitagent' );
			/* translators: %s: error message. */
			return sprintf( __( 'the site could not reach itself: %s', 'flitagent' ), $reason );
		}
		return array(
			'code' => (int) ( $response['code'] ?? 0 ),
			'type' => (string) ( $response['type'] ?? '' ),
			'body' => (string) ( $response['body'] ?? '' ),
		);
	}

	/**
	 * A check whose loopback request could not be made: a warning, pointing at Site Health.
	 *
	 * @param string $check  Check id.
	 * @param string $label  Label.
	 * @param string $reason Why the request was not made or failed.
	 * @return array<string, string>
	 */
	private function not_run( string $check, string $label, string $reason ): array {
		$fix = $this->out_of_time
			? __( 'The site answered slowly. Check again; if it keeps running out of time, ask your host why requests from the site to itself are slow.', 'flitagent' )
			: __( 'Run Tools → Site Health: its loopback request and REST API tests show why the server cannot reach its own URL.', 'flitagent' );
		/* translators: %s: error message. */
		return self::unchecked( self::result( $check, $label, self::WARNING, sprintf( __( 'Could not check: %s.', 'flitagent' ), $reason ), $fix ) );
	}

	/**
	 * Marks a result as a check that could not tell (`unchecked`: '1'), so the setup guide never reads it as ready.
	 *
	 * @param array<string, string> $result Result.
	 * @return array<string, string>
	 */
	private static function unchecked( array $result ): array {
		$result['unchecked'] = '1';
		return $result;
	}

	/**
	 * Whether a Content-Type header is JSON.
	 *
	 * @param string $type Content-Type.
	 */
	private static function is_json_type( string $type ): bool {
		$media = strtolower( trim( explode( ';', $type )[0] ) );
		return 'application/json' === $media || '+json' === substr( $media, -5 );
	}

	/**
	 * Lower-case scheme of a URL.
	 *
	 * @param string $url URL.
	 */
	private static function scheme( string $url ): string {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		return is_string( $scheme ) ? strtolower( $scheme ) : '';
	}

	/**
	 * Builds one result.
	 *
	 * @param string $check   Check id.
	 * @param string $label   Label.
	 * @param string $status  pass, warning, fail or skipped.
	 * @param string $message What was found.
	 * @param string $fix     One-line fix, empty when nothing needs fixing.
	 * @return array<string, string>
	 */
	private static function result( string $check, string $label, string $status, string $message, string $fix = '' ): array {
		return array(
			'check'   => $check,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
			'fix'     => $fix,
		);
	}
}
