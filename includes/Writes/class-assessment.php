<?php
/**
 * What a write would do, and how risky it is.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of WriteTool::assess(): tier, auto-approvability, a one-sentence summary, a preview (before/after),
 * a preconditions fingerprint (compared again at apply time), and a refusal when the write is not allowed.
 *
 * Invariants: only a reversible assessment can be auto-approvable; a prohibited assessment always has a refusal.
 */
final class Assessment {

	/**
	 * Tier (a Risk constant).
	 *
	 * @var string
	 */
	private $tier;

	/**
	 * Whether policy may approve it without a human.
	 *
	 * @var bool
	 */
	private $auto_approvable;

	/**
	 * One human sentence.
	 *
	 * @var string
	 */
	private $summary;

	/**
	 * Before/after or diff data for the approver.
	 *
	 * @var array<string, mixed>
	 */
	private $preview;

	/**
	 * Fingerprint of the state the write depends on.
	 *
	 * @var array<string, mixed>
	 */
	private $preconditions;

	/**
	 * Code and message when refused.
	 *
	 * @var array{code: string, message: string}|null
	 */
	private $refusal;

	/**
	 * Constructor.
	 *
	 * @param string                                    $tier            Tier.
	 * @param bool                                      $auto_approvable Auto-approvable.
	 * @param string                                    $summary         Summary.
	 * @param array<string, mixed>                      $preview         Preview.
	 * @param array<string, mixed>                      $preconditions   Preconditions.
	 * @param array{code: string, message: string}|null $refusal         Refusal.
	 */
	private function __construct( string $tier, bool $auto_approvable, string $summary, array $preview, array $preconditions, ?array $refusal ) {
		$this->tier            = $tier;
		$this->auto_approvable = Risk::REVERSIBLE === $tier && $auto_approvable;
		$this->summary         = $summary;
		$this->preview         = $preview;
		$this->preconditions   = $preconditions;
		$this->refusal         = $refusal;
	}

	/**
	 * A write that can be rolled back.
	 *
	 * @param string               $summary         One human sentence.
	 * @param array<string, mixed> $preview         Before/after data.
	 * @param array<string, mixed> $preconditions   Fingerprint of the state the write depends on.
	 * @param bool                 $auto_approvable Whether policy may approve it (e.g. the target is not public).
	 * @return self
	 */
	public static function reversible( string $summary, array $preview, array $preconditions, bool $auto_approvable ): self {
		return new self( Risk::REVERSIBLE, $auto_approvable, $summary, $preview, $preconditions, null );
	}

	/**
	 * A write that needs a human approval every time.
	 *
	 * @param string               $summary       One human sentence.
	 * @param array<string, mixed> $preview       Before/after data.
	 * @param array<string, mixed> $preconditions Fingerprint of the state the write depends on.
	 * @return self
	 */
	public static function destructive( string $summary, array $preview, array $preconditions ): self {
		return new self( Risk::DESTRUCTIVE, false, $summary, $preview, $preconditions, null );
	}

	/**
	 * A refused write: not allowed, not authorized, or not supported.
	 *
	 * @param string $code    Short machine code, e.g. `forbidden`.
	 * @param string $message Reason the model can act on. Never internal details.
	 * @return self
	 */
	public static function prohibited( string $code, string $message ): self {
		return new self(
			Risk::PROHIBITED,
			false,
			$message,
			array(),
			array(),
			array(
				'code'    => $code,
				'message' => $message,
			)
		);
	}

	/**
	 * The same assessment with at least the given tier. Raising the tier above reversible removes auto-approval; a tier
	 * is never lowered.
	 *
	 * @param string $tier Minimum tier.
	 * @return self
	 */
	public function at_least( string $tier ): self {
		if ( Risk::rank( $tier ) <= Risk::rank( $this->tier ) ) {
			return $this;
		}
		if ( Risk::PROHIBITED === $tier ) {
			return self::prohibited( 'prohibited', $this->summary );
		}
		return new self( $tier, false, $this->summary, $this->preview, $this->preconditions, null );
	}

	/**
	 * The same assessment, tier unchanged, that policy may not approve: a person must.
	 *
	 * @return self
	 */
	public function needing_a_person(): self {
		return new self( $this->tier, false, $this->summary, $this->preview, $this->preconditions, $this->refusal );
	}

	/**
	 * The same assessment with another summary.
	 *
	 * @param string $summary Summary.
	 * @return self
	 */
	public function with_summary( string $summary ): self {
		return new self( $this->tier, $this->auto_approvable, $summary, $this->preview, $this->preconditions, $this->refusal );
	}

	/**
	 * Tier (a Risk constant).
	 */
	public function tier(): string {
		return $this->tier;
	}

	/**
	 * Whether policy may approve it without a human. Always false unless reversible.
	 */
	public function auto_approvable(): bool {
		return $this->auto_approvable;
	}

	/**
	 * One human sentence.
	 */
	public function summary(): string {
		return $this->summary;
	}

	/**
	 * Before/after data.
	 *
	 * @return array<string, mixed>
	 */
	public function preview(): array {
		return $this->preview;
	}

	/**
	 * Fingerprint of the state the write depends on.
	 *
	 * @return array<string, mixed>
	 */
	public function preconditions(): array {
		return $this->preconditions;
	}

	/**
	 * Refusal code and message, or null.
	 *
	 * @return array{code: string, message: string}|null
	 */
	public function refusal(): ?array {
		return $this->refusal;
	}

	/**
	 * Whether the write is refused.
	 */
	public function is_refused(): bool {
		return null !== $this->refusal;
	}
}
