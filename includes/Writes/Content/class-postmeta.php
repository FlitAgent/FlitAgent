<?php
/**
 * Shared rules of the post meta write tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Content;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Redactor;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Builders\BreakdanceEngine;
use FlitAgent\Writes\Builders\BricksEngine;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\Summary;

defined( 'ABSPATH' ) || exit;

/**
 * Which meta keys set_post_meta and delete_post_meta may touch, snapshots of every value of a key, and the shared
 * rollback that restores them.
 */
final class PostMeta {

	/**
	 * Page-builder layout and settings keys (builder saves are a separate package).
	 */
	const BUILDER_KEYS = '/^(_elementor|_bricks|_breakdance|breakdance_|_oxygen|ct_builder|_fl_builder|fl_builder)/i';

	/**
	 * Builders among BUILDER_KEYS that FlitAgent has layout tools for: key pattern => builder name and its save tools.
	 * The refusal names the tools for the keys they write (layout_keys()) and says the builder's other keys are not
	 * written at all.
	 */
	const TOOL_KEYS = array(
		'/^_elementor/i'                        => array( 'Elementor', array( 'save_elementor_page', 'save_elementor_template' ) ),
		'/^_bricks/i'                           => array( 'Bricks', array( 'save_bricks_page' ) ),
		'/^(_breakdance|breakdance_|_oxygen)/i' => array( 'Breakdance or Oxygen 6', array( 'save_breakdance_page' ) ),
	);

	/**
	 * Builders among BUILDER_KEYS that FlitAgent has no layout tools for, by name: their refusal says so. Oxygen 6 runs
	 * on Breakdance and keeps its layout in `_oxygen_` keys, which save_breakdance_page writes; classic Oxygen's
	 * `ct_builder` keys have no tool.
	 */
	const NO_TOOL_KEYS = array(
		'Beaver Builder' => '/^_?fl_builder/i',
		'classic Oxygen' => '/^ct_builder/i',
	);

	/**
	 * The meta keys a builder's save tools write (their SaveLayout::meta_keys()).
	 *
	 * @param string $builder Builder name from TOOL_KEYS.
	 * @return string[]
	 */
	private static function layout_keys( string $builder ): array {
		switch ( $builder ) {
			case 'Elementor':
				return array( '_elementor_data', '_elementor_edit_mode', '_elementor_template_type', '_elementor_version' );
			case 'Bricks':
				return array( BricksEngine::content_key(), BricksEngine::mode_key() );
			default:
				return array( BreakdanceEngine::data_key() );
		}
	}

	/**
	 * The `key` property.
	 *
	 * @return array<string, mixed>
	 */
	public static function key_property(): array {
		return array(
			'type'        => 'string',
			'minLength'   => 1,
			'maxLength'   => 255,
			'description' => 'Meta key. Keys starting with "_" only when registered for the REST API; page-builder, code-snippet and secret-looking keys are refused.',
		);
	}

	/**
	 * The post (untrashed, `edit_post`) and the key's current values, or a refusal. Read-only.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $key        Meta key.
	 * @param string $capability `edit_post_meta` or `delete_post_meta`.
	 * @return array{0: object, 1: array<int, mixed>, 2: array<string, mixed>|null}|Assessment The post, the values and
	 *         the registration (or null).
	 */
	public static function target( int $post_id, string $key, string $capability ) {
		$post = Posts::find_untrashed( $post_id, 'edit_post' );
		if ( $post instanceof Assessment ) {
			return $post;
		}

		$registered = self::registration( (string) $post->post_type, $key );
		$refusal    = self::key_refusal( $key, $registered );
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( ! current_user_can( $capability, $post_id, $key ) ) {
			return Assessment::prohibited( 'forbidden', sprintf( 'You cannot change meta key %s of post %d.', $key, $post_id ) );
		}

		$values = self::values( $post_id, $key );
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				return Assessment::prohibited( 'structured', sprintf( 'Meta key %s of post %d holds structured data (an array or object); FlitAgent only writes plain text values.', $key, $post_id ) );
			}
		}
		return array( $post, $values, $registered );
	}

	/**
	 * Why a key may not be written, or null.
	 *
	 * @param string                    $key        Meta key.
	 * @param array<string, mixed>|null $registered Registration from register_post_meta(), or null.
	 * @return Assessment|null
	 */
	public static function key_refusal( string $key, ?array $registered ): ?Assessment {
		if ( Redactor::is_secret_key( $key ) ) {
			return Assessment::prohibited( 'protected_meta', sprintf( 'Meta key %s looks like it holds a secret, so FlitAgent does not write it.', $key ) );
		}
		foreach ( self::NO_TOOL_KEYS as $builder => $pattern ) {
			if ( 1 === preg_match( $pattern, $key ) ) {
				return Assessment::prohibited( 'protected_meta', sprintf( 'Meta key %1$s holds %2$s layout or settings. FlitAgent does not edit %2$s layouts, so it does not write it.', $key, $builder ) );
			}
		}
		foreach ( self::TOOL_KEYS as $pattern => $tool ) {
			if ( 1 !== preg_match( $pattern, $key ) ) {
				continue;
			}
			list( $builder, $tools ) = $tool;
			$layout                  = self::layout_keys( $builder );
			if ( in_array( $key, $layout, true ) ) {
				return Assessment::prohibited( 'protected_meta', sprintf( 'Meta key %s holds the %s layout; it is only written through %s.', $key, $builder, implode( ' or ', $tools ) ) );
			}
			return Assessment::prohibited(
				'protected_meta',
				sprintf(
					'Meta key %s holds %s settings or generated data, which FlitAgent does not write. %s %s only its layout %s: %s.',
					$key,
					$builder,
					Summary::join( $tools ),
					1 === count( $tools ) ? 'writes' : 'write',
					1 === count( $layout ) ? 'key' : 'keys',
					Summary::join( $layout )
				)
			);
		}//end foreach
		if ( 1 === preg_match( self::BUILDER_KEYS, $key ) ) {
			return Assessment::prohibited( 'protected_meta', sprintf( 'Meta key %s holds page-builder layout or settings; it is only written through the page-builder tools.', $key ) );
		}
		if ( false !== stripos( $key, 'wpcode' ) ) {
			return Assessment::prohibited( 'protected_meta', sprintf( 'Meta key %s belongs to code snippets; FlitAgent never writes code.', $key ) );
		}
		if ( '_thumbnail_id' === $key ) {
			return Assessment::prohibited( 'protected_meta', 'The featured image (_thumbnail_id) is protected meta; set it with update_post or create_post (featured_media), which checks the attachment.' );
		}
		if ( '_wp_page_template' === $key ) {
			return Assessment::prohibited( 'protected_meta', 'The page template is set with update_post (template), which checks it against the theme.' );
		}
		$in_rest = null !== $registered && ! empty( $registered['show_in_rest'] );
		if ( is_protected_meta( $key, 'post' ) && ! $in_rest ) {
			return Assessment::prohibited( 'protected_meta', sprintf( 'Meta key %s is protected (hidden) and not registered for the REST API, so FlitAgent does not write it.', $key ) );
		}
		if ( $in_rest && isset( $registered['type'] ) && in_array( $registered['type'], array( 'array', 'object' ), true ) ) {
			return Assessment::prohibited( 'structured', sprintf( 'Meta key %s is registered as structured data (%s); FlitAgent only writes plain values.', $key, $registered['type'] ) );
		}
		return null;
	}

	/**
	 * The key's registration for this post type (or for all post types), or null.
	 *
	 * @param string $type Post type.
	 * @param string $key  Meta key.
	 * @return array<string, mixed>|null
	 */
	public static function registration( string $type, string $key ): ?array {
		foreach ( array( $type, '' ) as $subtype ) {
			$keys = get_registered_meta_keys( 'post', $subtype );
			if ( isset( $keys[ $key ] ) && is_array( $keys[ $key ] ) ) {
				return $keys[ $key ];
			}
		}
		return null;
	}

	/**
	 * Checks a value: never serialized (prohibited), and canonical for registered integer, number and boolean keys.
	 *
	 * @param string                    $key        Meta key.
	 * @param string                    $value      Value.
	 * @param array<string, mixed>|null $registered Registration, or null.
	 * @return Assessment|null A refusal, or null.
	 * @throws ToolError When the value does not fit the registered type.
	 */
	public static function value_refusal( string $key, string $value, ?array $registered ): ?Assessment {
		if ( is_serialized( $value ) ) {
			return Assessment::prohibited( 'serialized_value', sprintf( 'The value for %s is PHP-serialized data; FlitAgent refuses serialized values.', $key ) );
		}
		$type     = null !== $registered && ! empty( $registered['show_in_rest'] ) && isset( $registered['type'] ) ? (string) $registered['type'] : 'string';
		$patterns = array(
			'integer' => array( '/^-?\d+$/', 'an integer such as 12' ),
			'number'  => array( '/^-?\d+(\.\d+)?$/', 'a number such as 1.5' ),
			'boolean' => array( '/^1?$/', '"1" (true) or "" (false)' ),
		);
		if ( isset( $patterns[ $type ] ) && 1 !== preg_match( $patterns[ $type ][0], $value ) ) {
			throw new ToolError( sprintf( 'The value for %s must be %s.', esc_textarea( $key ), esc_textarea( $patterns[ $type ][1] ) ) );
		}
		return null;
	}

	/**
	 * Every value of a key, in row order.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return array<int, mixed>
	 */
	public static function values( int $post_id, string $key ): array {
		$values = get_post_meta( $post_id, $key, false );
		return is_array( $values ) ? array_values( $values ) : array();
	}

	/**
	 * Preconditions: the key's values and the post's status.
	 *
	 * @param object            $post   Post.
	 * @param string            $key    Meta key.
	 * @param array<int, mixed> $values Values.
	 * @return array<string, string>
	 */
	public static function state( $post, string $key, array $values ): array {
		return array(
			'fingerprint' => self::fingerprint( $key, $values ),
			'status'      => (string) $post->post_status,
		);
	}

	/**
	 * Fingerprint of a key's values.
	 *
	 * @param string            $key    Meta key.
	 * @param array<int, mixed> $values Values.
	 * @return string
	 */
	public static function fingerprint( string $key, array $values ): string {
		return Canonical::hash( array( $key, $values ) );
	}

	/**
	 * Preview map: nothing for no value, the value for one, a JSON list for several.
	 *
	 * @param string            $key    Meta key.
	 * @param array<int, mixed> $values Values.
	 * @return array<string, string>
	 */
	public static function display( string $key, array $values ): array {
		if ( array() === $values ) {
			return array();
		}
		if ( 1 === count( $values ) && is_string( $values[0] ) ) {
			return array( $key => $values[0] );
		}
		return array( $key => Canonical::encode( $values ) );
	}

	/**
	 * Throws unless the key still has the assessed values.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param string               $key      Meta key.
	 * @param array<string, mixed> $expected Preconditions.
	 * @return array<int, mixed> Current values.
	 * @throws ToolError When the post or values changed.
	 */
	public static function expect( int $post_id, string $key, array $expected ): array {
		$post   = get_post( $post_id );
		$values = self::values( $post_id, $key );
		if ( ! $post || self::state( $post, $key, $values ) !== $expected ) {
			throw new ToolError( sprintf( 'Meta key %s of post %d changed after it was assessed; nothing was changed.', esc_textarea( $key ), (int) $post_id ) );
		}
		return $values;
	}

	/**
	 * Replaces every value of a key with `$values` (none deletes the key).
	 *
	 * @param int               $post_id Post ID.
	 * @param string            $key     Meta key.
	 * @param array<int, mixed> $current Current values.
	 * @param array<int, mixed> $values  New values.
	 * @throws ToolError When WordPress refused.
	 */
	public static function replace( int $post_id, string $key, array $current, array $values ): void {
		if ( $current === $values ) {
			return;
		}
		$ok = true;
		if ( 1 === count( $values ) && count( $current ) <= 1 ) {
			$ok = (bool) update_post_meta( $post_id, $key, wp_slash( $values[0] ) );
		} else {
			if ( array() !== $current ) {
				$ok = (bool) delete_post_meta( $post_id, $key );
			}
			foreach ( $values as $value ) {
				$ok = $ok && (bool) add_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}
		if ( ! $ok ) {
			throw new ToolError( sprintf( 'WordPress could not save meta key %s of post %d.', esc_textarea( $key ), (int) $post_id ) );
		}
	}

	/**
	 * The outcome of a meta write: snapshot rollback of the previous values and the new fingerprint.
	 *
	 * @param int               $post_id Post ID.
	 * @param string            $key     Meta key.
	 * @param array<int, mixed> $before  Previous values.
	 * @return Outcome
	 */
	public static function outcome( int $post_id, string $key, array $before ): Outcome {
		return new Outcome(
			array(
				'post_id' => $post_id,
				'key'     => $key,
			),
			Rollback::make( Rollback::SNAPSHOT, 'post_meta', $post_id, array( 'key' => $key ), array( 'values' => $before ) ),
			array( 'fingerprint' => self::fingerprint( $key, self::values( $post_id, $key ) ) )
		);
	}

	/**
	 * Assesses restoring a key's snapshotted values, which waits for a person like setting them. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public static function assess_restore( array $rollback ): Assessment {
		$post_id = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$key     = isset( $rollback['refs']['key'] ) ? (string) $rollback['refs']['key'] : '';
		$post    = Posts::find_untrashed( $post_id, 'edit_post' );
		if ( $post instanceof Assessment ) {
			return $post;
		}
		$refusal = self::key_refusal( $key, self::registration( (string) $post->post_type, $key ) );
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( ! current_user_can( 'edit_post_meta', $post_id, $key ) ) {
			return Assessment::prohibited( 'forbidden', sprintf( 'You cannot change meta key %s of post %d.', $key, $post_id ) );
		}

		$current  = self::values( $post_id, $key );
		$restored = self::snapshot( $rollback );
		return Assessment::reversible(
			( array() === $restored
				? sprintf( 'Remove meta key %s from %s; it did not exist before the change.', $key, Posts::name( $post ) )
				: sprintf( 'Restore meta key %s of %s to its %s.', $key, Posts::name( $post ), Summary::count( count( $restored ), 'previous value', 'previous values' ) ) ) . Posts::live_note( $post ),
			array(
				'post_id'  => $post_id,
				'key'      => $key,
				'current'  => self::display( $key, $current ),
				'restored' => self::display( $key, $restored ),
			),
			self::state( $post, $key, $current ),
			false
		);
	}

	/**
	 * Restores a key's snapshotted values, if they are still as assessed.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @param array<string, mixed> $expected Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public static function restore( array $rollback, array $expected ): Outcome {
		$post_id  = (int) $rollback['target']['id'];
		$key      = (string) $rollback['refs']['key'];
		$restored = self::snapshot( $rollback );
		self::replace( $post_id, $key, self::expect( $post_id, $key, $expected ), $restored );

		$outcome = new Outcome(
			array(
				'post_id' => $post_id,
				'key'     => $key,
			),
			Rollback::none()
		);
		return self::values( $post_id, $key ) === $restored ? $outcome : $outcome->with_warning( 'restored_differently', sprintf( 'WordPress stored the restored values of %s differently from the snapshot.', $key ) );
	}

	/**
	 * Snapshotted values (strings only).
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return array<int, string>
	 */
	private static function snapshot( array $rollback ): array {
		$values = isset( $rollback['before']['values'] ) && is_array( $rollback['before']['values'] ) ? $rollback['before']['values'] : array();
		return array_values( array_map( 'strval', array_filter( $values, 'is_string' ) ) );
	}
}
