<?php
/**
 * A paired gateway connection.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Gateway;

use FlitAgent\Auth\Context;

defined( 'ABSPATH' ) || exit;

/**
 * The site's connection to flitagent.com: its id, the WordPress user who paired it (requests act as that user), the
 * scope granted and a hint of the account it belongs to. The signing keys are not part of it (see KeySet). WordPress-free.
 */
final class Connection {

	/**
	 * Connection ids: `fc_` and 32 lowercase hex digits.
	 */
	const ID_PATTERN = '/^fc_[0-9a-f]{32}\z/';

	/**
	 * Longest account hint kept.
	 */
	const MAX_HINT = 100;

	/**
	 * Connection id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Pairing user.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Scope.
	 *
	 * @var string
	 */
	private $scope;

	/**
	 * Unix time of pairing.
	 *
	 * @var int
	 */
	private $paired_at;

	/**
	 * Account hint (for display only).
	 *
	 * @var string
	 */
	private $account_hint;

	/**
	 * Constructor.
	 *
	 * @param string $id         Connection id.
	 * @param int    $user_id    Pairing user.
	 * @param string $scope      Context::SCOPE_READ or Context::SCOPE_WRITE.
	 * @param int    $paired_at  Unix time of pairing.
	 * @param string $account_hint Account hint; control characters are removed and it is cut to 100 characters.
	 * @throws \InvalidArgumentException For an invalid id, user or scope.
	 */
	public function __construct( string $id, int $user_id, string $scope, int $paired_at, string $account_hint = '' ) {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new \InvalidArgumentException( 'Invalid connection id.' );
		}
		if ( $user_id <= 0 ) {
			throw new \InvalidArgumentException( 'Invalid user.' );
		}
		if ( ! in_array( $scope, array( Context::SCOPE_READ, Context::SCOPE_WRITE ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid scope.' );
		}
		$this->id        = $id;
		$this->user_id   = $user_id;
		$this->scope     = $scope;
		$this->paired_at = $paired_at;

		$hint = preg_replace( '/[\x00-\x1F\x7F]/', '', $account_hint );
		$hint = null === $hint ? '' : $hint;
		// Cut by characters (PCRE, so no mbstring needed); text that is not valid UTF-8 gives no hint.
		$this->account_hint = 1 === preg_match( '/^.{0,' . self::MAX_HINT . '}/us', $hint, $cut ) ? $cut[0] : '';
	}

	/**
	 * Restores a connection from storage, or null when the stored value is not a valid connection.
	 *
	 * @param mixed $data Stored array.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) || ! isset( $data['id'], $data['user_id'], $data['scope'], $data['paired_at'] ) || ! self::is_int_like( $data['user_id'] ) || ! self::is_int_like( $data['paired_at'] ) || array_key_exists( 'public_key', $data ) || ! is_string( $data['id'] ) || ! is_string( $data['scope'] ) ) {
			return null;
		}
		$hint = isset( $data['account_hint'] ) && is_string( $data['account_hint'] ) ? $data['account_hint'] : '';
		try {
			return new self( $data['id'], (int) $data['user_id'], $data['scope'], (int) $data['paired_at'], $hint );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * Whether a stored value is an int or a string of digits.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function is_int_like( $value ): bool {
		return is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^[0-9]+\z/', $value ) );
	}

	/**
	 * For storage.
	 *
	 * @return array{id: string, user_id: int, scope: string, paired_at: int, account_hint: string}
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'user_id'      => $this->user_id,
			'scope'        => $this->scope,
			'paired_at'    => $this->paired_at,
			'account_hint' => $this->account_hint,
		);
	}

	/**
	 * Connection id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Pairing user.
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	/**
	 * Scope.
	 */
	public function scope(): string {
		return $this->scope;
	}

	/**
	 * Unix time of pairing.
	 */
	public function paired_at(): int {
		return $this->paired_at;
	}

	/**
	 * Account hint, for display.
	 */
	public function account_hint(): string {
		return $this->account_hint;
	}
}
