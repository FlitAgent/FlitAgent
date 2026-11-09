<?php
/**
 * Write tool: create_post.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Content;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Markup;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `create_post`: creates one item of a supported post type (Posts::type_refusal()), as a draft unless asked otherwise.
 * Reversible: rollback moves the item to the trash. Auto-approvable only for drafts and pending items whose text kses
 * would leave as it is (Markup). With the site's trash disabled there is no rollback, so the creation is destructive.
 */
final class CreatePost implements WriteTool, Reversible {

	/**
	 * Argument => post field.
	 */
	const FIELDS = array(
		'title'   => 'post_title',
		'content' => 'post_content',
		'excerpt' => 'post_excerpt',
		'slug'    => 'post_name',
		'date'    => 'post_date',
		'parent'  => 'post_parent',
	);

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'create_post';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: create a post',
			'Creates one post, page or custom post type item (any type shown in the REST API), as a draft unless status says otherwise, optionally with a featured image. Content is raw post content (block markup or HTML). Roll it back with rollback_change, which moves it to the trash. Drafts and pending items may be created at once if an administrator enabled auto-approve; publish, private and future return approval_required.',
			array(
				'post_type'      => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 20,
					'description' => 'Post type, e.g. post or page. Default post.',
				),
				'title'          => array(
					'type'        => 'string',
					'maxLength'   => 1000,
					'description' => 'Title.',
				),
				'content'        => array(
					'type'        => 'string',
					'maxLength'   => 2000000,
					'description' => 'Content.',
				),
				'excerpt'        => array(
					'type'        => 'string',
					'maxLength'   => 10000,
					'description' => 'Excerpt.',
				),
				'status'         => array(
					'type'        => 'string',
					'enum'        => Posts::STATUSES,
					'description' => 'Status. Default draft. publish, private and future need the publish capability; future needs a date in the future.',
				),
				'slug'           => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => 'Slug (WordPress may sanitize it or add a suffix to keep it unique).',
				),
				'date'           => array(
					'type'        => 'string',
					'pattern'     => Posts::DATE_PATTERN,
					'description' => 'Publish date in site time, YYYY-MM-DD HH:MM:SS.',
				),
				'parent'         => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Parent ID (hierarchical types only).',
				),
				'featured_media' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Featured image: the ID of an image in the Media Library (types that support featured images).',
				),
			),
			array(),
			$this->tiers(),
			false
		);
	}

	/**
	 * Tiers: reversible, destructive when the trash is disabled.
	 *
	 * @return string[]
	 */
	public function tiers(): array {
		return array( Risk::REVERSIBLE, Risk::DESTRUCTIVE );
	}

	/**
	 * May auto-approve (drafts and pending items, without code).
	 */
	public function may_auto_approve(): bool {
		return true;
	}

	/**
	 * Assesses the creation. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the arguments are invalid.
	 */
	public function assess( array $args ): Assessment {
		$type   = isset( $args['post_type'] ) ? (string) $args['post_type'] : 'post';
		$status = isset( $args['status'] ) ? (string) $args['status'] : 'draft';
		if ( '' === ( isset( $args['title'] ) ? (string) $args['title'] : '' ) && '' === ( isset( $args['content'] ) ? (string) $args['content'] : '' ) ) {
			throw new ToolError( 'Give a title or content.' );
		}

		$refusal = Posts::type_refusal( $type );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$object = get_post_type_object( $type );
		if ( ! current_user_can( $object->cap->create_posts ) ) {
			return Assessment::prohibited( 'forbidden', sprintf( 'You cannot create %s items.', $type ) );
		}
		$refusal = Posts::publish_refusal( $type, $status );
		if ( null !== $refusal ) {
			return $refusal;
		}

		if ( isset( $args['date'] ) ) {
			Posts::check_date( (string) $args['date'] );
		}
		if ( 'future' === $status && ( ! isset( $args['date'] ) || (string) $args['date'] <= Posts::now() ) ) {
			throw new ToolError( 'Status future needs a date in the future (give date).' );
		}
		if ( isset( $args['parent'] ) ) {
			Posts::check_parent( $type, (int) $args['parent'], 0 );
		}
		if ( isset( $args['featured_media'] ) ) {
			$refusal = Posts::check_featured( $type, (int) $args['featured_media'] );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}

		$after = array( 'post_type' => $type ) + self::fields( $args ) + array( 'post_status' => $status );
		if ( isset( $args['featured_media'] ) ) {
			$after['featured_media'] = (int) $args['featured_media'];
		}
		$label   = isset( $object->labels->singular_name ) ? strtolower( (string) $object->labels->singular_name ) : $type;
		$summary = sprintf( 'Create a %s %s "%s".', $status, $label, Posts::short( isset( $args['title'] ) ? (string) $args['title'] : '' ) );
		$preview = array(
			'post_type' => $type,
			'before'    => array(),
			'after'     => $after,
		);
		if ( ! Posts::trash_enabled() ) {
			return Assessment::destructive( $summary . ' The trash is disabled on this site, so it cannot be rolled back (only deleted permanently).', $preview, array( 'trash_enabled' => false ) );
		}
		return Assessment::reversible( $summary, $preview, array( 'trash_enabled' => true ), Posts::is_private( $status ) && Markup::is_safe_post( self::fields( $args ) ) );
	}

	/**
	 * Creates the post.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$postarr = array(
			'post_type'   => isset( $args['post_type'] ) ? (string) $args['post_type'] : 'post',
			'post_status' => isset( $args['status'] ) ? (string) $args['status'] : 'draft',
		) + self::fields( $args );
		if ( isset( $postarr['post_parent'] ) ) {
			$postarr['post_parent'] = (int) $postarr['post_parent'];
		}
		if ( isset( $postarr['post_date'] ) ) {
			$postarr['post_date_gmt'] = (string) get_gmt_from_date( $postarr['post_date'] );
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			throw new ToolError( 'WordPress could not create the post.' );
		}
		$post_id = (int) $post_id;
		if ( isset( $args['featured_media'] ) ) {
			Posts::set_featured( $post_id, (int) $args['featured_media'] );
		}
		$after = get_post( $post_id );

		return new Outcome(
			array(
				'post_id' => $post_id,
				'status'  => $after ? (string) $after->post_status : '',
			),
			Risk::REVERSIBLE === $assessment->tier() ? Rollback::make( Rollback::TRASH, 'post', $post_id, array( 'action' => 'trash' ) ) : Rollback::none(),
			array( 'fingerprint' => $after ? Posts::fingerprint( $after ) : '' )
		);
	}

	/**
	 * Reads the post back and compares the stored fields with the request.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$post = get_post( (int) $outcome->result()['post_id'] );
		if ( ! $post ) {
			return $outcome->with_verification_failure( 'The new post could not be read back.' );
		}
		$differs = array();
		foreach ( self::fields( $args ) + array( 'post_status' => isset( $args['status'] ) ? (string) $args['status'] : 'draft' ) as $field => $value ) {
			if ( (string) $post->$field !== (string) $value ) {
				$differs[] = 'post_status' === $field ? 'status' : (string) array_search( $field, self::FIELDS, true );
			}
		}
		if ( isset( $args['featured_media'] ) && Posts::featured_id( $post ) !== (int) $args['featured_media'] ) {
			$differs[] = 'featured_media';
		}
		if ( array() === $differs ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result(
			array(
				'stored_verbatim' => false,
				'differs'         => $differs,
			)
		)->with_warning( 'stored_differently', sprintf( 'WordPress stored the %s differently from the request (for example a content filter, a unique slug, or a future date that scheduled the post).', implode( ', ', $differs ) ) );
	}

	/**
	 * Assesses moving the created post to the trash. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		return Trashing::assess_trash( isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0, 'Undo the creation: move to the trash', true );
	}

	/**
	 * Moves the created post to the trash.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$post_id = (int) $rollback['target']['id'];
		return Trashing::verify( $post_id, 'trash', Trashing::trash( $post_id, $expected_state, null ) );
	}

	/**
	 * Requested post fields.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, string>
	 */
	private static function fields( array $args ): array {
		$fields = array();
		foreach ( self::FIELDS as $arg => $field ) {
			if ( array_key_exists( $arg, $args ) ) {
				$fields[ $field ] = (string) $args[ $arg ];
			}
		}
		return $fields;
	}
}
