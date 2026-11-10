<?php
/**
 * Rollbacks of writes that belong to the active theme.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

use FlitAgent\Writes\Assessment;

defined( 'ABSPATH' ) || exit;

/**
 * Theme mods, menu locations and widget areas are stored per theme. A write records the theme it ran on, and its
 * rollback acts only while that theme is still active, so it never writes into another theme.
 */
final class ThemeBound {

	/**
	 * The active theme (its stylesheet).
	 *
	 * @return string
	 */
	public static function current(): string {
		return (string) get_stylesheet();
	}

	/**
	 * The rollback refs that record the active theme.
	 *
	 * @return array{theme: string}
	 */
	public static function refs(): array {
		return array( 'theme' => self::current() );
	}

	/**
	 * The theme the write ran on, or '' when the receipt does not record one.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return string
	 */
	public static function theme( array $rollback ): string {
		return isset( $rollback['refs']['theme'] ) ? (string) $rollback['refs']['theme'] : '';
	}

	/**
	 * Whether the theme the write ran on is the active theme.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return bool
	 */
	public static function active( array $rollback ): bool {
		return self::current() === self::theme( $rollback );
	}

	/**
	 * Refusal when the theme the write ran on is no longer active, or null.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment|null
	 */
	public static function refusal( array $rollback ): ?Assessment {
		$theme = self::theme( $rollback );
		if ( '' === $theme ) {
			return Assessment::prohibited( 'theme_changed', 'This receipt does not record the theme the change was made on, so it cannot be rolled back safely.' );
		}
		return self::active( $rollback ) ? null : Assessment::prohibited( 'theme_changed', sprintf( 'This change was made on the theme %1$s, which is no longer the active theme. Switch back to %1$s to roll it back.', $theme ) );
	}
}
