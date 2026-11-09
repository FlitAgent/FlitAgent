<?php
/**
 * Write tool: update_post.
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
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `update_post`: changes the title, content, excerpt, status, slug, date, parent, page template, author and/or
 * featured image of one post of a supported type (Posts::find()). Always reversible: before a text change the old state is saved as a
 * revision, and the other fields are snapshotted in the receipt; rollback restores both. Auto-approvable only while the
 * post is a draft or pending, stays one, and keeps its author, and kses would leave the new values as they are (Markup).
 */
final class UpdatePost implements WriteTool, Reversible {

	/**
	 * Revisioned argument => post field.
	 */
	const FIELDS = array(
		'title'   => 'post_title',
		'content' => 'post_content',
		'excerpt' => 'post_excerpt',
	);

	/**
	 * Snapshotted argument => post field (`page_template` is the `_wp_page_template` meta, '' for the default;
	 * `featured_media` is the featured image attachment id, '0' for none).
	 */
	const ATTRIBUTES = array(
		'status'         => 'post_status',
		'slug'           => 'post_name',
		'date'           => 'post_date',
		'parent'         => 'post_parent',
		'template'       => 'page_template',
		'author'         => 'post_author',
		'featured_media' => 'featured_media',
	);

	/**
	 * Post fields WordPress may change without being asked; changed ones are added to the rollback snapshot.
	 */
	const SIDE_EFFECTS = array( 'post_status', 'post_name', 'post_date', 'post_date_gmt' );

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'update_post';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: update a post',
			'Changes the title, content, excerpt, status, slug, date, parent, page template, author and/or featured image of one post or page (any post type shown in the REST API; text changes need revisions). Content is raw post content (block markup or HTML). Saves a revision (text) and a snapshot (other fields) first so the change can be rolled back with rollback_change. Changes that keep a draft or pending post unpublished may be applied at once if an administrator enabled auto-approve; publishing, changes to live posts and author changes return approval_required.',
			array(
				'post_id'        => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the post to change.',
				),
				'title'          => array(
					'type'        => 'string',
					'maxLength'   => 1000,
					'description' => 'New title.',
				),
				'content'        => array(
					'type'        => 'string',
					'maxLength'   => 2000000,
					'description' => 'New full content, replacing the current content.',
				),
				'excerpt'        => array(
					'type'        => 'string',
					'maxLength'   => 10000,
					'description' => 'New excerpt.',
				),
				'status'         => array(
					'type'        => 'string',
					'enum'        => Posts::STATUSES,
					'description' => 'New status. publish, private and future need the publish capability; future needs a date in the future. To trash, use trash_post.',
				),
				'slug'           => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => 'New slug (WordPress may sanitize it or add a suffix to keep it unique).',
				),
				'date'           => array(
					'type'        => 'string',
					'pattern'     => Posts::DATE_PATTERN,
					'description' => 'New publish date in site time, YYYY-MM-DD HH:MM:SS.',
				),
				'parent'         => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'New parent ID (hierarchical types only; 0 for none).',
				),
				'template'       => array(
					'type'        => 'string',
					'maxLength'   => 255,
					'description' => 'Page template file or block template slug the theme offers for this type; empty string for the default template.',
				),
				'author'         => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'New author user ID (a user who can edit this type). Needs the edit-others capability.',
				),
				'featured_media' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Featured image: the ID of an image in the Media Library (types that support featured images); 0 removes it.',
				),
			),
			array( 'post_id' ),
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
	 * May auto-approve (drafts and pending posts that stay unpublished, without code).
	 */
	public function may_auto_approve(): bool {
		return true;
	}

	/**
	 * Assesses the update. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When no field is given, a value is invalid or nothing would change.
	 */
	public function assess( array $args ): Assessment {
		$changes = self::changes( $args );
		if ( array() === $changes ) {
			throw new ToolError( 'Give at least one of ' . esc_textarea( implode( ', ', array_keys( self::FIELDS + self::ATTRIBUTES ) ) ) . '.' );
		}

		$post_id = (int) $args['post_id'];
		$post    = Posts::find_untrashed( $post_id, 'edit_post' );
		if ( $post instanceof Assessment ) {
			return $post;
		}
		if ( array() !== array_intersect_key( $changes, array_flip( self::FIELDS ) ) ) {
			$refusal = self::revisions_refusal( $post );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		$refusal = $this->check_attributes( $post, $changes );
		if ( null !== $refusal ) {
			return $refusal;
		}

		$before = array();
		foreach ( array_keys( $changes ) as $field ) {
			$before[ $field ] = self::current( $post, $field );
		}
		if ( $before === $changes ) {
			throw new ToolError( sprintf( 'Post %d already has that %s; nothing to change.', (int) $post_id, esc_textarea( self::names( $changes ) ) ) );
		}

		$status  = isset( $changes['post_status'] ) ? $changes['post_status'] : (string) $post->post_status;
		$summary = sprintf( 'Update the %s of %s.', self::names( $changes ), Posts::name( $post ) ) . self::status_note( (string) $post->post_status, $status );
		if ( $status === $post->post_status && 'publish' === $status && isset( $changes['post_date'] ) && $changes['post_date'] > Posts::now() ) {
			$summary .= ' The new date is in the future, so WordPress will schedule it, which takes it off the live site until then.';
		} elseif ( $status === $post->post_status ) {
			$summary .= Posts::live_note( $post );
		}

		$preview = array(
			'post_id'   => $post_id,
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
			'before'    => $before,
			'after'     => $changes,
		);
		if ( isset( $changes['featured_media'] ) ) {
			$preview['featured_media_titles'] = array(
				'before' => Posts::featured_title( (int) $before['featured_media'] ),
				'after'  => Posts::featured_title( (int) $changes['featured_media'] ),
			);
		}
		return Assessment::reversible(
			$summary,
			$preview,
			Posts::state( $post ),
			Posts::is_private( (string) $post->post_status ) && Posts::is_private( $status ) && ! isset( $changes['post_author'] ) && Markup::is_safe_post( $changes )
		);
	}

	/**
	 * Saves a revision of the current state (text changes), then updates the post.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the post changed, no revision could be saved, or WordPress refused the update.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post || Posts::state( $post ) !== $assessment->preconditions() ) {
			throw new ToolError( sprintf( 'Post %d changed while the update was being applied; nothing was changed.', (int) $post_id ) );
		}

		$changes = self::changes( $args );
		$text    = array_values( array_intersect( self::FIELDS, array_keys( $changes ) ) );
		$fields  = array_values( array_intersect( self::ATTRIBUTES, array_keys( $changes ) ) );

		// Snapshot before writing: the template is read from live meta.
		$snapshot = array();
		foreach ( $fields as $field ) {
			$snapshot[ $field ] = self::current( $post, $field );
		}
		if ( in_array( 'post_date', $fields, true ) ) {
			$snapshot['post_date_gmt'] = (string) $post->post_date_gmt;
		}
		// Fields WordPress may change on its own (a slug and a fixed date when publishing, scheduling on a future date).
		$prior = array();
		foreach ( self::SIDE_EFFECTS as $field ) {
			$prior[ $field ] = (string) $post->$field;
		}

		$before_revision = 0;
		if ( array() !== $text ) {
			// The rollback point: a revision identical to the current state, saved now unless the latest one already is.
			wp_save_post_revision( $post_id );
			$before_revision = self::latest_revision_matching( $post );
			if ( 0 === $before_revision ) {
				throw new ToolError( sprintf( 'Could not save a revision of post %d, so nothing was changed.', (int) $post_id ) );
			}
		}

		$postarr = self::postarr( $changes );
		if ( array() !== $postarr || ! isset( $changes['featured_media'] ) ) {
			$updated = wp_update_post( wp_slash( array( 'ID' => $post_id ) + $postarr ), true );
			if ( is_wp_error( $updated ) || ! $updated ) {
				throw new ToolError( sprintf( 'WordPress could not update post %d.', (int) $post_id ) );
			}
		}
		if ( isset( $changes['featured_media'] ) ) {
			Posts::set_featured( $post_id, (int) $changes['featured_media'] );
		}

		$after = get_post( $post_id );
		if ( $after ) {
			foreach ( array( 'post_status', 'post_name', 'post_date' ) as $field ) {
				$changed = (string) $after->$field !== $prior[ $field ] || ( 'post_date' === $field && (string) $after->post_date_gmt !== $prior['post_date_gmt'] );
				if ( $changed && ! in_array( $field, $fields, true ) ) {
					$fields[]           = $field;
					$snapshot[ $field ] = $prior[ $field ];
					if ( 'post_date' === $field ) {
						$snapshot['post_date_gmt'] = $prior['post_date_gmt'];
					}
				}
			}
		}

		$result = array( 'post_id' => $post_id );
		if ( array() !== $text ) {
			$result['revision_id']        = $after ? self::latest_revision_matching( $after ) : 0;
			$result['before_revision_id'] = $before_revision;
		}
		return new Outcome(
			$result,
			Rollback::make(
				array() === $text ? Rollback::SNAPSHOT : Rollback::REVISION,
				'post',
				$post_id,
				array(
					'revision_id' => $before_revision,
					'fields'      => $text,
					'attributes'  => $fields,
				),
				$snapshot
			),
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
		$post = get_post( (int) $args['post_id'] );
		if ( ! $post ) {
			return $outcome->with_verification_failure( 'The post could not be read back after the update.' );
		}

		$changes = self::changes( $args );
		$differs = array();
		foreach ( $changes as $field => $value ) {
			if ( self::current( $post, $field ) !== $value ) {
				$differs[] = self::arg_name( $field );
			}
		}
		// A status WordPress changed without being asked (e.g. a future date scheduled a published post).
		$rollback = $outcome->rollback();
		if ( ! isset( $changes['post_status'] ) && isset( $rollback['before']['post_status'] ) && (string) $rollback['before']['post_status'] !== (string) $post->post_status ) {
			$differs[] = 'status';
		}
		if ( array() === $differs ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result(
			array(
				'stored_verbatim' => false,
				'differs'         => $differs,
				'status'          => (string) $post->post_status,
			)
		)->with_warning( 'stored_differently', sprintf( 'WordPress stored the %s differently from the request (for example a content filter, a unique slug, or a future date that scheduled the post). Read the post to see what was saved.', implode( ', ', $differs ) ) );
	}

	/**
	 * Assesses restoring the changed fields: text from the revision saved before the write, the rest from the snapshot.
	 * Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$post_id = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$post    = Posts::find_untrashed( $post_id, 'edit_post' );
		if ( $post instanceof Assessment ) {
			return $post;
		}

		$current  = array();
		$restored = array();
		$text     = self::rollback_fields( $rollback );
		$revision = null;
		if ( array() !== $text ) {
			$revision = $this->revision( $rollback, $post_id );
			if ( null === $revision ) {
				return Assessment::prohibited( 'revision_missing', sprintf( 'The revision of post %d that this rollback would restore no longer exists (revisions may have been limited or deleted).', $post_id ) );
			}
			foreach ( $text as $field ) {
				$current[ $field ]  = (string) $post->$field;
				$restored[ $field ] = (string) $revision->$field;
			}
		}
		foreach ( self::rollback_attributes( $rollback ) as $field => $value ) {
			$current[ $field ]  = self::current( $post, $field );
			$restored[ $field ] = $value;
		}

		$refusal = $this->check_restore( $post, $restored );
		if ( null !== $refusal ) {
			return $refusal;
		}

		$status = isset( $restored['post_status'] ) ? $restored['post_status'] : (string) $post->post_status;
		return Assessment::reversible(
			sprintf( 'Restore the %s of %s%s.', self::names( $current ), Posts::name( $post ), null === $revision ? '' : sprintf( ' from revision %d', $revision->ID ) ) . ( $status === $post->post_status ? Posts::live_note( $post ) : self::status_note( (string) $post->post_status, (string) $status ) ),
			array(
				'post_id'  => $post_id,
				'status'   => $post->post_status,
				'current'  => $current,
				'restored' => $restored,
			),
			Posts::state( $post ),
			Posts::is_private( (string) $post->post_status ) && Posts::is_private( $status ) && ! isset( $restored['post_author'] ) && Markup::is_safe_post( $restored )
		);
	}

	/**
	 * Restores the changed fields, if the post is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the post changed or WordPress could not restore.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$post_id  = (int) $rollback['target']['id'];
		$post     = get_post( $post_id );
		$text     = self::rollback_fields( $rollback );
		$revision = array() === $text ? null : $this->revision( $rollback, $post_id );
		if ( ! $post || ( array() !== $text && null === $revision ) || Posts::state( $post ) !== $expected_state ) {
			throw new ToolError( sprintf( 'Post %d changed after the rollback was assessed; nothing was restored.', (int) $post_id ) );
		}

		$result = array( 'post_id' => $post_id );
		if ( null !== $revision ) {
			if ( ! wp_restore_post_revision( $revision->ID, $text ) ) {
				throw new ToolError( sprintf( 'WordPress could not restore revision %d of post %d.', (int) $revision->ID, (int) $post_id ) );
			}
			$result['restored_revision_id'] = (int) $revision->ID;
		}

		$attributes = self::rollback_attributes( $rollback );
		$postarr    = self::postarr( $attributes );
		if ( array() !== $postarr ) {
			if ( isset( $postarr['post_date'] ) && isset( $rollback['before']['post_date_gmt'] ) ) {
				$postarr['post_date_gmt'] = (string) $rollback['before']['post_date_gmt'];
			}
			$updated = wp_update_post( wp_slash( array( 'ID' => $post_id ) + $postarr ), true );
			if ( is_wp_error( $updated ) || ! $updated ) {
				throw new ToolError( sprintf( 'WordPress could not restore the fields of post %d.', (int) $post_id ) );
			}
		}
		if ( isset( $attributes['featured_media'] ) ) {
			Posts::set_featured( $post_id, (int) $attributes['featured_media'] );
		}

		$outcome = new Outcome( $result, Rollback::none() );
		$after   = get_post( $post_id );
		if ( ! $after ) {
			return $outcome->with_verification_failure( 'The post could not be read back after the rollback.' );
		}
		foreach ( $text as $field ) {
			if ( (string) $after->$field !== (string) $revision->$field ) {
				return $outcome->with_warning( 'restored_differently', 'WordPress stored the restored fields differently from the revision.' );
			}
		}
		foreach ( $attributes as $field => $value ) {
			if ( self::current( $after, $field ) !== $value ) {
				return $outcome->with_warning( 'restored_differently', sprintf( 'WordPress stored the restored %s differently from the snapshot.', self::arg_name( $field ) ) );
			}
		}
		return $outcome;
	}

	/**
	 * Refusal when the post's type cannot keep the revision a text change rolls back to.
	 *
	 * @param object $post Post.
	 * @return Assessment|null
	 */
	private static function revisions_refusal( $post ): ?Assessment {
		if ( ! post_type_supports( $post->post_type, 'revisions' ) ) {
			return Assessment::prohibited( 'no_revisions', sprintf( 'Post type %s does not support revisions, so this change could not be rolled back.', $post->post_type ) );
		}
		if ( ! wp_revisions_enabled( $post ) ) {
			return Assessment::prohibited( 'no_revisions', sprintf( 'Revisions are turned off for post %d, so this change could not be rolled back.', $post->ID ) );
		}
		return null;
	}

	/**
	 * Checks status, date, parent, template, author and featured image changes. Read-only.
	 *
	 * @param object                $post    Post.
	 * @param array<string, string> $changes Requested fields.
	 * @return Assessment|null A refusal, or null.
	 * @throws ToolError When a value is invalid.
	 */
	private function check_attributes( $post, array $changes ): ?Assessment {
		$type = (string) $post->post_type;
		if ( isset( $changes['post_date'] ) ) {
			Posts::check_date( $changes['post_date'] );
		}
		if ( isset( $changes['post_status'] ) && $changes['post_status'] !== $post->post_status ) {
			$refusal = Posts::publish_refusal( $type, $changes['post_status'] );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		$status     = isset( $changes['post_status'] ) ? $changes['post_status'] : (string) $post->post_status;
		$date       = isset( $changes['post_date'] ) ? $changes['post_date'] : (string) $post->post_date;
		$scheduling = isset( $changes['post_status'] ) || isset( $changes['post_date'] );
		if ( $scheduling && 'future' === $status && ( ( ! isset( $changes['post_date'] ) && 'future' !== $post->post_status ) || $date <= Posts::now() ) ) {
			throw new ToolError( 'Status future needs a date in the future (give date).' );
		}
		if ( isset( $changes['post_parent'] ) ) {
			Posts::check_parent( $type, (int) $changes['post_parent'], (int) $post->ID );
		}
		if ( isset( $changes['page_template'] ) && '' !== $changes['page_template'] ) {
			$templates = (array) wp_get_theme()->get_page_templates( $post, $type );
			if ( ! isset( $templates[ $changes['page_template'] ] ) ) {
				throw new ToolError( sprintf( 'Template %s is not offered for %s items. Available: %s (or an empty string for the default).', esc_textarea( $changes['page_template'] ), esc_textarea( $type ), array() === $templates ? 'none' : esc_textarea( implode( ', ', array_keys( $templates ) ) ) ) );
			}
		}
		if ( isset( $changes['featured_media'] ) ) {
			$refusal = Posts::check_featured( $type, (int) $changes['featured_media'] );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		if ( isset( $changes['post_author'] ) ) {
			return $this->check_author( $type, (int) $changes['post_author'] );
		}
		return null;
	}

	/**
	 * Checks an author change: the caller needs `edit_others_posts`, the user must exist and be able to edit the type.
	 *
	 * @param string $type   Post type.
	 * @param int    $author User ID.
	 * @return Assessment|null
	 * @throws ToolError When the user cannot be the author.
	 */
	private function check_author( string $type, int $author ): ?Assessment {
		$object = get_post_type_object( $type );
		if ( ! $object || ! current_user_can( $object->cap->edit_others_posts ) ) {
			return Assessment::prohibited( 'forbidden', sprintf( 'You cannot change the author of %s items.', $type ) );
		}
		$user = get_userdata( $author );
		if ( ! $user || ! user_can( $user, $object->cap->edit_posts ) ) {
			throw new ToolError( sprintf( 'User %d does not exist or cannot edit %s items.', (int) $author, esc_textarea( $type ) ) );
		}
		return null;
	}

	/**
	 * Checks a rollback may set the restored status and author.
	 *
	 * @param object                $post     Post.
	 * @param array<string, string> $restored Restored fields.
	 * @return Assessment|null
	 */
	private function check_restore( $post, array $restored ): ?Assessment {
		if ( isset( $restored['post_status'] ) && $restored['post_status'] !== $post->post_status ) {
			$refusal = Posts::publish_refusal( (string) $post->post_type, $restored['post_status'] );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		if ( isset( $restored['post_author'] ) ) {
			$object = get_post_type_object( $post->post_type );
			if ( ! $object || ! current_user_can( $object->cap->edit_others_posts ) ) {
				return Assessment::prohibited( 'forbidden', sprintf( 'You cannot change the author of %s items.', $post->post_type ) );
			}
		}
		return null;
	}

	/**
	 * The revision a rollback reference points to, if it still belongs to the post.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @param int                  $post_id  Post ID.
	 * @return object|null
	 */
	private function revision( array $rollback, int $post_id ) {
		$revision_id = isset( $rollback['refs']['revision_id'] ) ? (int) $rollback['refs']['revision_id'] : 0;
		$revision    = $revision_id > 0 ? wp_get_post_revision( $revision_id ) : null;
		return $revision && (int) $revision->post_parent === $post_id ? $revision : null;
	}

	/**
	 * Requested post fields (post_title => value, ...) as strings, in FIELDS then ATTRIBUTES order.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, string>
	 */
	private static function changes( array $args ): array {
		$changes = array();
		foreach ( self::FIELDS + self::ATTRIBUTES as $arg => $field ) {
			if ( array_key_exists( $arg, $args ) ) {
				$changes[ $field ] = (string) $args[ $arg ];
			}
		}
		return $changes;
	}

	/**
	 * The post array for wp_update_post() from post fields: the date also sets its GMT value (and `edit_date`), the
	 * default template is `default`, ids are integers; the featured image is left out (set separately).
	 *
	 * @param array<string, string> $fields Post field => value.
	 * @return array<string, mixed>
	 */
	private static function postarr( array $fields ): array {
		$postarr = array();
		foreach ( $fields as $field => $value ) {
			switch ( $field ) {
				case 'post_parent':
				case 'post_author':
					$postarr[ $field ] = (int) $value;
					break;
				case 'page_template':
					$postarr[ $field ] = '' === $value ? 'default' : $value;
					break;
				case 'featured_media':
					// Set with set_post_thumbnail() / delete_post_thumbnail(), like the REST API.
					break;
				case 'post_date':
					$postarr['post_date']     = $value;
					$postarr['post_date_gmt'] = (string) get_gmt_from_date( $value );
					$postarr['edit_date']     = true;
					break;
				default:
					$postarr[ $field ] = $value;
			}
		}//end foreach
		return $postarr;
	}

	/**
	 * A post field's current value as a string.
	 *
	 * @param object $post  Post.
	 * @param string $field Post field.
	 * @return string
	 */
	private static function current( $post, string $field ): string {
		if ( 'page_template' === $field ) {
			return (string) get_page_template_slug( $post );
		}
		if ( 'featured_media' === $field ) {
			return (string) Posts::featured_id( $post );
		}
		if ( 'post_parent' === $field || 'post_author' === $field ) {
			return (string) (int) $post->$field;
		}
		return (string) $post->$field;
	}

	/**
	 * Text fields a rollback restores (only title, content and excerpt are ever accepted).
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return string[]
	 */
	private static function rollback_fields( array $rollback ): array {
		$fields = isset( $rollback['refs']['fields'] ) && is_array( $rollback['refs']['fields'] ) ? $rollback['refs']['fields'] : array();
		return array_values( array_intersect( self::FIELDS, $fields ) );
	}

	/**
	 * Snapshotted fields a rollback restores, field => value (only ATTRIBUTES are ever accepted).
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return array<string, string>
	 */
	private static function rollback_attributes( array $rollback ): array {
		$fields   = isset( $rollback['refs']['attributes'] ) && is_array( $rollback['refs']['attributes'] ) ? $rollback['refs']['attributes'] : array();
		$restored = array();
		foreach ( array_intersect( self::ATTRIBUTES, $fields ) as $field ) {
			if ( isset( $rollback['before'][ $field ] ) && is_scalar( $rollback['before'][ $field ] ) ) {
				$restored[ $field ] = (string) $rollback['before'][ $field ];
			}
		}
		return $restored;
	}

	/**
	 * The latest revision whose title, content and excerpt equal the post's, or 0.
	 *
	 * @param object $post Post.
	 * @return int
	 */
	private static function latest_revision_matching( $post ): int {
		foreach ( wp_get_post_revisions( $post->ID ) as $revision ) {
			if ( false === strpos( (string) $revision->post_name, 'autosave' ) ) {
				return Posts::text_fingerprint( $revision ) === Posts::text_fingerprint( $post ) ? (int) $revision->ID : 0;
			}
		}
		return 0;
	}

	/**
	 * Argument name of a post field.
	 *
	 * @param string $field Post field.
	 * @return string
	 */
	private static function arg_name( string $field ): string {
		return (string) array_search( $field, self::FIELDS + self::ATTRIBUTES, true );
	}

	/**
	 * "title and status" from post fields (Summary::fields()).
	 *
	 * @param array<string, mixed> $fields Post field => value.
	 * @return string
	 */
	private static function names( array $fields ): string {
		return Summary::fields( array_map( array( self::class, 'arg_name' ), array_keys( $fields ) ) );
	}

	/**
	 * What a summary says about the post's status: nothing while it stays the same; otherwise the old and the new
	 * status and what that does to who can see the post.
	 *
	 * @param string $from The post's status now.
	 * @param string $to   Its status after the change.
	 * @return string '' or a sentence, with a leading space.
	 */
	private static function status_note( string $from, string $to ): string {
		if ( $from === $to ) {
			return '';
		}
		$note = sprintf( ' The status changes from %s to %s', $from, $to );
		if ( 'private' === $to ) {
			return $note . ( Posts::is_private( $from )
				? ', which is not public: only logged-in users who can read private posts will see it.'
				: ', which takes it off the public site: only logged-in users who can read private posts will see it.' );
		}
		if ( ! Posts::is_private( $to ) ) {
			return $note . ', which makes it public or scheduled.';
		}
		if ( ! Posts::is_private( $from ) ) {
			return $note . ', which takes it off the live site.';
		}
		return $note . '.';
	}
}
