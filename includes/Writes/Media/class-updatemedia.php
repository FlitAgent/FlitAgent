<?php
/**
 * Write tool: update_media.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Media;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `update_media`: changes the title, alt text, caption and/or description of one attachment. Reversible: the previous
 * values are kept as a snapshot in the receipt and restored by rollback. Never auto-approved, nor is its rollback: the
 * item may already be shown on published pages, and core prints a caption unfiltered in gallery output.
 */
final class UpdateMedia implements WriteTool, Reversible {

	/**
	 * Argument => post field (null: the alt text meta), in preview order.
	 */
	const FIELDS = array(
		'title'       => 'post_title',
		'alt_text'    => null,
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
	);

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'update_media';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: update a media item',
			'Changes the title, alt text, caption and/or description of one media library item (attachment). Alt text is plain text. Can be rolled back with rollback_change. Always returns approval_required: an administrator approves it in wp-admin.',
			array(
				'attachment_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the attachment.',
				),
				'title'         => array(
					'type'        => 'string',
					'maxLength'   => 1000,
					'description' => 'New title.',
				),
				'alt_text'      => array(
					'type'        => 'string',
					'maxLength'   => 1000,
					'description' => 'New alternative text (plain text; tags are removed).',
				),
				'caption'       => array(
					'type'        => 'string',
					'maxLength'   => 10000,
					'description' => 'New caption.',
				),
				'description'   => array(
					'type'        => 'string',
					'maxLength'   => 100000,
					'description' => 'New description.',
				),
			),
			array( 'attachment_id' ),
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
	 * Never auto-approved: the item may already be shown on published pages.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the update. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When no field is given or nothing would change.
	 */
	public function assess( array $args ): Assessment {
		$after = self::requested( $args );
		if ( array() === $after ) {
			throw new ToolError( 'Give at least one of title, alt_text, caption, description.' );
		}

		$id   = (int) $args['attachment_id'];
		$post = self::editable( $id );
		if ( $post instanceof Assessment ) {
			return $post;
		}

		$before = self::values( $post, array_keys( $after ) );
		if ( $before === $after ) {
			throw new ToolError( sprintf( 'Attachment %d already has that %s; nothing to change.', (int) $id, esc_textarea( implode( ', ', array_keys( $after ) ) ) ) );
		}

		return Assessment::reversible(
			sprintf( 'Update the %s of the attachment %s.', Summary::fields( array_keys( $after ) ), Summary::named( (string) $post->post_title, sprintf( '%d', $id ) ) ),
			array(
				'attachment_id' => $id,
				'file'          => Library::file( $post ),
				'before'        => $before,
				'after'         => $after,
			),
			self::state( $post ),
			false
		);
	}

	/**
	 * Updates the attachment, if it is still as assessed.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the attachment changed or WordPress refused the update.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$id   = (int) $args['attachment_id'];
		$post = Library::attachment( $id );
		if ( ! $post || self::state( $post ) !== $assessment->preconditions() ) {
			throw new ToolError( sprintf( 'Attachment %d changed while the update was being applied; nothing was changed.', (int) $id ) );
		}

		$after                      = self::requested( $args );
		$before                     = self::values( $post, array_keys( $after ) );
		$before['alt_text_existed'] = (bool) metadata_exists( 'post', $id, Library::ALT );

		self::write( $id, $after );

		$post = Library::attachment( $id );
		return new Outcome(
			array(
				'attachment_id' => $id,
				'changed'       => array_keys( $after ),
			),
			Rollback::make( Rollback::SNAPSHOT, 'attachment', $id, array( 'fields' => array_keys( $after ) ), $before ),
			array( 'fingerprint' => $post ? Library::fingerprint( $post ) : '' )
		);
	}

	/**
	 * Reads the attachment back and compares the stored values with the request.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$post = Library::attachment( (int) $args['attachment_id'] );
		if ( ! $post ) {
			return $outcome->with_verification_failure( 'The attachment could not be read back after the update.' );
		}
		$after   = self::requested( $args );
		$stored  = self::values( $post, array_keys( $after ) );
		$differs = array_keys( array_diff_assoc( $after, $stored ) );
		if ( array() === $differs ) {
			return $outcome;
		}
		return $outcome->with_warning( 'stored_differently', sprintf( 'WordPress stored the %s differently from the request (for example a content filter changed it). Read the attachment to see what was saved.', implode( ', ', $differs ) ) );
	}

	/**
	 * Assesses restoring the snapshot. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$id   = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$post = self::editable( $id );
		if ( $post instanceof Assessment ) {
			return $post;
		}

		$fields   = self::rollback_fields( $rollback );
		$restored = array();
		foreach ( $fields as $field ) {
			$restored[ $field ] = isset( $rollback['before'][ $field ] ) ? (string) $rollback['before'][ $field ] : '';
		}

		return Assessment::reversible(
			sprintf( 'Restore the %s of the attachment %s.', Summary::fields( $fields ), Summary::named( (string) $post->post_title, sprintf( '%d', $id ) ) ),
			array(
				'attachment_id' => $id,
				'current'       => self::values( $post, $fields ),
				'restored'      => $restored,
			),
			self::state( $post ),
			false
		);
	}

	/**
	 * Restores the snapshot, if the attachment is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the attachment changed or WordPress refused the update.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$id   = (int) $rollback['target']['id'];
		$post = Library::attachment( $id );
		if ( ! $post || self::state( $post ) !== $expected_state ) {
			throw new ToolError( sprintf( 'Attachment %d changed after the rollback was assessed; nothing was restored.', (int) $id ) );
		}

		$values = array();
		foreach ( self::rollback_fields( $rollback ) as $field ) {
			$values[ $field ] = isset( $rollback['before'][ $field ] ) ? (string) $rollback['before'][ $field ] : '';
		}
		$remove_alt = isset( $values['alt_text'] ) && empty( $rollback['before']['alt_text_existed'] );
		if ( $remove_alt ) {
			unset( $values['alt_text'] );
		}

		self::write( $id, $values );
		if ( $remove_alt ) {
			delete_post_meta( $id, Library::ALT );
		}

		$outcome = new Outcome(
			array(
				'attachment_id' => $id,
				'restored'      => self::rollback_fields( $rollback ),
			),
			Rollback::none()
		);
		$after   = Library::attachment( $id );
		if ( ! $after ) {
			return $outcome->with_verification_failure( 'The attachment could not be read back after the rollback.' );
		}
		return array() === array_diff_assoc( $values, self::values( $after, array_keys( $values ) ) ) ? $outcome : $outcome->with_warning( 'restored_differently', 'WordPress stored the restored values differently from the snapshot.' );
	}

	/**
	 * Writes values (argument name => value): post fields in one update, alt text as meta.
	 *
	 * @param int                   $id     Attachment id.
	 * @param array<string, string> $values Values.
	 * @throws ToolError When WordPress refused the update.
	 */
	private static function write( int $id, array $values ): void {
		$postarr = array();
		foreach ( $values as $field => $value ) {
			if ( null !== self::FIELDS[ $field ] ) {
				$postarr[ self::FIELDS[ $field ] ] = $value;
			}
		}
		if ( array() !== $postarr ) {
			$updated = wp_update_post( wp_slash( array( 'ID' => $id ) + $postarr ), true );
			if ( is_wp_error( $updated ) || ! $updated ) {
				throw new ToolError( sprintf( 'WordPress could not update attachment %d.', (int) $id ) );
			}
		}
		if ( array_key_exists( 'alt_text', $values ) ) {
			update_post_meta( $id, Library::ALT, wp_slash( $values['alt_text'] ) );
		}
	}

	/**
	 * The attachment, or the same refusal for missing, not an attachment and not editable.
	 *
	 * @param int $id Attachment id.
	 * @return object|Assessment
	 */
	private static function editable( int $id ) {
		$post = Library::attachment( $id );
		if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Attachment %d was not found or you cannot edit it.', $id ) );
		}
		return $post;
	}

	/**
	 * Requested values (argument name => value) in FIELDS order; alt text sanitized like core REST.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, string>
	 */
	private static function requested( array $args ): array {
		$values = array();
		foreach ( array_keys( self::FIELDS ) as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$values[ $field ] = 'alt_text' === $field ? sanitize_text_field( (string) $args[ $field ] ) : (string) $args[ $field ];
			}
		}
		return $values;
	}

	/**
	 * Current values of the given fields.
	 *
	 * @param object   $post   Attachment.
	 * @param string[] $fields Argument names.
	 * @return array<string, string>
	 */
	private static function values( $post, array $fields ): array {
		$values = array();
		foreach ( $fields as $field ) {
			$values[ $field ] = null === self::FIELDS[ $field ] ? Library::alt( $post ) : (string) $post->{self::FIELDS[ $field ]};
		}
		return $values;
	}

	/**
	 * Fields a rollback restores (only known fields are ever accepted).
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return string[]
	 */
	private static function rollback_fields( array $rollback ): array {
		$fields = isset( $rollback['refs']['fields'] ) && is_array( $rollback['refs']['fields'] ) ? $rollback['refs']['fields'] : array();
		return array_values( array_intersect( array_keys( self::FIELDS ), $fields ) );
	}

	/**
	 * Preconditions: fingerprint and modification time.
	 *
	 * @param object $post Attachment.
	 * @return array<string, string>
	 */
	private static function state( $post ): array {
		return array(
			'fingerprint'       => Library::fingerprint( $post ),
			'post_modified_gmt' => (string) $post->post_modified_gmt,
		);
	}
}
