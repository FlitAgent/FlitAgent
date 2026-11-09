<?php
/**
 * WordPress.org directory failure.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Themes;

defined( 'ABSPATH' ) || exit;

/**
 * The theme is not in the WordPress.org directory, or the directory could not be read. The message is safe to return.
 */
final class DirectoryError extends \RuntimeException {
}
