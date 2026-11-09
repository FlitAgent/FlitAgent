<?php
/**
 * Write tool: set_permalink_structure.
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
 * `set_permalink_structure`: changes the permalink structure and, optionally, the category and tag bases, the way
 * core's Permalinks screen does (WP_Rewrite setters), then flushes rewrite rules softly (no .htaccess or web.config
 * write). Always destructive: every post URL changes. Rollback restores the three values, also with approval.
 */
final class SetPermalinkStructure implements WriteTool, Reversible {

	/**
	 * The options this tool owns, keyed by argument name.
	 */
	const OPTIONS = array(
		'structure'     => 'permalink_structure',
		'category_base' => 'category_base',
		'tag_base'      => 'tag_base',
	);

	/**
	 * Core's post structure tags.
	 */
	const TAGS = array( 'year', 'monthnum', 'day', 'hour', 'minute', 'second', 'post_id', 'postname', 'category', 'author' );

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'set_permalink_structure';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: set the permalink structure',
			'Changes the permalink structure (and optionally the category and tag bases) like Settings > Permalinks: "" for plain links, or a path with core structure tags such as /%postname%/ or /%year%/%monthnum%/%postname%/ (%year% %monthnum% %day% %hour% %minute% %second% %post_id% %postname% %category% %author%). Every post URL changes, so it always needs an administrator\'s approval. Rewrite rules are flushed without writing .htaccess; on Apache without existing rewrite rules, pretty links may need the Permalinks screen to be saved once. Undo with rollback_change (also approved).',
			array(
				'structure'     => array(
					'type'        => 'string',
					'maxLength'   => 255,
					'description' => 'New permalink structure, or "" for plain links.',
				),
				'category_base' => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => 'New category base (e.g. "topics"), "" for the default. Omit to keep the current one.',
				),
				'tag_base'      => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => 'New tag base, "" for the default. Omit to keep the current one.',
				),
			),
			array( 'structure' ),
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
	 * Assesses the change. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When a value is invalid or nothing would change.
	 */
	public function assess( array $args ): Assessment {
		$after = self::requested( $args );
		if ( ! current_user_can( 'manage_options' ) ) {
			return Assessment::prohibited( 'forbidden', 'Changing permalinks needs the manage_options capability.' );
		}

		$before = self::read();
		$after += $before;
		if ( $after === $before ) {
			throw new ToolError( 'The permalink settings already have those values; nothing to change.' );
		}

		return Assessment::destructive(
			sprintf( 'Change the permalink structure from "%s" to "%s" (category base "%s" to "%s", tag base "%s" to "%s"). Every post URL changes; old links may stop working.', $before['structure'], $after['structure'], $before['category_base'], $after['category_base'], $before['tag_base'], $after['tag_base'] ),
			array(
				'before' => $before,
				'after'  => $after,
			),
			array( 'fingerprint' => Canonical::hash( $before ) )
		);
	}

	/**
	 * Applies the structure and bases, then flushes rewrite rules softly.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the settings changed meanwhile.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$before = self::unchanged( $assessment->preconditions(), 'changed' );
		$after  = self::requested( $args ) + $before;
		self::write( $after );

		return new Outcome(
			array( 'permalinks' => self::read() ),
			Rollback::make( Rollback::SNAPSHOT, 'permalinks', 'permalink_structure', array(), $before ),
			array( 'fingerprint' => Canonical::hash( self::read() ) )
		);
	}

	/**
	 * Reads the settings back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$stored = self::read();
		foreach ( self::requested( $args ) as $field => $value ) {
			if ( $stored[ $field ] !== $value ) {
				return $outcome->with_result( array( 'stored_verbatim' => false ) )
					->with_warning( 'stored_differently', sprintf( 'WordPress stored the %s as "%s".', $field, $stored[ $field ] ) );
			}
		}
		return $outcome->with_result( array( 'stored_verbatim' => true ) );
	}

	/**
	 * Assesses restoring the previous settings. Destructive like the change. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		if ( ! current_user_can( 'manage_options' ) ) {
			return Assessment::prohibited( 'forbidden', 'Changing permalinks needs the manage_options capability.' );
		}
		$current  = self::read();
		$restored = self::snapshot( $rollback );
		return Assessment::destructive(
			sprintf( 'Restore the permalink structure "%s" (now "%s"). Every post URL changes again.', $restored['structure'], $current['structure'] ),
			array(
				'current'  => $current,
				'restored' => $restored,
			),
			array( 'fingerprint' => Canonical::hash( $current ) )
		);
	}

	/**
	 * Restores the previous settings, if they are still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		self::unchanged( $expected_state, 'restored' );
		$restored = self::snapshot( $rollback );
		self::write( $restored );

		$outcome = new Outcome( array( 'permalinks' => self::read() ), Rollback::none() );
		return self::read() === $restored ? $outcome : $outcome->with_warning( 'restored_differently', 'WordPress stored the restored permalink settings differently.' );
	}

	/**
	 * The requested values, normalized like core's Permalinks screen and validated.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, string> Only the fields that were sent.
	 * @throws ToolError When a value is invalid.
	 */
	private static function requested( array $args ): array {
		$values = array();
		foreach ( array_keys( self::OPTIONS ) as $field ) {
			if ( ! array_key_exists( $field, $args ) ) {
				continue;
			}
			$value = (string) $args[ $field ];
			if ( '' !== $value ) {
				$value = (string) preg_replace( '#/+#', '/', '/' . str_replace( '#', '', $value ) );
			}
			$allowed = 'structure' === $field ? '/^[A-Za-z0-9_\-.\/%]*$/D' : '/^[A-Za-z0-9_\-.\/]*$/D';
			if ( 1 !== preg_match( $allowed, $value ) || false !== strpos( $value, '..' ) ) {
				throw new ToolError( sprintf( '%s may only contain letters, digits, "-", "_", ".", "/"%s.', esc_textarea( $field ), 'structure' === $field ? ' and structure tags' : '' ) );
			}
			if ( 'structure' === $field && '' !== $value ) {
				self::check_tags( $value );
			}
			// Bases are compared as get_option() returns them: core's _wp_filter_taxonomy_base() trims the slashes.
			$values[ $field ] = 'structure' === $field ? $value : trim( $value, '/' );
		}
		return $values;
	}

	/**
	 * Throws unless the structure has at least one tag and only core tags.
	 *
	 * @param string $structure Structure.
	 * @throws ToolError When it does not.
	 */
	private static function check_tags( string $structure ): void {
		preg_match_all( '/%([^\/%]*)%/', $structure, $found );
		if ( array() === $found[1] ) {
			throw new ToolError( 'The structure needs at least one structure tag, for example /%postname%/ (or "" for plain links).' );
		}
		foreach ( $found[1] as $tag ) {
			if ( ! in_array( $tag, self::TAGS, true ) ) {
				throw new ToolError( sprintf( 'The structure has an unknown structure tag %%%s%%. Use: %%%s%%.', esc_textarea( $tag ), esc_textarea( implode( '% %', self::TAGS ) ) ) );
			}
		}
		if ( substr_count( $structure, '%' ) !== 2 * count( $found[1] ) ) {
			throw new ToolError( 'The structure has a stray "%"; structure tags look like %postname%.' );
		}
	}

	/**
	 * Current settings.
	 *
	 * @return array{structure: string, category_base: string, tag_base: string}
	 */
	private static function read(): array {
		$values = array();
		foreach ( self::OPTIONS as $field => $option ) {
			$values[ $field ] = (string) get_option( $option, '' );
		}
		return $values;
	}

	/**
	 * Throws unless the settings are still as assessed.
	 *
	 * @param array<string, mixed> $expected Preconditions.
	 * @param string               $what     What would have been done.
	 * @return array{structure: string, category_base: string, tag_base: string}
	 * @throws ToolError When they changed.
	 */
	private static function unchanged( array $expected, string $what ): array {
		$current = self::read();
		if ( ! isset( $expected['fingerprint'] ) || Canonical::hash( $current ) !== $expected['fingerprint'] ) {
			throw new ToolError( sprintf( 'The permalink settings changed after the request was assessed; nothing was %s.', esc_textarea( $what ) ) );
		}
		return $current;
	}

	/**
	 * Writes the three settings through WP_Rewrite (bases stored with a leading slash, as the Permalinks screen stores
	 * them) and flushes rewrite rules without touching server files.
	 *
	 * @param array<string, string> $values Settings, bases without slashes.
	 */
	private static function write( array $values ): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( $values['structure'] );
		$wp_rewrite->set_category_base( '' === $values['category_base'] ? '' : '/' . $values['category_base'] );
		$wp_rewrite->set_tag_base( '' === $values['tag_base'] ? '' : '/' . $values['tag_base'] );
		// The category and tag permastructs were registered at init from the old bases; core's Permalinks screen flushes
		// on the next page load instead. Re-register them (as core does on change_locale) so this flush uses the new bases.
		create_initial_taxonomies();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- an approved permalink change; soft flush, as the Permalinks screen does.
		flush_rewrite_rules( false );
	}

	/**
	 * The snapshot in a rollback reference.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return array{structure: string, category_base: string, tag_base: string}
	 */
	private static function snapshot( array $rollback ): array {
		$values = array();
		foreach ( array_keys( self::OPTIONS ) as $field ) {
			$values[ $field ] = isset( $rollback['before'][ $field ] ) ? (string) $rollback['before'][ $field ] : '';
		}
		return $values;
	}
}
