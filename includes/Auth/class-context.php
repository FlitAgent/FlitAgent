<?php
/**
 * How the current request authenticated.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * The credential behind the current MCP request: its method (a request signed by flitagent.com, or none), its scope
 * (read or write) and the AI app it belongs to. The gateway authenticator sets it once it accepts a request;
 * everything else reads it. Without one the request has no credential: read scope and no client, so nothing can
 * write. WordPress-free.
 */
final class Context {

	const NONE    = 'none';
	const GATEWAY = 'gateway';

	const SCOPE_READ  = 'read';
	const SCOPE_WRITE = 'write';

	/**
	 * The context of the current request, or null for the default.
	 *
	 * @var Context|null
	 */
	private static $current;

	/**
	 * Authentication method.
	 *
	 * @var string
	 */
	private $method;

	/**
	 * Scope.
	 *
	 * @var string
	 */
	private $scope;

	/**
	 * Client id, e.g. `fa:` and 40 hex characters of the signed request's app.
	 *
	 * @var string
	 */
	private $client_id;

	/**
	 * Human-readable client name.
	 *
	 * @var string
	 */
	private $client_name;

	/**
	 * Constructor.
	 *
	 * @param string $method      One of the method constants.
	 * @param string $scope       One of the scope constants.
	 * @param string $client_id   Client id.
	 * @param string $client_name Client name.
	 * @throws \InvalidArgumentException For an unknown method or scope.
	 */
	public function __construct( string $method, string $scope, string $client_id = '', string $client_name = '' ) {
		if ( ! in_array( $method, array( self::NONE, self::GATEWAY ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown authentication method.' );
		}
		if ( ! in_array( $scope, array( self::SCOPE_READ, self::SCOPE_WRITE ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown scope.' );
		}
		$this->method      = $method;
		$this->scope       = $scope;
		$this->client_id   = $client_id;
		$this->client_name = $client_name;
	}

	/**
	 * Sets the context of the current request.
	 *
	 * @param Context $context Context.
	 */
	public static function set( Context $context ): void {
		self::$current = $context;
	}

	/**
	 * The context of the current request.
	 */
	public static function current(): Context {
		return null === self::$current ? new self( self::NONE, self::SCOPE_READ ) : self::$current;
	}

	/**
	 * Back to the default (end of request, tests).
	 */
	public static function reset(): void {
		self::$current = null;
	}

	/**
	 * Authentication method.
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * Scope.
	 */
	public function scope(): string {
		return $this->scope;
	}

	/**
	 * Whether the credential may write.
	 */
	public function allows_writes(): bool {
		return self::SCOPE_WRITE === $this->scope;
	}

	/**
	 * The access AI tools get on a site: write only while its Access for AI tools is Read and propose changes (writes
	 * on) and the scope (the connection's, or an app's) is write; read otherwise.
	 *
	 * @param bool   $writes_enabled Whether writes are on (WpPolicy::writes_enabled()).
	 * @param string $scope          A scope constant.
	 * @return string SCOPE_READ or SCOPE_WRITE.
	 */
	public static function access( bool $writes_enabled, string $scope ): string {
		return $writes_enabled && self::SCOPE_WRITE === $scope ? self::SCOPE_WRITE : self::SCOPE_READ;
	}

	/**
	 * Client id.
	 */
	public function client_id(): string {
		return $this->client_id;
	}

	/**
	 * Client name.
	 */
	public function client_name(): string {
		return $this->client_name;
	}
}
