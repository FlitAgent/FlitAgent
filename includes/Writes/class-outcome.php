<?php
/**
 * What a write did.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of WriteTool::apply() and verify(): the result for the model, the rollback reference (a Rollback
 * array, stored in the receipt and never returned over MCP), the fingerprint of the state after the write (compared
 * before a rollback), warnings, and whether verification failed.
 */
final class Outcome {

	/**
	 * Result for the model (ids, flags). No secrets.
	 *
	 * @var array<string, mixed>
	 */
	private $result;

	/**
	 * Rollback reference (see Rollback).
	 *
	 * @var array<string, mixed>
	 */
	private $rollback;

	/**
	 * State fingerprint after the write: `array( 'fingerprint' => string )`.
	 *
	 * @var array<string, mixed>
	 */
	private $after;

	/**
	 * Warnings: list of `array( 'code' => string, 'message' => string )`.
	 *
	 * @var array<int, array{code: string, message: string}>
	 */
	private $warnings = array();

	/**
	 * Verification failure message, or null.
	 *
	 * @var string|null
	 */
	private $verification_failure;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $result   Result for the model.
	 * @param array<string, mixed> $rollback Rollback reference from Rollback::make() or Rollback::none().
	 * @param array<string, mixed> $after    State fingerprint after the write, `array( 'fingerprint' => string )`.
	 */
	public function __construct( array $result, array $rollback, array $after = array() ) {
		$this->result   = $result;
		$this->rollback = $rollback;
		$this->after    = $after;
	}

	/**
	 * A copy with extra result fields.
	 *
	 * @param array<string, mixed> $fields Fields to add or replace.
	 * @return self
	 */
	public function with_result( array $fields ): self {
		$copy         = clone $this;
		$copy->result = array_merge( $this->result, $fields );
		return $copy;
	}

	/**
	 * A copy with a warning (the write happened, but something is off).
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human message.
	 * @return self
	 */
	public function with_warning( string $code, string $message ): self {
		$copy             = clone $this;
		$copy->warnings[] = array(
			'code'    => $code,
			'message' => $message,
		);
		return $copy;
	}

	/**
	 * A copy marked as failing verification (the read-back could not confirm the write).
	 *
	 * @param string $message Human message.
	 * @return self
	 */
	public function with_verification_failure( string $message ): self {
		$copy                       = $this->with_warning( 'verification_failed', $message );
		$copy->verification_failure = $message;
		return $copy;
	}

	/**
	 * Result for the model.
	 *
	 * @return array<string, mixed>
	 */
	public function result(): array {
		return $this->result;
	}

	/**
	 * Rollback reference.
	 *
	 * @return array<string, mixed>
	 */
	public function rollback(): array {
		return $this->rollback;
	}

	/**
	 * State fingerprint after the write.
	 *
	 * @return array<string, mixed>
	 */
	public function after(): array {
		return $this->after;
	}

	/**
	 * Warnings.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Whether verification failed.
	 */
	public function verification_failed(): bool {
		return null !== $this->verification_failure;
	}
}
