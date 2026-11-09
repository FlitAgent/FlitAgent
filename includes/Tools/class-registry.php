<?php
/**
 * Every tool FlitAgent can serve.
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
		Providers\BuilderReads::class,
		Providers\WooReads::class,
		\FlitAgent\Writes\Content\Provider::class,
		\FlitAgent\Writes\TermsComments\Provider::class,
		\FlitAgent\Writes\Media\Provider::class,
		\FlitAgent\Writes\Users\Provider::class,
		\FlitAgent\Writes\Ops\Provider::class,
		\FlitAgent\Writes\Packages\Plugins\Provider::class,
		\FlitAgent\Writes\Packages\Themes\Provider::class,
		\FlitAgent\Writes\Packages\Core\Provider::class,
		\FlitAgent\Writes\Builders\Provider::class,
		\FlitAgent\Writes\Config\Provider::class,
		\FlitAgent\Writes\Abilities\Provider::class,
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

	/**
	 * Write tools of every provider.
	 *
	 * @return \FlitAgent\Writes\WriteTool[]
	 */
	public static function writes(): array {
		$tools = array();
		foreach ( self::providers() as $provider ) {
			$tools = array_merge( $tools, $provider->writes() );
		}
		return $tools;
	}
}
