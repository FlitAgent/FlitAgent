<?php
/**
 * Write tool: update_core.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Packages\Core;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\AdminCode;
use FlitAgent\Writes\Buffers;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Ops\ErrorText;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `update_core`: updates WordPress to a version WordPress.org offers in the cached `update_core` transient, with core's
 * `Core_Upgrader` and the non-interactive `Automatic_Upgrader_Skin` (as the background updater does). Destructive and
 * never auto-approvable; there is no rollback. Runs inside the MCP request: core's update_core() raises the time limit
 * to 300 seconds. If the request is still cut off, core leaves `.maintenance` (ignored by WordPress after 10 minutes)
 * and its 15-minute `core_updater` lock; delete `.maintenance` and run the update again, or re-install from
 * Dashboard > Updates.
 */
final class UpdateCore implements WriteTool {

	/**
	 * What the receipt and preview say about undoing it.
	 */
	const NO_UNDO = 'Not available: a core update cannot be undone by FlitAgent (rollback_change refuses it). Restore from a backup to go back.';

	/**
	 * How to recover after a failed or interrupted update.
	 */
	const RECOVERY = 'If the site shows "Briefly unavailable for scheduled maintenance", delete the .maintenance file in the WordPress root; then check Dashboard > Updates and re-run the update or re-install.';

	/**
	 * Version pattern: stable or development (the latter is refused with a reason).
	 */
	const VERSION_PATTERN = '/^\d+\.\d+(\.\d+)?(-[A-Za-z0-9.-]+)?$/';

	/**
	 * Locale pattern.
	 */
	const LOCALE_PATTERN = '/^[a-z]{2,3}(_[A-Z]{2})?(_[A-Za-z0-9]+)?$/';

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'update_core';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: update WordPress core',
			'Updates WordPress core to a stable version WordPress.org currently offers for this site (see get_core_updates). Always needs an administrator\'s approval (returns approval_required) and cannot be undone: there is no rollback_change for it. The preview shows the versions, minor or major, release notes, PHP and database compatibility, the active backup plugin and, for a major update, active plugins and themes not tested with it. Refused when no recent update check is cached (check for updates first), the version is not offered, is the installed one or older, or is a beta, RC or nightly; and when file changes are disabled, the filesystem needs credentials, the site is multisite or under version control, another core update is running, or PHP/MySQL are too old. After the update it checks the version on disk, the database upgrade and the home page, and reports problems as warnings.',
			array(
				'version' => array(
					'type'        => 'string',
					'minLength'   => 3,
					'maxLength'   => 40,
					'description' => 'Exact version to install, as get_core_updates lists it, e.g. "6.8.3".',
				),
				'locale'  => array(
					'type'        => 'string',
					'minLength'   => 2,
					'maxLength'   => 20,
					'description' => 'Package locale of the offer, e.g. "de_DE". Default: the site language if offered, else en_US.',
				),
			),
			array( 'version' ),
			$this->tiers(),
			true
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
	 * Assesses the update. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$found = self::offer( $args );
		if ( $found instanceof Assessment ) {
			return $found;
		}
		list( $offer, $installed, $offers ) = $found;

		$requirements = Preflight::requirements( $offer );
		if ( null !== $requirements['refusal'] ) {
			return $requirements['refusal'];
		}

		$from    = $installed['version'];
		$to      = (string) $offer->current;
		$major   = Readiness::branch( $from ) !== Readiness::branch( $to );
		$preview = array(
			'before'        => array( 'version' => $from ),
			'after'         => array( 'version' => $to ),
			'update'        => array(
				'type'          => $major ? 'major' : 'minor',
				'locale'        => (string) $offer->locale,
				'release_notes' => 'https://wordpress.org/documentation/wordpress-version/version-' . str_replace( '.', '-', $to ) . '/',
				'checked_at'    => $offers->checked_at(),
			),
			'compatibility' => $requirements['compatibility'],
			'backup'        => Readiness::backup(),
			'undo'          => 'This update cannot be undone. ' . self::NO_UNDO,
		);
		if ( $major ) {
			$preview['not_tested'] = Readiness::not_tested( $to );
		}

		return Assessment::destructive(
			sprintf( 'Update WordPress from %s to %s (%s update, %s). The site is in maintenance mode for a short time during the update, and this cannot be undone.', $from, $to, $major ? 'major' : 'minor', (string) $offer->locale ),
			$preview,
			array(
				'from'    => $from,
				'to'      => $to,
				'locale'  => (string) $offer->locale,
				'package' => (string) ( $offer->download ?? '' ),
			)
		);
	}

	/**
	 * Runs the update.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When WordPress changed meanwhile or the update fails.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$expected = $assessment->preconditions();
		$found    = self::offer(
			array(
				'version' => $expected['to'],
				'locale'  => $expected['locale'],
			)
		);
		if ( $found instanceof Assessment || $found[1]['version'] !== $expected['from'] ) {
			throw new ToolError( 'WordPress or its update offer changed after the update was assessed; nothing was changed.' );
		}
		$offer = $found[0];

		self::load_upgrader();
		ignore_user_abort( true );
		$skin  = new \Automatic_Upgrader_Skin();
		$level = ob_get_level();
		ob_start();
		try {
			$result = ( new \Core_Upgrader( $skin ) )->upgrade( $offer, array( 'attempt_rollback' => true ) );
		} finally {
			// Everything printed, including into buffers the upgrader or a hook left open, which are closed.
			$output = Buffers::discard( $level );
		}

		if ( is_wp_error( $result ) ) {
			throw new ToolError( sprintf( 'WordPress was not updated (%s): %s %s', esc_textarea( $result->get_error_code() ), esc_textarea( ErrorText::clean( (string) $result->get_error_message() ) ), esc_textarea( self::RECOVERY ) ) );
		}
		if ( ! is_string( $result ) || '' === $result ) {
			$messages = array_map( 'strval', (array) $skin->get_upgrade_messages() );
			throw new ToolError( 'WordPress was not updated: the upgrader returned no result' . ( array() === $messages ? '.' : ': ' . esc_textarea( ErrorText::clean( (string) end( $messages ) ) ) ) . ' ' . esc_textarea( self::RECOVERY ) );
		}

		$outcome = new Outcome(
			array(
				'from'     => $expected['from'],
				'version'  => $expected['to'],
				'locale'   => $expected['locale'],
				'rollback' => self::NO_UNDO,
			),
			Rollback::none()
		);
		if ( '' !== trim( $output ) ) {
			$outcome = $outcome->with_warning( 'unexpected_output', 'The update printed output, which was discarded.' );
		}
		if ( Installed::in_maintenance() ) {
			Installed::end_maintenance();
			$outcome = $outcome->with_warning( 'maintenance_removed', 'The update left the .maintenance file behind; FlitAgent removed it so the site is reachable.' );
		}
		return $outcome;
	}

	/**
	 * Reads the version on disk, the database version, maintenance mode and the home page.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$to        = (string) $outcome->result()['version'];
		$installed = Installed::read();
		if ( null === $installed ) {
			return $outcome->with_verification_failure( 'wp-includes/version.php could not be read after the update. ' . self::RECOVERY );
		}
		if ( $installed['version'] !== $to ) {
			return $outcome->with_verification_failure( sprintf( 'wp-includes/version.php still reports %s, not %s. ', $installed['version'], $to ) . self::RECOVERY );
		}

		$db = Installed::stored_db_version();
		if ( $db < $installed['db_version'] ) {
			SiteCheck::upgrade_database();
			$db = Installed::stored_db_version();
		}
		$outcome = $outcome->with_result(
			array(
				'version'    => $installed['version'],
				'db_version' => (string) $db,
			)
		);
		if ( $db < $installed['db_version'] ) {
			$outcome = $outcome->with_warning( 'db_upgrade_pending', sprintf( 'The database still needs updating (db_version %d, WordPress %s needs %d). Open wp-admin/upgrade.php as an administrator and click "Update WordPress Database".', $db, $installed['version'], $installed['db_version'] ) );
		}
		if ( Installed::in_maintenance() ) {
			$outcome = $outcome->with_warning( 'maintenance_left', 'The .maintenance file is still in the WordPress root; delete it so visitors can reach the site.' );
		}
		return SiteCheck::home( $outcome );
	}

	/**
	 * The validated offer with the installed version, or a refusal.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array{0: object, 1: array{version: string, db_version: int}, 2: Offers}|Assessment
	 * @throws ToolError When an argument is malformed.
	 */
	private static function offer( array $args ) {
		$version = isset( $args['version'] ) && is_string( $args['version'] ) ? trim( $args['version'] ) : '';
		if ( 1 !== preg_match( self::VERSION_PATTERN, $version ) ) {
			throw new ToolError( 'version must be an exact WordPress version such as "6.8.3"; get_core_updates lists the offered versions.' );
		}
		$locale = null;
		if ( isset( $args['locale'] ) ) {
			$locale = is_string( $args['locale'] ) ? $args['locale'] : '';
			if ( 1 !== preg_match( self::LOCALE_PATTERN, $locale ) ) {
				throw new ToolError( 'locale must be a WordPress locale such as "en_US" or "de_DE".' );
			}
		}

		$refusal = Preflight::site();
		if ( null !== $refusal ) {
			return $refusal;
		}
		$installed = Installed::read();
		if ( null === $installed ) {
			return Assessment::prohibited( 'unknown_version', 'The installed WordPress version could not be read from wp-includes/version.php.' );
		}
		$offers  = new Offers();
		$refusal = $offers->unusable( $installed['version'] );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$offer = $offers->find( $version, $locale, $installed['version'] );
		return $offer instanceof Assessment ? $offer : array( $offer, $installed, $offers );
	}

	/**
	 * Loads the admin code Core_Upgrader and update_core() use, when the request has not (as WP-CLI does).
	 */
	private static function load_upgrader(): void {
		AdminCode::load();
		if ( ! class_exists( 'Core_Upgrader', false ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
	}
}
