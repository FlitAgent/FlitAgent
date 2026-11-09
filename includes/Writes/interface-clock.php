<?php
/**
 * Clock port.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes;

defined( 'ABSPATH' ) || exit;

/**
 * Current time for Gateway.
 */
interface Clock {

	/**
	 * Current Unix time.
	 */
	public function now(): int;
}
