<?php
/**
 * A verified gateway request.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

use FlitAgent\Auth\Context;

defined( 'ABSPATH' ) || exit;

/**
 * The connection a request was signed for, the AI app that made it and when it was signed. WordPress-free.
 */
final class Verified {

	/**
	 * Connection.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * The request's signed timestamp (`FlitAgent-Timestamp`), 0 when unknown.
	 *
	 * @var int
	 */
	private $signed_at;

	/**
	 * Constructor.
	 *
	 * @param Connection $connection Connection.
	 * @param Client     $client     Client.
	 * @param int        $signed_at  The request's signed timestamp (0 when unknown).
	 */
	public function __construct( Connection $connection, Client $client, int $signed_at = 0 ) {
		$this->connection = $connection;
		$this->client     = $client;
		$this->signed_at  = $signed_at;
	}

	/**
	 * When flitagent.com signed the request (Unix time), 0 when unknown.
	 */
	public function signed_at(): int {
		return $this->signed_at;
	}

	/**
	 * Connection.
	 */
	public function connection(): Connection {
		return $this->connection;
	}

	/**
	 * Client.
	 */
	public function client(): Client {
		return $this->client;
	}

	/**
	 * The effective scope: write only when both the connection and the app have it.
	 */
	public function scope(): string {
		return Context::SCOPE_WRITE === $this->connection->scope() && Context::SCOPE_WRITE === $this->client->scope() ? Context::SCOPE_WRITE : Context::SCOPE_READ;
	}

	/**
	 * The authentication context of the request: its client id is bound to the connection (Client::client_id()). A request
	 * from flitagent.com itself names no app, so it has none, and the write gateway refuses it.
	 */
	public function context(): Context {
		$client_id = Apps::is_relay( $this->client ) ? '' : $this->client->client_id( $this->connection->id() );
		return new Context( Context::GATEWAY, $this->scope(), $client_id, $this->client->client_name() );
	}
}
