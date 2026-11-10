<?php
/**
 * Write tool: delete_all_transients.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Redactor;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `delete_all_transients`: deletes every transient and site transient stored in the database, each through
 * `delete_transient()` / `delete_site_transient()` so hooks and caches stay consistent. Destructive: most transients
 * are caches their owners rebuild, but some plugins keep licence keys or OAuth tokens in transients that will not come
 * back, so it always needs approval and has no rollback. Refused while a persistent object cache holds transients
 * (`flush_object_cache` clears those). Values are never read.
 */
final class DeleteAllTransients implements WriteTool {

	/**
	 * Most names listed in the preview, per kind.
	 */
	const PREVIEW_NAMES = 50;

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_all_transients';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete all transients',
			'Deletes every transient and site transient stored in the database. Always needs an administrator\'s approval (returns approval_required); the approval preview counts them, lists up to 50 names of each kind and flags names that look like tokens or keys, which some plugins keep in transients and which may not come back. Cannot be rolled back. Refused when a persistent object cache holds transients (use flush_object_cache). To delete one transient use delete_transient; for expired ones only use delete_expired_transients.',
			array(),
			array(),
			$this->tiers(),
			false
		);
	}

	/**
	 * Tiers.
	 *
	 * @return string[]
	 */
	public function tiers(): array {
		return array( Risk::DESTRUCTIVE );
	}

	/**
	 * Never auto-approvable.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the deletion. Read-only: lists names, never values.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When there is nothing to delete.
	 */
	public function assess( array $args ): Assessment {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Assessment::prohibited( 'forbidden', 'Deleting transients needs the manage_options capability.' );
		}
		if ( wp_using_ext_object_cache() ) {
			return Assessment::prohibited( 'object_cache', 'A persistent object cache holds this site\'s transients, so they are not in the database; use flush_object_cache to clear the cache instead.' );
		}

		$names = self::names();
		$total = count( $names['transients'] ) + count( $names['site_transients'] );
		if ( 0 === $total ) {
			throw new ToolError( 'There are no transients in the database; nothing to delete.' );
		}

		$secret = array_values( array_filter( array_merge( $names['transients'], $names['site_transients'] ), array( Redactor::class, 'is_secret_key' ) ) );
		return Assessment::destructive(
			sprintf(
				'Delete all %s and %s. Most are caches their plugins rebuild%s; this cannot be rolled back.',
				Summary::count( count( $names['transients'] ), 'transient', 'transients' ),
				Summary::count( count( $names['site_transients'] ), 'site transient', 'site transients' ),
				array() === $secret ? '' : ', but ' . Summary::count( count( $secret ), 'looks like a token or key', 'look like tokens or keys' ) . ' that may not come back'
			),
			array(
				'transients'      => count( $names['transients'] ),
				'site_transients' => count( $names['site_transients'] ),
				'names'           => array(
					'transients'      => array_slice( $names['transients'], 0, self::PREVIEW_NAMES ),
					'site_transients' => array_slice( $names['site_transients'], 0, self::PREVIEW_NAMES ),
				),
				'truncated'       => count( $names['transients'] ) > self::PREVIEW_NAMES || count( $names['site_transients'] ) > self::PREVIEW_NAMES,
				'key_like_names'  => $secret,
			),
			array( 'names' => Canonical::hash( $names ) )
		);
	}

	/**
	 * Deletes the transients listed now (a transient added since approval is deleted too; it is a cache).
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$names   = self::names();
		$deleted = array(
			'transients'      => 0,
			'site_transients' => 0,
		);
		foreach ( $names['transients'] as $name ) {
			$deleted['transients'] += delete_transient( $name ) ? 1 : 0;
		}
		foreach ( $names['site_transients'] as $name ) {
			$deleted['site_transients'] += delete_site_transient( $name ) ? 1 : 0;
		}

		return new Outcome(
			array(
				'deleted_transients'      => $deleted['transients'],
				'deleted_site_transients' => $deleted['site_transients'],
			),
			Rollback::none()
		);
	}

	/**
	 * Reports transients still in the database (re-created by a hook during the deletion, or not deletable).
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$names = self::names();
		$left  = count( $names['transients'] ) + count( $names['site_transients'] );
		return 0 === $left ? $outcome : $outcome->with_warning( 'transients_left', sprintf( '%s still in the database (re-created while deleting, or WordPress could not delete %s).', Summary::count( $left, 'transient is', 'transients are' ), 1 === $left ? 'it' : 'them' ) );
	}

	/**
	 * Names of the transients and site transients in the database, without prefixes and timeout rows, sorted.
	 *
	 * @return array{transients: string[], site_transients: string[]}
	 */
	private static function names(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- core has no API that lists transients; names only, never cached because they change as they are deleted.
		$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%' ) );
		if ( is_multisite() ) {
			$rows = array_filter(
				$rows,
				static function ( $row ) {
					return 0 !== strpos( (string) $row, '_site_transient_' );
				}
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- on multisite, site transients are network meta; core has no API that lists them.
			$network = (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key LIKE %s", get_current_network_id(), $wpdb->esc_like( '_site_transient_' ) . '%' ) );
			$rows    = array_merge( $rows, $network );
		}

		$names = array(
			'transients'      => array(),
			'site_transients' => array(),
		);
		foreach ( $rows as $row ) {
			$row = (string) $row;
			if ( 0 === strpos( $row, '_site_transient_' ) ) {
				$name = substr( $row, strlen( '_site_transient_' ) );
				$kind = 'site_transients';
			} else {
				$name = substr( $row, strlen( '_transient_' ) );
				$kind = 'transients';
			}
			if ( '' !== $name && 0 !== strpos( $name, 'timeout_' ) ) {
				$names[ $kind ][ $name ] = true;
			}
		}
		$names['transients']      = array_keys( $names['transients'] );
		$names['site_transients'] = array_keys( $names['site_transients'] );
		sort( $names['transients'] );
		sort( $names['site_transients'] );
		return $names;
	}
}
