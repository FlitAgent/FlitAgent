<?php
/**
 * PSR-4 autoloader for the FlitAgent namespace.
 *
 * @package FlitAgent
 */

namespace FlitAgent;

defined( 'ABSPATH' ) || exit;

/**
 * Maps FlitAgent\Foo\Bar to includes/Foo/Bar.php.
 */
final class Autoloader {

	/**
	 * Whether the autoloader is registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Registers the autoloader once.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		spl_autoload_register(
			static function ( string $class_name ): void {
				$path = self::path_for( $class_name );

				if ( null !== $path && is_readable( $path ) ) {
					require $path;
				}
			}
		);
	}

	/**
	 * Maps an FlitAgent class name to its file under includes/.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return string|null Absolute file path, or null for classes outside the FlitAgent namespace.
	 */
	public static function path_for( string $class_name ): ?string {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class_name, $prefix ) || strlen( $class_name ) === strlen( $prefix ) ) {
			return null;
		}

		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class_name, strlen( $prefix ) ) );

		return __DIR__ . DIRECTORY_SEPARATOR . $relative . '.php';
	}
}
