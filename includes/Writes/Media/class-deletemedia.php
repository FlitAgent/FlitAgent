<?php
/**
 * Write tool: delete_media.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Media;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `delete_media`: permanently deletes one attachment and its files (attachments skip the trash). Always destructive,
 * so it always needs an administrator's approval; the preview lists the posts that use it. No rollback.
 */
final class DeleteMedia implements WriteTool {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'delete_media';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: delete a media item',
			'Permanently deletes one media library item (attachment) and its files, including every generated size. It cannot be rolled back, so it always returns approval_required; the preview lists posts that use it as featured image or link its file in their content.',
			array(
				'attachment_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the attachment to delete.',
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
		return array( Risk::DESTRUCTIVE );
	}

	/**
	 * Never auto-approved.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the deletion. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		$id   = (int) $args['attachment_id'];
		$post = Library::attachment( $id );
		if ( ! $post || ! current_user_can( 'delete_post', $id ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Attachment %d was not found or you cannot delete it.', $id ) );
		}

		$references = Library::references( $post );
		$used       = Library::count( $references );
		return Assessment::destructive(
			sprintf(
				'Permanently delete %s and its files (%s). %s This cannot be undone.',
				Summary::named_with_id( 'attachment', (string) $post->post_title, $id ),
				Library::file( $post ),
				$used > 0 || $references['truncated'] ? Library::used_note( $used, (bool) $references['truncated'] ) : 'No post uses it as featured image or links its file.'
			),
			array(
				'attachment_id' => $id,
				'before'        => self::described( $post ),
				'after'         => array(
					'title'     => '',
					'file'      => '',
					'mime_type' => '',
				),
				'references'    => $references,
			),
			self::state( $post, $references )
		);
	}

	/**
	 * Deletes the attachment and its files, if it and its references are still as assessed.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the attachment changed or WordPress could not delete it.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$id   = (int) $args['attachment_id'];
		$post = Library::attachment( $id );
		if ( ! $post || self::state( $post, Library::references( $post ) ) !== $assessment->preconditions() ) {
			throw new ToolError( sprintf( 'Attachment %d changed while the deletion was being applied; nothing was deleted.', (int) $id ) );
		}
		if ( ! wp_delete_attachment( $id, true ) ) {
			throw new ToolError( sprintf( 'WordPress could not delete attachment %d.', (int) $id ) );
		}

		return new Outcome(
			array(
				'attachment_id' => $id,
				'deleted'       => true,
			) + self::described( $post ),
			Rollback::none()
		);
	}

	/**
	 * Confirms the attachment is gone.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return Library::attachment( (int) $args['attachment_id'] ) ? $outcome->with_verification_failure( 'The attachment still exists after the deletion.' ) : $outcome;
	}

	/**
	 * Title, file and type.
	 *
	 * @param object $post Attachment.
	 * @return array<string, string>
	 */
	private static function described( $post ): array {
		return array(
			'title'     => (string) $post->post_title,
			'file'      => Library::file( $post ),
			'mime_type' => (string) $post->post_mime_type,
		);
	}

	/**
	 * Preconditions: the attachment's fingerprint and who uses it.
	 *
	 * @param object               $post       Attachment.
	 * @param array<string, mixed> $references Library::references() result.
	 * @return array<string, mixed>
	 */
	private static function state( $post, array $references ): array {
		return array(
			'fingerprint' => Library::fingerprint( $post ),
			'references'  => Library::reference_ids( $references ),
		);
	}
}
