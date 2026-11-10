<?php
/**
 * Page-builder write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

use FlitAgent\Tools\Builders\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Layout saves for the builders active on this site, detected like the builder reads (#48, `Catalog::active()`).
 */
final class Provider implements \FlitAgent\Tools\Provider {

	/**
	 * Decides per builder; null uses Catalog's detection.
	 *
	 * @var callable|null
	 */
	private $is_active;

	/**
	 * Constructor.
	 *
	 * @param callable|null $is_active Decides per builder; defaults to Catalog::is_loaded(). For tests.
	 */
	public function __construct( ?callable $is_active = null ) {
		$this->is_active = $is_active;
	}

	/**
	 * No read tools (the builder reads come from Tools\Providers\BuilderReads).
	 *
	 * @return \FlitAgent\Mcp\Tool[]
	 */
	public function reads(): array {
		return array();
	}

	/**
	 * Save tools of the active builders.
	 *
	 * @return \FlitAgent\Writes\WriteTool[]
	 */
	public function writes(): array {
		$tools = array();
		foreach ( Catalog::active( $this->is_active ) as $builder ) {
			switch ( $builder ) {
				case 'elementor':
					$tools[] = new SaveElementorPage();
					$tools[] = new SaveElementorTemplate();
					break;
				case 'bricks':
					$tools[] = new SaveBricksPage();
					break;
				default:
					$tools[] = new SaveBreakdancePage();
			}
		}
		return $tools;
	}
}
