<?php
/**
 * The get_core_updates tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\System;

use FlitAgent\Mcp\Tool;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Reports the WordPress version and cached core updates (WP-CLI `core version` / `core check-update`).
 */
final class CoreUpdates implements Tool {

	/**
	 * Constant reader.
	 *
	 * @var Constants
	 */
	private $constants;

	/**
	 * Constructor.
	 *
	 * @param callable|null $lookup Constant reader, for tests.
	 */
	public function __construct( ?callable $lookup = null ) {
		$this->constants = new Constants( $lookup );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_core_updates';
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		return Definition::read_only(
			$this->name(),
			'Get WordPress core updates with FlitAgent',
			'reports the installed WordPress version, the core update offers WordPress last fetched (type, version, locale) and when it checked, plus whether minor and major automatic updates are on. It reads the cached result only and never contacts wordpress.org.'
		);
	}

	/**
	 * Reports the version and updates.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array(), $this->name() );

		$cached  = get_site_transient( 'update_core' );
		$updates = array();
		foreach ( is_object( $cached ) && isset( $cached->updates ) ? (array) $cached->updates : array() as $offer ) {
			$offer     = (array) $offer;
			$updates[] = array(
				'type'    => (string) ( $offer['response'] ?? '' ),
				'version' => (string) ( $offer['current'] ?? '' ),
				'locale'  => (string) ( $offer['locale'] ?? '' ),
			);
		}

		$checked = is_object( $cached ) && ! empty( $cached->last_checked ) ? gmdate( 'c', (int) $cached->last_checked ) : null;

		return array(
			'version'      => (string) get_bloginfo( 'version' ),
			'updates'      => $updates,
			'checked_at'   => $checked,
			'auto_updates' => $this->auto_updates(),
		);
	}

	/**
	 * Whether minor and major core auto-updates are on.
	 *
	 * @return array{minor: bool, major: bool}
	 */
	private function auto_updates(): array {
		if ( $this->constants->is_true( 'AUTOMATIC_UPDATER_DISABLED' ) ) {
			return array(
				'minor' => false,
				'major' => false,
			);
		}

		$setting = $this->constants->get( 'WP_AUTO_UPDATE_CORE', null );
		if ( false === $setting ) {
			return array(
				'minor' => false,
				'major' => false,
			);
		}
		if ( true === $setting || 'beta' === $setting || 'rc' === $setting || 'development' === $setting ) {
			return array(
				'minor' => true,
				'major' => true,
			);
		}

		return array(
			'minor' => true,
			'major' => 'enabled' === get_site_option( 'auto_update_core_major' ),
		);
	}
}
