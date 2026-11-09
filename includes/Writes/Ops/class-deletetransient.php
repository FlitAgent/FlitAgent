<?php
/**
 * Write tool: delete_transient.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Redactor;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;

defined( 'ABSPATH' ) || exit;

/**
 * `delete_transient`: deletes one named transient (or site transient). Transients are caches, so this is reversible
 * (regen) and auto-approvable, except a secret-looking name (a token or key may not regenerate): destructive, with no
 * rollback. The value is never read into the preview. `delete_all_transients` deletes them all (destructive).
 */
final class DeleteTransient extends RegenTool {

	/**
	 * Longest transient name core can store (the option name is 191 characters at most).
	 */
	const MAX_NAME = 172;

	/**
	 * Longest site transient name.
	 */
	const MAX_SITE_NAME = 167;

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_transient';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete a transient',
			'Deletes one transient by name (as list_transients shows it, without the _transient_ prefix); network_wide: true deletes a site transient. Transients are caches the owning code rebuilds, so this may be applied at once if an administrator enabled auto-approve for it; a name that looks like a secret (token, key, password) always needs approval because it may not regenerate. To delete every transient use delete_all_transients.',
			array(
				'name'         => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => self::MAX_NAME,
					'description' => 'Transient name, e.g. "feed_abc123".',
				),
				'network_wide' => array(
					'type'        => 'boolean',
					'description' => 'true for a site transient (get_site_transient). Default false.',
				),
			),
			array( 'name' ),
			$this->tiers(),
			false
		);
	}

	/**
	 * Tiers: reversible, or destructive for a secret-looking name.
	 *
	 * @return string[]
	 */
	public function tiers(): array {
		return array( Risk::REVERSIBLE, Risk::DESTRUCTIVE );
	}

	/**
	 * Assesses the deletion. Read-only: existence is checked without get_transient(), which deletes expired values.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When a site transient name is too long.
	 */
	public function assess( array $args ): Assessment {
		$name = (string) $args['name'];
		$site = ! empty( $args['network_wide'] );
		if ( $site && strlen( $name ) > self::MAX_SITE_NAME ) {
			throw new ToolError( sprintf( 'A site transient name is at most %d characters.', (int) self::MAX_SITE_NAME ) );
		}

		$refusal = self::refuse_without_manage_options();
		if ( null !== $refusal ) {
			return $refusal;
		}

		$label = ( $site ? 'site transient ' : 'transient ' ) . $name;
		if ( ! self::exists( $name, $site ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'There is no %s to delete.', $label ) );
		}

		$preview       = array(
			'name'         => $name,
			'network_wide' => $site,
			'before'       => array( 'exists' => 'yes' ),
			'after'        => array( 'exists' => 'no' ),
		);
		$preconditions = array(
			'name'         => $name,
			'network_wide' => $site,
			'exists'       => true,
		);
		if ( Redactor::is_secret_key( $name ) ) {
			return Assessment::destructive( sprintf( 'Delete %s. Its name looks like a secret (a token or key), which may not regenerate. This cannot be undone.', $label ), $preview, $preconditions );
		}
		return Assessment::reversible( sprintf( 'Delete %s; the code that set it rebuilds it when needed.', $label ), $preview, $preconditions, true );
	}

	/**
	 * Deletes the transient.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 * @throws ToolError When it is already gone or could not be deleted.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$name = (string) $args['name'];
		$site = ! empty( $args['network_wide'] );
		if ( ! self::exists( $name, $site ) ) {
			throw new ToolError( sprintf( 'Transient %s no longer exists; nothing was deleted.', esc_textarea( $name ) ) );
		}
		$deleted = $site ? delete_site_transient( $name ) : delete_transient( $name );
		if ( ! $deleted ) {
			throw new ToolError( sprintf( 'WordPress could not delete transient %s.', esc_textarea( $name ) ) );
		}
		return new Outcome(
			array(
				'name'         => $name,
				'network_wide' => $site,
				'deleted'      => true,
			),
			Risk::DESTRUCTIVE === $assessment->tier() ? Rollback::none() : self::regen( $site ? 'site_transient' : 'transient', $name ),
			self::no_fingerprint()
		);
	}

	/**
	 * Reads back that the transient is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return self::exists( (string) $args['name'], ! empty( $args['network_wide'] ) ) ? $outcome->with_verification_failure( 'The transient still exists after deleting it (something set it again).' ) : $outcome;
	}

	/**
	 * Whether the transient is stored: in the object cache when one is in use, else in the options table.
	 *
	 * @param string $name Name.
	 * @param bool   $site Site transient.
	 * @return bool
	 */
	private static function exists( string $name, bool $site ): bool {
		if ( wp_using_ext_object_cache() ) {
			$found = false;
			wp_cache_get( $name, $site ? 'site-transient' : 'transient', false, $found );
			return (bool) $found;
		}
		return $site ? false !== get_site_option( '_site_transient_' . $name ) : false !== get_option( '_transient_' . $name );
	}
}
