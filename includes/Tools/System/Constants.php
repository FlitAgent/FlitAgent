<?php
/**
 * Constant reader.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\System;

defined( 'ABSPATH' ) || exit;

/**
 * Reads PHP constants through a replaceable callable, so tools can be tested with any configuration.
 */
final class Constants {

	/**
	 * Reader returning array( defined, value ).
	 *
	 * @var callable
	 */
	private $lookup;

	/**
	 * Constructor.
	 *
	 * @param callable|null $lookup Returns array( bool defined, mixed value ) for a name. Defaults to defined()/constant().
	 */
	public function __construct( ?callable $lookup = null ) {
		$this->lookup = $lookup ? $lookup : static function ( string $name ): array {
			return defined( $name ) ? array( true, constant( $name ) ) : array( false, null );
		};
	}

	/**
	 * Whether the constant is defined and truthy.
	 *
	 * @param string $name Constant name.
	 * @return bool
	 */
	public function is_true( string $name ): bool {
		list( $defined, $value ) = call_user_func( $this->lookup, $name );
		return $defined && (bool) $value;
	}

	/**
	 * The constant's value, or the fallback when undefined.
	 *
	 * @param string $name     Constant name.
	 * @param mixed  $fallback Value when undefined.
	 * @return mixed
	 */
	public function get( string $name, $fallback = null ) {
		list( $defined, $value ) = call_user_func( $this->lookup, $name );
		return $defined ? $value : $fallback;
	}
}
