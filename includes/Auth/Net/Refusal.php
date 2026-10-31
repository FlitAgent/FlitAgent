<?php
/**
 * Why a URL may not be fetched.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Auth\Net;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Target with a message safe to show an administrator.
 */
final class Refusal extends \RuntimeException {}
