<?php
/**
 * Write tool: replace_in_post.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Content;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;
use FlitAgent\Writes\Reversible;
use FlitAgent\Writes\Risk;
use FlitAgent\Writes\Summary;
use FlitAgent\Writes\WriteTool;

defined( 'ABSPATH' ) || exit;

/**
 * `replace_in_post`: replaces exact text in the title, content or excerpt of one post, once (the text must occur exactly
 * once) or everywhere with `replace_all`. In content, edits that remove blocks or break block markup are refused. The
 * write itself is an update_post of that field: same refusals, tier, auto-approval, revision and rollback.
 */
final class ReplaceInPost implements WriteTool, Reversible {

	/**
	 * Argument `field` => post field.
	 */
	const FIELDS = array(
		'title'   => 'post_title',
		'content' => 'post_content',
		'excerpt' => 'post_excerpt',
	);

	/**
	 * Candidate excerpts shown for no or several matches.
	 */
	const CANDIDATES = 5;

	/**
	 * The update that performs the write.
	 *
	 * @var UpdatePost
	 */
	private $update;

	/**
	 * The update_post arguments of the last apply(), for verify().
	 *
	 * @var array<string, mixed>
	 */
	private $applied = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->update = new UpdatePost();
	}

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'replace_in_post';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: replace text in a post',
			'Replaces exact, case-sensitive text in the title, content or excerpt of one post. The text must occur exactly once unless replace_all is true; otherwise the error lists the candidates. In block content, edits that remove blocks or break block markup are refused. Saves a revision first; roll it back with rollback_change. Same approval rules as update_post.',
			array(
				'post_id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the post.',
				),
				'field'       => array(
					'type'        => 'string',
					'enum'        => array_keys( self::FIELDS ),
					'description' => 'Field to edit.',
				),
				'old'         => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 100000,
					'description' => 'Exact text to find (raw content, including block comments and HTML).',
				),
				'new'         => array(
					'type'        => 'string',
					'maxLength'   => 100000,
					'description' => 'Replacement text.',
				),
				'replace_all' => array(
					'type'        => 'boolean',
					'description' => 'true replaces every occurrence. Default false (exactly one must exist).',
				),
			),
			array( 'post_id', 'field', 'old', 'new' ),
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
		return $this->update->tiers();
	}

	/**
	 * May auto-approve (as update_post).
	 */
	public function may_auto_approve(): bool {
		return $this->update->may_auto_approve();
	}

	/**
	 * Assesses the replacement. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the text does not match exactly once (or at all with replace_all).
	 */
	public function assess( array $args ): Assessment {
		$planned = $this->plan( $args );
		if ( $planned instanceof Assessment ) {
			return $planned;
		}
		list( $post, $update, $count ) = $planned;

		$inner = $this->update->assess( $update );
		if ( $inner->is_refused() ) {
			return $inner;
		}
		$preview                 = $inner->preview();
		$preview['replacements'] = $count;
		return Assessment::reversible(
			sprintf( 'Replace %s of "%s" in the %s of %s.', Summary::count( $count, 'occurrence', 'occurrences' ), Posts::short( (string) $args['old'] ), $args['field'], Posts::name( $post ) ) . Posts::live_note( $post ),
			$preview,
			$inner->preconditions(),
			$inner->auto_approvable()
		);
	}

	/**
	 * Writes the replaced field through update_post.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the post no longer matches.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$planned = $this->plan( $args );
		if ( $planned instanceof Assessment ) {
			throw new ToolError( sprintf( 'Post %d changed while the replacement was being applied; nothing was changed.', (int) $args['post_id'] ) );
		}
		$this->applied = $planned[1];
		return $this->update->apply( $this->applied, $assessment )->with_result( array( 'replacements' => $planned[2] ) );
	}

	/**
	 * Reads the field back.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		return $this->update->verify( $this->applied, $outcome );
	}

	/**
	 * Assesses the rollback (as update_post). Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		return $this->update->assess_rollback( $rollback );
	}

	/**
	 * Rolls back (as update_post).
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		return $this->update->rollback( $rollback, $expected_state );
	}

	/**
	 * The post, the update_post arguments and the number of replacements, or a refusal. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array{0: object, 1: array<string, mixed>, 2: int}|Assessment
	 * @throws ToolError When the text does not match as required.
	 */
	private function plan( array $args ) {
		$old = (string) $args['old'];
		$new = (string) $args['new'];
		if ( $old === $new ) {
			throw new ToolError( 'old and new are the same; nothing to change.' );
		}
		$post_id = (int) $args['post_id'];
		$post    = Posts::find_untrashed( $post_id, 'edit_post' );
		if ( $post instanceof Assessment ) {
			return $post;
		}

		$field = self::FIELDS[ $args['field'] ];
		$value = (string) $post->$field;
		$count = substr_count( $value, $old );
		$all   = ! empty( $args['replace_all'] );
		if ( 0 === $count ) {
			$candidates = self::candidates( $value, $old, false );
			throw new ToolError( sprintf( 'no_match: "%s" does not occur in the %s of post %d.%s', esc_textarea( Posts::short( $old ) ), esc_textarea( $args['field'] ), (int) $post_id, array() === $candidates ? ' Read the post to copy the exact text.' : ' Case-insensitive matches: ' . esc_textarea( implode( ' | ', $candidates ) ) ) );
		}
		if ( $count > 1 && ! $all ) {
			throw new ToolError( sprintf( 'multiple_matches: "%s" occurs %d times in the %s of post %d. Give more surrounding text so it matches once, or set replace_all. Matches: %s', esc_textarea( Posts::short( $old ) ), (int) $count, esc_textarea( $args['field'] ), (int) $post_id, esc_textarea( implode( ' | ', self::candidates( $value, $old, true ) ) ) ) );
		}

		$replaced = $all ? str_replace( $old, $new, $value ) : substr_replace( $value, $new, (int) strpos( $value, $old ), strlen( $old ) );
		if ( 'post_content' === $field ) {
			$damage = Blocks::damage( $value, $replaced );
			if ( null !== $damage ) {
				return Assessment::prohibited( 'breaks_blocks', $damage );
			}
		}

		return array(
			$post,
			array(
				'post_id'      => $post_id,
				$args['field'] => $replaced,
			),
			$all ? $count : 1,
		);
	}

	/**
	 * Excerpts around the matches of `$needle` (case-sensitive or not), at most CANDIDATES.
	 *
	 * @param string $haystack       Text.
	 * @param string $needle         Text to find.
	 * @param bool   $case_sensitive Case-sensitive.
	 * @return string[]
	 */
	private static function candidates( string $haystack, string $needle, bool $case_sensitive ): array {
		$found  = array();
		$offset = 0;
		$limit  = self::CANDIDATES;
		while ( $limit-- > 0 ) {
			$at = $case_sensitive ? strpos( $haystack, $needle, $offset ) : stripos( $haystack, $needle, $offset );
			if ( false === $at ) {
				break;
			}
			$start   = max( 0, $at - 30 );
			$excerpt = substr( $haystack, $start, strlen( $needle ) + 60 );
			// Keep whole UTF-8 characters.
			$excerpt = (string) preg_replace( '/^[\x80-\xBF]+|[\xC0-\xFF][\x80-\xBF]*$/', '', $excerpt );
			$found[] = ( $start > 0 ? '...' : '' ) . str_replace( array( "\r", "\n" ), ' ', $excerpt ) . '...';
			$offset  = $at + max( 1, strlen( $needle ) );
		}
		return $found;
	}
}
