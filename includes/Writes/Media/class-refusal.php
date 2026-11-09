<?php
/**
 * Why a media download was refused.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Media;

use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Download with a short machine code (`blocked_url`, `unsupported_type`, `too_large`, `download_failed`) and
 * a message safe to show the model.
 */
final class Refusal extends \RuntimeException {

	/**
	 * Machine code.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Constructor.
	 *
	 * @param string $reason  Machine code.
	 * @param string $message Message.
	 */
	public function __construct( string $reason, string $message ) {
		parent::__construct( $message );
		$this->reason = $reason;
	}

	/**
	 * Machine code.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * `code: message`, as tool errors and refusals show it.
	 */
	public function describe(): string {
		return $this->reason . ': ' . ToolError::plain( $this );
	}
}
