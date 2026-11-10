<?php
/**
 * Write tool: set_theme_mod.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Config;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `set_theme_mod`: sets or removes one of five typed theme mods of the active theme. Only these keys, because many
 * themes print mods without escaping, and every value is validated (existing image attachments, hex colours).
 * Reversible from a snapshot; never auto-approvable, because the change is public.
 */
final class SetThemeMod implements WriteTool, Reversible {

	/**
	 * Key => theme feature it needs.
	 */
	const KEYS = array(
		'custom_logo'      => 'custom-logo',
		'background_color' => 'custom-background',
		'header_textcolor' => 'custom-header',
		'background_image' => 'custom-background',
		'header_image'     => 'custom-header',
	);

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'set_theme_mod';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: set a theme mod',
			'Sets or removes one theme mod of the active theme: custom_logo (id of an image attachment), background_color or header_textcolor (3 or 6 hex digits; header_textcolor also "blank" to hide the header text), background_image or header_image (URL of an image in the Media Library). An empty value removes the mod. Refused when the active theme does not support the feature. Always returns approval_required; undo with rollback_change. For custom CSS use update_custom_css.',
			array(
				'key'   => array(
					'type'        => 'string',
					'enum'        => array_keys( self::KEYS ),
					'description' => 'Theme mod to set.',
				),
				'value' => array(
					'type'        => 'string',
					'maxLength'   => 2048,
					'description' => 'New value, or "" to remove the mod.',
				),
			),
			array( 'key', 'value' ),
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
		return array( Risk::REVERSIBLE );
	}

	/**
	 * Never auto-approvable: theme mods are public.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the change. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the value is invalid or would not change anything.
	 */
	public function assess( array $args ): Assessment {
		$key     = (string) $args['key'];
		$refusal = self::refusal( $key ) ?? self::unsupported( $key );
		if ( null !== $refusal ) {
			return $refusal;
		}

		$value = self::value( $key, (string) $args['value'] );
		$state = self::read( $key );
		if ( ( null === $value && ! $state['exists'] ) || ( null !== $value && $state['exists'] && Canonical::json( $state['value'] ) === Canonical::json( $value ) ) ) {
			throw new ToolError( sprintf( 'The theme mod %s already has that value; nothing to change.', esc_textarea( $key ) ) );
		}

		$after = self::display(
			array(
				'exists' => null !== $value,
				'value'  => $value,
			)
		);
		return Assessment::reversible(
			sprintf( '%s the theme mod %s of theme %s ("%s" to "%s").', null === $value ? 'Remove' : 'Set', $key, $state['theme'], self::display( $state ), $after ),
			array(
				'theme'  => $state['theme'],
				'key'    => $key,
				'before' => self::display( $state ),
				'after'  => $after,
			),
			array( 'fingerprint' => self::fingerprint( $state ) ),
			false
		);
	}

	/**
	 * Sets or removes the mod.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the mod changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$key    = (string) $args['key'];
		$before = self::unchanged( $key, $assessment->preconditions(), 'changed' );
		$value  = self::value( $key, (string) $args['value'] );

		self::write( $key, null !== $value, $value );

		return new Outcome(
			array(
				'theme'   => $before['theme'],
				'key'     => $key,
				'removed' => null === $value,
			),
			Rollback::make(
				Rollback::SNAPSHOT,
				'theme_mod',
				$key,
				array( 'theme' => $before['theme'] ),
				array(
					'exists' => $before['exists'],
					'packed' => Exact::pack( $before['value'] ),
				)
			),
			array( 'fingerprint' => self::fingerprint( self::read( $key ) ) )
		);
	}

	/**
	 * Reads the mod back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$key      = (string) $args['key'];
		$value    = self::value( $key, (string) $args['value'] );
		$state    = self::read( $key );
		$expected = null !== $value;
		if ( $expected !== $state['exists'] || Canonical::json( $state['value'] ) !== Canonical::json( $value ) ) {
			return $outcome->with_verification_failure( sprintf( 'The theme mod %s could not be read back as written.', $key ) );
		}
		return $outcome;
	}

	/**
	 * Assesses restoring the previous mod. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$key = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		// The theme check comes before the support check: the active theme may lack the feature only because it is not
		// the theme the change was made on.
		$refusal = self::refusal( $key ) ?? self::theme_switched( $rollback ) ?? self::unsupported( $key );
		if ( null !== $refusal ) {
			return $refusal;
		}

		$current  = self::read( $key );
		$restored = self::snapshot( $rollback );
		return Assessment::reversible(
			sprintf( 'Restore the theme mod %s of theme %s to "%s" (now "%s").', $key, $current['theme'], self::display( $restored ), self::display( $current ) ),
			array(
				'theme'    => $current['theme'],
				'key'      => $key,
				'current'  => self::display( $current ),
				'restored' => self::display( $restored ),
			),
			array( 'fingerprint' => self::fingerprint( $current ) ),
			false
		);
	}

	/**
	 * Restores the previous mod, if it is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the mod changed.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$key = (string) $rollback['target']['id'];
		if ( null !== self::theme_switched( $rollback ) ) {
			throw new ToolError( 'The active theme changed; nothing was restored.' );
		}
		self::unchanged( $key, $expected_state, 'restored' );
		$restored = self::snapshot( $rollback );
		self::write( $key, $restored['exists'], $restored['value'] );

		$outcome = new Outcome(
			array(
				'key'      => $key,
				'restored' => true,
			),
			Rollback::none()
		);
		$after   = self::read( $key );
		return $after['exists'] === $restored['exists'] && Canonical::json( $after['value'] ) === Canonical::json( $restored['value'] )
			? $outcome
			: $outcome->with_verification_failure( sprintf( 'The theme mod %s could not be read back as restored.', $key ) );
	}

	/**
	 * Refusal for an unknown key or a missing capability, or null.
	 *
	 * @param string $key Key.
	 * @return Assessment|null
	 */
	private static function refusal( string $key ): ?Assessment {
		if ( ! isset( self::KEYS[ $key ] ) ) {
			return Assessment::prohibited( 'not_allowed', sprintf( 'The theme mod %s is not one FlitAgent may change.', $key ) );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return Assessment::prohibited( 'forbidden', 'Changing theme mods needs the edit_theme_options capability.' );
		}
		return null;
	}

	/**
	 * Refusal when the active theme does not support the key's feature, or null. Call after refusal().
	 *
	 * @param string $key Key.
	 * @return Assessment|null
	 */
	private static function unsupported( string $key ): ?Assessment {
		if ( ! current_theme_supports( self::KEYS[ $key ] ) ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'The active theme does not support %s, so it would ignore %s.', self::KEYS[ $key ], $key ) );
		}
		return null;
	}

	/**
	 * Refusal when the theme the write targeted is no longer active, or null.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment|null
	 */
	private static function theme_switched( array $rollback ): ?Assessment {
		return ThemeBound::refusal( $rollback );
	}

	/**
	 * The validated value to store, or null to remove the mod.
	 *
	 * @param string $key   Key.
	 * @param string $value Value as sent.
	 * @return string|int|null
	 * @throws ToolError When the value is invalid.
	 */
	private static function value( string $key, string $value ) {
		if ( '' === $value ) {
			return null;
		}
		switch ( $key ) {
			case 'custom_logo':
				if ( 1 !== preg_match( '/^[1-9]\d{0,18}$/D', $value ) || ! wp_attachment_is_image( (int) $value ) ) {
					throw new ToolError( 'custom_logo must be the id of an image attachment in the Media Library.' );
				}
				return (int) $value;
			case 'background_color':
			case 'header_textcolor':
				if ( 'header_textcolor' === $key && 'blank' === $value ) {
					return $value;
				}
				if ( 1 !== preg_match( '/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D', $value ) ) {
					throw new ToolError( sprintf( '%s must be 3 or 6 hex digits, for example "1a2b3c"%s.', esc_textarea( $key ), 'header_textcolor' === $key ? ' (or "blank")' : '' ) );
				}
				return ltrim( $value, '#' );
			default:
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.attachment_url_to_postid_attachment_url_to_postid -- one lookup per write assessment; the VIP wrapper exists only on VIP.
				$id = 1 === preg_match( '#^https?://#i', $value ) ? (int) attachment_url_to_postid( $value ) : 0;
				if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
					throw new ToolError( sprintf( '%s must be the URL of an image attachment in the Media Library.', esc_textarea( $key ) ) );
				}
				return $value;
		}//end switch
	}

	/**
	 * Current state of a mod of the active theme.
	 *
	 * @param string $key Key.
	 * @return array{theme: string, exists: bool, value: mixed}
	 */
	private static function read( string $key ): array {
		$mods   = get_theme_mods();
		$exists = is_array( $mods ) && array_key_exists( $key, $mods );
		return array(
			'theme'  => ThemeBound::current(),
			'exists' => $exists,
			'value'  => $exists ? $mods[ $key ] : null,
		);
	}

	/**
	 * Fingerprint of a mod state (theme included).
	 *
	 * @param array{theme: string, exists: bool, value: mixed} $state State.
	 * @return string
	 */
	private static function fingerprint( array $state ): string {
		return Canonical::hash( array( $state['theme'], $state['exists'], $state['value'] ) );
	}

	/**
	 * Throws unless the mod is still as assessed.
	 *
	 * @param string               $key      Key.
	 * @param array<string, mixed> $expected Preconditions.
	 * @param string               $what     What would have been done.
	 * @return array{theme: string, exists: bool, value: mixed}
	 * @throws ToolError When it changed.
	 */
	private static function unchanged( string $key, array $expected, string $what ): array {
		$state = self::read( $key );
		if ( ! isset( $expected['fingerprint'] ) || self::fingerprint( $state ) !== $expected['fingerprint'] ) {
			throw new ToolError( sprintf( 'The theme mod %s changed after the request was assessed; nothing was %s.', esc_textarea( $key ), esc_textarea( $what ) ) );
		}
		return $state;
	}

	/**
	 * Sets or removes a mod.
	 *
	 * @param string $key    Key.
	 * @param bool   $exists Whether the mod should exist.
	 * @param mixed  $value  Value.
	 */
	private static function write( string $key, bool $exists, $value ): void {
		if ( $exists ) {
			set_theme_mod( $key, $value );
		} else {
			remove_theme_mod( $key );
		}
	}

	/**
	 * The snapshot in a rollback reference.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return array{exists: bool, value: mixed}
	 */
	private static function snapshot( array $rollback ): array {
		return array(
			'exists' => ! empty( $rollback['before']['exists'] ),
			'value'  => isset( $rollback['before']['packed'] ) ? Exact::unpack( $rollback['before']['packed'] ) : null,
		);
	}

	/**
	 * A mod state as text for previews.
	 *
	 * @param array<string, mixed> $state State.
	 * @return string
	 */
	private static function display( array $state ): string {
		return Options::display(
			array(
				'exists' => (bool) $state['exists'],
				'value'  => $state['value'],
			)
		);
	}
}
