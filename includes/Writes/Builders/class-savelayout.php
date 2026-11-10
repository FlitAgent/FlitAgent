<?php
/**
 * Base for the page-builder layout saves.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Buffers;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Canonical;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Ops\ErrorText;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Rollback;
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\WriteTool;
use FlitAgent\Tools\Builders\Output;

defined( 'ABSPATH' ) || exit;

/**
 * Saves one existing post's layout through a builder's own pipeline (an Engine), with an exact snapshot of the layout
 * meta and post_content for rollback.
 *
 * Tiers: reversible for drafts and pending posts; destructive for any other status, for layouts with elements that execute code (which also need `unfiltered_html`) and
 * for tools that are always destructive.
 */
abstract class SaveLayout implements WriteTool, Reversible {

	/**
	 * Statuses that are not publicly visible, so a save can be rolled back without taking anything live.
	 */
	const NOT_PUBLIC = array( 'draft', 'pending' );

	/**
	 * The builder's pipeline.
	 *
	 * @var Engine
	 */
	protected $engine;

	/**
	 * Constructor.
	 *
	 * @param Engine $engine The builder's pipeline.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Builder name for messages, e.g. "Elementor".
	 */
	abstract protected function builder(): string;

	/**
	 * Tool title, naming FlitAgent.
	 */
	abstract protected function title(): string;

	/**
	 * Tool-specific description.
	 */
	abstract protected function describe(): string;

	/**
	 * Name of the layout input property.
	 */
	abstract protected function property(): string;

	/**
	 * JSON schema of the layout input property.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function property_schema(): array;

	/**
	 * Meta keys that hold the layout (snapshotted and restored).
	 *
	 * @return string[]
	 */
	abstract protected function meta_keys(): array;

	/**
	 * Validates the layout from the caller and returns it as the builder's pipeline takes it. Only looks at the value.
	 *
	 * @param mixed $raw Layout argument.
	 * @return mixed
	 * @throws ToolError When the layout is malformed.
	 */
	abstract protected function normalize( $raw );

	/**
	 * The layout in the stored meta, comparable with normalize()'s result, or null when there is none.
	 *
	 * @param array<string, mixed> $values Meta key => value as get_post_meta() returns it (null when missing).
	 * @return mixed
	 */
	abstract protected function layout_from( array $values );

	/**
	 * Elements that execute code, as short labels.
	 *
	 * @param mixed $layout Layout as plain arrays (from normalize() or layout_from()).
	 * @return string[]
	 */
	abstract protected function code_elements( $layout ): array;

	/**
	 * Why a save that the builder accepted must be undone because it lost the whole layout, or null. For builders that
	 * silently drop elements they do not accept.
	 *
	 * @param mixed $sent    Normalized layout.
	 * @param mixed $before  Layout before the save (null for none).
	 * @param mixed $stored  Layout read back after the save (null for none).
	 * @param int   $post_id Post ID.
	 * @return string|null
	 */
	protected function emptied( $sent, $before, $stored, int $post_id ): ?string {
		return null;
	}

	/**
	 * A refusal when the post is the wrong kind for this tool.
	 *
	 * @param object $post Post.
	 * @return Assessment|null
	 */
	protected function check_type( $post ): ?Assessment {
		return null;
	}

	/**
	 * Whether every save is destructive (site-wide targets).
	 */
	protected function always_destructive(): bool {
		return false;
	}

	/**
	 * A layout as compared after the save: the builder's own harmless rewrites (defaults it fills in) undone, so they
	 * are not reported as stored differently. As it is by default.
	 *
	 * @param mixed $layout Layout.
	 * @return mixed
	 */
	protected function comparable( $layout ) {
		return $layout;
	}

	/**
	 * The refusal when the builder does not let the current user edit the post's layout.
	 *
	 * @param object $post Post.
	 * @return Assessment
	 */
	protected function access_refusal( $post ): Assessment {
		return Assessment::prohibited( 'builder_access', sprintf( '%s\'s own access settings do not let you edit the layout of post %d.', $this->builder(), (int) $post->ID ) );
	}

	/**
	 * The post's kind in words: its post type with spaces (page, product).
	 *
	 * @param object $post Post.
	 * @return string
	 */
	protected function kind_of( $post ): string {
		return str_replace( '_', ' ', (string) $post->post_type );
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			$this->title(),
			$this->describe() . ' Saves to an existing post only and never changes its status, title or template. The previous layout is snapshotted so rollback_change restores it exactly and regenerates the CSS. Elements that execute code need the unfiltered_html capability and always need approval. Every save needs an administrator\'s approval (returns approval_required).',
			array(
				'post_id'         => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the post whose layout to replace.',
				),
				$this->property() => $this->property_schema(),
			),
			array( 'post_id', $this->property() ),
			$this->tiers(),
			false
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function tiers(): array {
		return $this->always_destructive() ? array( Risk::DESTRUCTIVE ) : array( Risk::REVERSIBLE, Risk::DESTRUCTIVE );
	}

	/**
	 * Never: a builder prints its settings in links, attributes and stylesheets or resolves them at render, so nothing here
	 * can tell that a layout is safe to save without a person.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the save. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the layout is malformed or already stored.
	 */
	public function assess( array $args ): Assessment {
		$layout  = $this->normalize( $args[ $this->property() ] ?? null );
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$post    = $this->editable_post( $post_id );
		if ( $post instanceof Assessment ) {
			return $post;
		}

		$state       = Snapshot::read( $post, $this->meta_keys() );
		$fingerprint = self::fingerprint( $state );
		if ( '' === $fingerprint ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'The stored %s layout of post %d holds values FlitAgent cannot snapshot exactly, so this save could not be rolled back.', $this->builder(), $post_id ) );
		}

		$current = $this->layout_from( Snapshot::values( $state ) );
		if ( null !== $current && Canonical::hash( $current ) === Canonical::hash( $layout ) ) {
			throw new ToolError( sprintf( 'Post %d already has that %s layout; nothing to change.', (int) $post_id, esc_textarea( $this->builder() ) ) );
		}

		$code = $this->gate( $post_id, $layout );
		if ( $code instanceof Assessment ) {
			return $code;
		}

		$clears  = array() === $layout && is_array( $current ) && array() !== $current;
		$summary = $clears
			? sprintf( 'Clear the %s layout of %s, removing every element.', $this->builder(), $this->summary_name( $post, $code ) )
			: sprintf( 'Save the %s layout of %s%s.', $this->builder(), $this->summary_name( $post, $code ), self::code_note( $code ) );
		return $this->tiered(
			$summary . $this->post_live_note( $post ),
			array(
				'post_id'       => $post_id,
				'post_type'     => (string) $post->post_type,
				'status'        => (string) $post->post_status,
				'code_elements' => $code,
				'before'        => array( 'layout' => self::pretty( $current ) ),
				'after'         => array( 'layout' => self::pretty( $layout ) ),
			),
			array(
				'fingerprint' => $fingerprint,
				'status'      => (string) $post->post_status,
			),
			$post,
			$code
		);
	}

	/**
	 * Saves through the builder's pipeline. On any failure the snapshot is written back.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the post changed or the builder failed.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$post_id = (int) $args['post_id'];
		$post    = get_post( $post_id );
		$state   = $post ? Snapshot::read( $post, $this->meta_keys() ) : null;
		if ( null === $state || self::state( $state, $post ) !== $assessment->preconditions() ) {
			throw new ToolError( sprintf( 'Post %d changed while the save was being applied; nothing was changed.', (int) $post_id ) );
		}

		$layout = $this->normalize( $args[ $this->property() ] );
		try {
			$warnings = self::quietly(
				function () use ( $post_id, $layout ) {
					return $this->engine->save( $post_id, $layout );
				}
			);
		} catch ( \Throwable $e ) {
			$this->restore_after_failure( $post_id, $state );
			if ( $e instanceof ToolError ) {
				throw new ToolError( sprintf( '%s %s could not save the layout of post %d; the previous layout was restored.', esc_textarea( ToolError::plain( $e ) ), esc_textarea( $this->builder() ), (int) $post_id ) );
			}
			throw new ToolError( sprintf( '%s could not save the layout of post %d (%s); the previous layout was restored.', esc_textarea( $this->builder() ), (int) $post_id, esc_textarea( rtrim( ErrorText::of( $e ), '.' ) ) ) );
		}

		$after   = get_post( $post_id );
		$emptied = $this->emptied( $layout, $this->layout_from( Snapshot::values( $state ) ), $after ? $this->layout_from( Snapshot::values( Snapshot::read( $after, $this->meta_keys() ) ) ) : null, $post_id );
		if ( null !== $emptied ) {
			$this->restore_after_failure( $post_id, $state );
			throw new ToolError( esc_textarea( $emptied ) );
		}
		$outcome = new Outcome(
			array(
				'post_id' => $post_id,
				'status'  => $after ? (string) $after->post_status : '',
			),
			Rollback::make( Rollback::SNAPSHOT, 'post', $post_id, array( 'tool' => $this->name() ), array( 'state' => Snapshot::encode( $state ) ) ),
			array( 'fingerprint' => $after ? self::fingerprint( Snapshot::read( $after, $this->meta_keys() ) ) : '' )
		);
		foreach ( $warnings as $code => $message ) {
			$outcome = $outcome->with_warning( (string) $code, (string) $message );
		}
		return $outcome;
	}

	/**
	 * Reads the stored layout back and compares it with the layout sent.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$post = get_post( (int) $args['post_id'] );
		if ( ! $post ) {
			return $outcome->with_verification_failure( 'The post could not be read back after the save.' );
		}

		$stored = $this->layout_from( Snapshot::values( Snapshot::read( $post, $this->meta_keys() ) ) );
		if ( null !== $stored && Canonical::hash( $this->comparable( $stored ) ) === Canonical::hash( $this->comparable( $this->normalize( $args[ $this->property() ] ) ) ) ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result( array( 'stored_verbatim' => false ) )->with_warning( 'stored_differently', sprintf( '%s stored the layout differently from the request (it may add defaults or filter settings). Read the page layout to see what was saved.', $this->builder() ) );
	}

	/**
	 * Assesses restoring the snapshot. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$post_id = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$post    = $this->editable_post( $post_id );
		if ( $post instanceof Assessment ) {
			return $post;
		}

		$before = $this->snapshot_of( $rollback );
		if ( null === $before ) {
			return Assessment::prohibited( 'no_rollback', sprintf( 'The layout snapshot of post %d in this receipt is missing or damaged, so it cannot be restored.', $post_id ) );
		}

		$state    = Snapshot::read( $post, $this->meta_keys() );
		$restored = $this->layout_from( Snapshot::values( $before ) );
		$code     = $this->gate( $post_id, $restored );
		if ( $code instanceof Assessment ) {
			return $code;
		}

		return $this->tiered(
			sprintf( 'Restore the previous %s layout of %s%s.', $this->builder(), $this->summary_name( $post, $code ), self::code_note( $code ) ) . $this->post_live_note( $post ),
			array(
				'post_id'       => $post_id,
				'status'        => (string) $post->post_status,
				'code_elements' => $code,
				'current'       => array( 'layout' => self::pretty( $this->layout_from( Snapshot::values( $state ) ) ) ),
				'restored'      => array( 'layout' => self::pretty( $restored ) ),
			),
			self::state( $state, $post ),
			$post,
			$code
		);
	}

	/**
	 * Writes the snapshot back byte for byte, then regenerates the builder's CSS.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the post changed after the assessment or the snapshot is unusable.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$post_id = isset( $rollback['target']['id'] ) ? (int) $rollback['target']['id'] : 0;
		$post    = get_post( $post_id );
		$before  = $this->snapshot_of( $rollback );
		if ( ! $post || null === $before || self::state( Snapshot::read( $post, $this->meta_keys() ), $post ) !== $expected_state ) {
			throw new ToolError( sprintf( 'Post %d changed after the rollback was assessed; nothing was restored.', (int) $post_id ) );
		}

		self::quietly(
			static function () use ( $post_id, $before, $post ) {
				Snapshot::restore( $post_id, $before, (string) $post->post_content );
			}
		);

		$warnings = array();
		try {
			$this->refresh_quietly( $post_id );
		} catch ( \Throwable $e ) {
			$warnings['css_regen_failed'] = sprintf( 'The layout was restored, but %s could not regenerate its CSS; open and save the page in the builder.', $this->builder() );
		}

		$after    = get_post( $post_id );
		$verbatim = $after && self::fingerprint( Snapshot::read( $after, $this->meta_keys() ) ) === self::fingerprint( $before );
		if ( ! $verbatim ) {
			$warnings['restored_differently'] = 'The restored layout reads back differently from the snapshot (a builder hook may have changed it).';
		}

		$outcome = new Outcome(
			array(
				'post_id'           => $post_id,
				'restored_verbatim' => $verbatim,
			),
			Rollback::none()
		);
		foreach ( $warnings as $code => $message ) {
			$outcome = $outcome->with_warning( $code, $message );
		}
		return $outcome;
	}

	/**
	 * The post, or a refusal: missing or not editable (same message), trashed or auto-draft, the wrong kind, or the
	 * builder's own access rule.
	 *
	 * @param int $post_id Post ID.
	 * @return object|Assessment
	 */
	private function editable_post( int $post_id ) {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post || 'revision' === $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return Assessment::prohibited( 'not_found', sprintf( 'Post %d was not found or you cannot edit it.', $post_id ) );
		}
		if ( 'trash' === $post->post_status || 'auto-draft' === $post->post_status ) {
			return Assessment::prohibited( 'not_supported', sprintf( 'Post %d is in the trash or is an auto-draft; restore or save it first.', $post_id ) );
		}
		$wrong = $this->check_type( $post );
		if ( null !== $wrong ) {
			return $wrong;
		}
		if ( ! $this->engine->can_edit( $post_id ) ) {
			return $this->access_refusal( $post );
		}
		return $post;
	}

	/**
	 * Code gating: the code elements of a layout, or a refusal (code without unfiltered_html, or the builder's own HTML
	 * filter rejecting the layout for a user without unfiltered_html).
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $layout  Layout, or null for none.
	 * @return string[]|Assessment
	 */
	private function gate( int $post_id, $layout ) {
		if ( null === $layout ) {
			return array();
		}
		$code       = $this->code_elements( Output::plain( $layout ) );
		$unfiltered = (bool) current_user_can( 'unfiltered_html' );
		if ( array() !== $code && ! $unfiltered ) {
			return Assessment::prohibited( 'code_not_allowed', sprintf( 'The layout has elements that execute code (%s); only users with the unfiltered_html capability may save them.', implode( ', ', $code ) ) );
		}
		if ( ! $unfiltered ) {
			$reason = $this->engine->rejects( $post_id, $layout );
			if ( null !== $reason ) {
				return Assessment::prohibited( 'unsafe_html', $reason );
			}
		}
		return $code;
	}

	/**
	 * Reversible for a draft or pending post without code elements, destructive otherwise. Never auto-approvable.
	 *
	 * @param string               $summary       Summary.
	 * @param array<string, mixed> $preview       Preview.
	 * @param array<string, mixed> $preconditions Preconditions.
	 * @param object               $post          Post.
	 * @param string[]             $code          Code elements.
	 * @return Assessment
	 */
	private function tiered( string $summary, array $preview, array $preconditions, $post, array $code ): Assessment {
		if ( $this->destructive_save( $post, $code ) ) {
			return Assessment::destructive( $summary, $preview, $preconditions );
		}
		return Assessment::reversible( $summary, $preview, $preconditions, false );
	}

	/**
	 * Whether saving this layout is destructive: always for some tools, with code elements, or on a post that is not a
	 * draft or pending.
	 *
	 * @param object   $post Post.
	 * @param string[] $code Code elements.
	 * @return bool
	 */
	private function destructive_save( $post, array $code ): bool {
		return $this->always_destructive() || array() !== $code || ! in_array( $post->post_status, self::NOT_PUBLIC, true );
	}

	/**
	 * The post as the summary names it: its quoted title (or kind and ID), and with its kind and ID when the save is
	 * destructive, so what cannot be undone is named unambiguously.
	 *
	 * @param object   $post Post.
	 * @param string[] $code Code elements.
	 * @return string
	 */
	private function summary_name( $post, array $code ): string {
		$kind = $this->kind_of( $post );
		return $this->destructive_save( $post, $code ) ? Summary::named_with_id( $kind, (string) $post->post_title, (int) $post->ID ) : Summary::named( (string) $post->post_title, sprintf( '%s %d', $kind, (int) $post->ID ) );
	}

	/**
	 * " This changes the live page." for a published post.
	 *
	 * @param object $post Post.
	 * @return string
	 */
	private function post_live_note( $post ): string {
		return Summary::live_note( (string) $post->post_status, $this->kind_of( $post ) );
	}

	/**
	 * Writes the snapshot back after a failed save, best effort.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $state   State before the save.
	 */
	private function restore_after_failure( int $post_id, array $state ): void {
		try {
			$now = get_post( $post_id );
			self::quietly(
				static function () use ( $post_id, $state, $now ) {
					Snapshot::restore( $post_id, $state, $now ? (string) $now->post_content : '' );
				}
			);
			$this->refresh_quietly( $post_id );
		} catch ( \Throwable $e ) {
			unset( $e );
			// The save already failed; its error is what the caller gets.
		}
	}

	/**
	 * Regenerates the builder's CSS and caches, discarding anything it prints.
	 *
	 * @param int $post_id Post ID.
	 */
	private function refresh_quietly( int $post_id ): void {
		self::quietly(
			function () use ( $post_id ) {
				$this->engine->refresh( $post_id );
			}
		);
	}

	/**
	 * Runs a builder call and discards anything it prints, including buffers it opened and left open (Elementor renders
	 * widgets inside its own buffer and can throw before closing it), so nothing reaches the MCP response.
	 *
	 * @param callable $call Builder call.
	 * @return mixed What the call returns.
	 */
	private static function quietly( callable $call ) {
		$level = ob_get_level();
		ob_start();
		try {
			return $call();
		} finally {
			Buffers::discard( $level );
		}
	}

	/**
	 * The snapshot of a rollback reference, if it is intact and covers exactly this tool's meta keys.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return array<string, mixed>|null
	 */
	private function snapshot_of( array $rollback ): ?array {
		try {
			$state = Snapshot::decode( $rollback['before']['state'] ?? null );
		} catch ( \UnexpectedValueException $e ) {
			return null;
		}
		return array_keys( $state['meta'] ) === $this->meta_keys() ? $state : null;
	}

	/**
	 * Preconditions of a state: fingerprint and status.
	 *
	 * @param array<string, mixed> $state State.
	 * @param object               $post  Post.
	 * @return array<string, string>
	 */
	private static function state( array $state, $post ): array {
		return array(
			'fingerprint' => self::fingerprint( $state ),
			'status'      => (string) $post->post_status,
		);
	}

	/**
	 * Fingerprint, or '' when the state cannot be encoded.
	 *
	 * @param array<string, mixed> $state State.
	 * @return string
	 */
	private static function fingerprint( array $state ): string {
		try {
			return Snapshot::fingerprint( $state );
		} catch ( \UnexpectedValueException $e ) {
			return '';
		}
	}

	/**
	 * A layout as indented JSON for the approval diff, '' for none.
	 *
	 * @param mixed $layout Layout.
	 * @return string
	 */
	private static function pretty( $layout ): string {
		return null === $layout ? '' : (string) wp_json_encode( $layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Summary suffix naming code elements.
	 *
	 * @param string[] $code Code elements.
	 * @return string
	 */
	private static function code_note( array $code ): string {
		return array() === $code ? '' : ', with elements that execute code: ' . implode( ', ', $code );
	}
}
