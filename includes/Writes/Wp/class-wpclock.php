<?php
/**
 * System clock.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Wp;

use FlitAgent\Writes\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Unix time.
 */
final class WpClock implements Clock {

	/**
	 * Current Unix time.
	 */
	public function now(): int {
		return time();
	}
}
