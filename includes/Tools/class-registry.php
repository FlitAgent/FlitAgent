<?php
/**
 * Every tool FlitAgent serves.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Collects tools from one provider class per package, in PROVIDERS order. To add a package, add one provider class and
 * one line here.
 */
final class Registry {

	/**
	 * Provider classes. Read tools appear in this order.
	 */
	const PROVIDERS = array(
		Providers\CoreReads::class,
	);

	/**
	 * Provider instances.
	 *
	 * @return Provider[]
	 */
	public static function providers(): array {
		$providers = array();
		foreach ( self::PROVIDERS as $class ) {
			$providers[] = new $class();
		}
		return $providers;
	}

	/**
	 * Read tools of every provider, each returning plain text (PlainOutput).
	 *
	 * @return \FlitAgent\Mcp\Tool[]
	 */
	public static function reads(): array {
		$tools = array();
		foreach ( self::providers() as $provider ) {
			foreach ( $provider->reads() as $tool ) {
				$tools[] = new PlainOutput( $tool );
			}
		}
		return $tools;
	}
}
