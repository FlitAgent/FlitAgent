<?php
/**
 * Write tool: upload_media_from_url.
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
 * `upload_media_from_url`: downloads a raster image from a public URL into the media library (Download does the
 * SSRF-safe fetch and type and size checks), with an optional title, alt text and parent post. Reversible: rollback
 * deletes the created attachment and its files. Never auto-approved: the bytes are unknown until after the approval, so a
 * person decides on the address. Text inside the file (IPTC, EXIF, XMP) is not stored as a title, caption or alt text, so
 * what is stored is what the preview shows.
 *
 * The assessment checks the URL and resolves its host (DNS only, no HTTP request); the bytes are checked when apply()
 * downloads them, so a type or size problem is a failed write with nothing stored.
 */
final class UploadMediaFromUrl implements WriteTool, Reversible {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'upload_media_from_url';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: upload an image from a URL',
			'Downloads one image from a public http(s) URL into the media library and returns the attachment id and URL. Only raster images (JPEG, PNG, GIF, WebP, AVIF, HEIC, BMP, TIFF) that the site allows are stored, detected from the file itself; SVG is refused. The size limit is 10 MB unless the site sets another. URLs that point at this server, a private network or cloud metadata (directly, through DNS or through a redirect) are refused with blocked_url. Can be rolled back with rollback_change, which deletes the attachment and its files.',
			array(
				'url'            => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 2048,
					'description' => 'http:// or https:// URL of the image (port 80, 443 or 8080).',
				),
				'title'          => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 1000,
					'description' => 'Attachment title. Default: the file name.',
				),
				'alt_text'       => array(
					'type'        => 'string',
					'maxLength'   => 1000,
					'description' => 'Alternative text describing the image (plain text).',
				),
				'parent_post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Post to attach the image to (you must be able to edit it). It is not inserted into the content or set as featured image.',
				),
			),
			array( 'url' ),
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
		return array( Risk::REVERSIBLE );
	}

	/**
	 * Never auto-approves.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Checks permissions, the parent post and the URL. Read-only: DNS lookups, no HTTP request.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 */
	public function assess( array $args ): Assessment {
		if ( ! current_user_can( 'upload_files' ) ) {
			return Assessment::prohibited( 'forbidden', 'You cannot upload files on this site.' );
		}

		$parent = isset( $args['parent_post_id'] ) ? (int) $args['parent_post_id'] : 0;
		if ( $parent > 0 ) {
			$post = get_post( $parent );
			if ( ! $post || in_array( $post->post_type, array( 'attachment', 'revision' ), true ) || ! current_user_can( 'edit_post', $parent ) ) {
				return Assessment::prohibited( 'not_found', sprintf( 'Post %d was not found or you cannot edit it.', $parent ) );
			}
		}

		try {
			$target = Download::target( (string) $args['url'] );
		} catch ( Refusal $e ) {
			return Assessment::prohibited( $e->reason(), $e->describe() );
		}
		if ( 1 === preg_match( '/\.svgz?$/i', (string) wp_parse_url( $target['url'], PHP_URL_PATH ) ) ) {
			return Assessment::prohibited( 'unsupported_type', 'unsupported_type: SVG files are not accepted; only raster images are.' );
		}

		$title = isset( $args['title'] ) ? (string) $args['title'] : null;
		$alt   = isset( $args['alt_text'] ) ? sanitize_text_field( (string) $args['alt_text'] ) : '';

		return Assessment::reversible(
			sprintf( 'Download the image at %s into the media library%s.', $target['url'], $parent > 0 ? sprintf( ' and attach it to post %d', $parent ) : '' ),
			array(
				'url'       => $target['url'],
				'host'      => $target['host'],
				'addresses' => $target['addresses'],
				'max_bytes' => Download::max_bytes(),
				'before'    => array(),
				'after'     => array(
					'url'            => $target['url'],
					'title'          => null === $title ? '(the file name)' : $title,
					'alt_text'       => $alt,
					'parent_post_id' => $parent > 0 ? (string) $parent : '',
				),
			),
			array(
				'url'    => $target['url'],
				'parent' => $parent,
			),
			false
		);
	}

	/**
	 * Downloads the image and creates the attachment.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the download is refused or WordPress cannot store it.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		self::load_admin_functions();

		try {
			$download = Download::fetch( (string) $assessment->preconditions()['url'], Download::max_bytes() );
		} catch ( Refusal $e ) {
			throw new ToolError( esc_textarea( $e->describe() ) . ' Nothing was stored.' );
		}

		$parent = (int) $assessment->preconditions()['parent'];
		$title  = isset( $args['title'] ) ? wp_slash( (string) $args['title'] ) : null;
		// Core fills a title, a description and alt text from what the file says about itself, and keeps the same text in the
		// attachment's image data. Nobody judged or saw that text, so for this upload it reads as empty.
		$blank = static function ( $meta ) {
			return is_array( $meta ) ? array_merge(
				$meta,
				array(
					'title'   => '',
					'caption' => '',
					'alt'     => '',
				)
			) : $meta;
		};
		add_filter( 'wp_read_image_metadata', $blank, PHP_INT_MAX );
		try {
			$id = media_handle_sideload(
				array(
					'name'     => $download['name'],
					'tmp_name' => $download['file'],
				),
				$parent,
				$title
			);
		} finally {
			remove_filter( 'wp_read_image_metadata', $blank, PHP_INT_MAX );
		}
		if ( file_exists( $download['file'] ) ) {
			wp_delete_file( $download['file'] );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			throw new ToolError( 'WordPress could not store the image: ' . ( is_wp_error( $id ) ? esc_textarea( $id->get_error_message() ) : 'unknown error' ) . ' Nothing was stored.' );
		}

		$id  = (int) $id;
		$alt = isset( $args['alt_text'] ) ? sanitize_text_field( (string) $args['alt_text'] ) : '';
		if ( '' !== $alt ) {
			update_post_meta( $id, Library::ALT, wp_slash( $alt ) );
		}

		$post = Library::attachment( $id );
		return new Outcome(
			array(
				'attachment_id' => $id,
				'url'           => (string) wp_get_attachment_url( $id ),
				'mime_type'     => $download['mime'],
				'bytes'         => $download['bytes'],
				'source_url'    => $download['url'],
			),
			Rollback::make( Rollback::SNAPSHOT, 'attachment', $id, array( 'file' => $post ? Library::file( $post ) : '' ), array( 'existed' => false ) ),
			array( 'fingerprint' => $post ? Library::fingerprint( $post ) : '' )
		);
	}

	/**
	 * Reads the attachment back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$post = Library::attachment( (int) $outcome->result()['attachment_id'] );
		if ( ! $post ) {
			return $outcome->with_verification_failure( 'The attachment could not be read back after the upload.' );
		}

		$differs = array();
		if ( isset( $args['title'] ) && (string) $post->post_title !== (string) $args['title'] ) {
			$differs[] = 'title';
		}
		if ( isset( $args['alt_text'] ) && Library::alt( $post ) !== sanitize_text_field( (string) $args['alt_text'] ) ) {
			$differs[] = 'alt_text';
		}
		$parent = isset( $args['parent_post_id'] ) ? (int) $args['parent_post_id'] : 0;
		if ( (int) $post->post_parent !== $parent ) {
			$differs[] = 'parent_post_id';
		}
		if ( ! in_array( (string) $post->post_mime_type, Download::RASTER, true ) ) {
			$differs[] = 'mime_type';
		}
		if ( array() === $differs ) {
			return $outcome;
		}
		return $outcome->with_warning( 'stored_differently', sprintf( 'WordPress stored the %s differently from the request.', implode( ', ', $differs ) ) );
	}

	/**
	 * Assesses deleting the uploaded attachment. Needs approval when a post uses it (or the fingerprint changed, which
	 * the Gateway checks). Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$id   = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$post = Library::attachment( $id );
		if ( ! $post || ! current_user_can( 'delete_post', $id ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Attachment %d was not found or you cannot delete it.', $id ) );
		}

		$references = Library::references( $post );
		$summary    = sprintf( 'Delete %s and its files (%s), undoing the upload.', Summary::named_with_id( 'attachment', (string) $post->post_title, $id ), Library::file( $post ) );
		$preview    = array(
			'attachment_id' => $id,
			'current'       => array(
				'title' => (string) $post->post_title,
				'file'  => Library::file( $post ),
			),
			'restored'      => array(
				'title' => '',
				'file'  => '',
			),
			'references'    => $references,
		);
		$state      = self::rollback_state( $post, $references );

		$used = Library::count( $references );
		if ( $used > 0 || $references['truncated'] ) {
			return Assessment::destructive( $summary . ' ' . Library::used_note( $used, (bool) $references['truncated'] ), $preview, $state );
		}
		return Assessment::reversible( $summary, $preview, $state, true );
	}

	/**
	 * Deletes the attachment and its files, if it is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the attachment changed or could not be deleted.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$id   = (int) $rollback['target']['id'];
		$post = Library::attachment( $id );
		if ( ! $post || self::rollback_state( $post, Library::references( $post ) ) !== $expected_state ) {
			throw new ToolError( sprintf( 'Attachment %d changed after the rollback was assessed; nothing was deleted.', (int) $id ) );
		}
		if ( ! wp_delete_attachment( $id, true ) ) {
			throw new ToolError( sprintf( 'WordPress could not delete attachment %d.', (int) $id ) );
		}

		$outcome = new Outcome(
			array(
				'attachment_id' => $id,
				'deleted'       => true,
			),
			Rollback::none()
		);
		return Library::attachment( $id ) ? $outcome->with_verification_failure( 'The attachment still exists after the rollback.' ) : $outcome;
	}

	/**
	 * Rollback preconditions: the attachment's fingerprint and who uses it.
	 *
	 * @param object               $post       Attachment.
	 * @param array<string, mixed> $references Library::references() result.
	 * @return array<string, mixed>
	 */
	private static function rollback_state( $post, array $references ): array {
		return array(
			'fingerprint' => Library::fingerprint( $post ),
			'references'  => Library::reference_ids( $references ),
		);
	}

	/**
	 * Loads the wp-admin media functions (not loaded on REST requests).
	 */
	private static function load_admin_functions(): void {
		if ( function_exists( 'media_handle_sideload' ) || ! file_exists( ABSPATH . 'wp-admin/includes/media.php' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}
