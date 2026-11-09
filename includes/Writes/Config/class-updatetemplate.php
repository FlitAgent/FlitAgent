<?php
/**
 * Write tool: update_template.
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
 * `update_template`: changes the content (and optionally title and description) of a block template or template part
 * of the active block theme through the core templates controllers. Editing a theme-file template creates a
 * customization (rollback deletes it, reverting to the file); editing a customization saves a revision of it first
 * (rollback restores that revision). Never auto-approvable: templates are public.
 */
final class UpdateTemplate implements WriteTool, Reversible {

	/**
	 * Argument => REST field compared and sent.
	 */
	const FIELDS = array(
		'content'     => 'content',
		'title'       => 'title',
		'description' => 'description',
	);

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'update_template';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: update a block template',
			'Replaces the block markup (and optionally the title and description) of a template or template part of the active block theme, as the Site Editor does. template_id is "theme//slug", e.g. "twentytwentyfive//index"; kind template_part for parts such as the header. Always returns approval_required; undo with rollback_change (restores the previous version, or removes the customization so the theme file is used again).',
			array(
				'template_id' => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9_.-]{1,100}//[A-Za-z0-9_.-]{1,100}$',
					'description' => 'Template id, theme//slug.',
				),
				'kind'        => array(
					'type'        => 'string',
					'enum'        => array( 'template', 'template_part' ),
					'description' => 'template (default) or template_part.',
				),
				'content'     => array(
					'type'        => 'string',
					'maxLength'   => 2000000,
					'description' => 'The complete new block markup.',
				),
				'title'       => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'description' => array(
					'type'      => 'string',
					'maxLength' => 1000,
				),
			),
			array( 'template_id', 'content' ),
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
	 * Never auto-approvable: templates are public.
	 */
	public function may_auto_approve(): bool {
		return false;
	}

	/**
	 * Assesses the change. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When nothing would change.
	 */
	public function assess( array $args ): Assessment {
		$id       = (string) $args['template_id'];
		$template = self::editable( $id, self::kind( $args ) );
		if ( $template instanceof Assessment ) {
			return $template;
		}
		$state   = self::state( $template );
		$changes = self::changes( $args );
		$before  = array_intersect_key( $state, $changes );
		if ( array_map( 'strval', $changes ) === $before ) {
			throw new ToolError( sprintf( 'Template %s already has that %s; nothing to change.', esc_textarea( $id ), esc_textarea( implode( ', ', array_keys( $changes ) ) ) ) );
		}
		return Assessment::reversible(
			sprintf( 'Change the %s of %s %s (%s).', implode( ', ', array_keys( $changes ) ), 'template_part' === self::kind( $args ) ? 'template part' : 'template', $id, $state['wp_id'] > 0 ? 'customized' : 'from the theme file, creating a customization' ),
			array(
				'template_id' => $id,
				'before'      => $before,
				'after'       => array_map( 'strval', $changes ),
			),
			array( 'fingerprint' => Canonical::hash( $state ) ),
			false
		);
	}

	/**
	 * Saves a revision of a customization, then writes through the templates controller.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment made just before.
	 * @return Outcome
	 * @throws ToolError When the template changed or WordPress refused.
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$id    = (string) $args['template_id'];
		$kind  = self::kind( $args );
		$state = self::state( (array) Rest::read( self::route( $kind, $id ) ) );
		if ( Canonical::hash( $state ) !== ( $assessment->preconditions()['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Template %s changed after the request was assessed; nothing was changed.', esc_textarea( $id ) ) );
		}
		$revision = $state['wp_id'] > 0 ? Revisions::save_current( $state['wp_id'] ) : 0;
		$after    = Rest::write( 'POST', self::route( $kind, $id ), self::changes( $args ) );
		$wp_id    = isset( $after['wp_id'] ) ? (int) $after['wp_id'] : 0;
		$created  = 0 === $state['wp_id'];

		return new Outcome(
			array(
				'template_id'        => $id,
				'wp_id'              => $wp_id,
				'created'            => $created,
				'before_revision_id' => $revision,
			),
			$created
				? Rollback::make(
					Rollback::SNAPSHOT,
					'template',
					$id,
					array(
						'kind'    => $kind,
						'created' => true,
						'wp_id'   => $wp_id,
					)
				)
				: Rollback::make(
					Rollback::REVISION,
					'template',
					$id,
					array(
						'kind'        => $kind,
						'wp_id'       => $state['wp_id'],
						'revision_id' => $revision,
					)
				),
			array( 'fingerprint' => Canonical::hash( self::state( (array) Rest::read( self::route( $kind, $id ) ) ) ) )
		);
	}

	/**
	 * Reads the template back; content filters may store it differently.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$template = Rest::read( self::route( self::kind( $args ), (string) $args['template_id'] ) );
		if ( null === $template ) {
			return $outcome->with_verification_failure( 'The template could not be read back.' );
		}
		$changes = self::changes( $args );
		if ( array_intersect_key( self::state( $template ), $changes ) === array_map( 'strval', $changes ) ) {
			return $outcome->with_result( array( 'stored_verbatim' => true ) );
		}
		return $outcome->with_result( array( 'stored_verbatim' => false ) )->with_warning( 'stored_differently', 'WordPress stored the template differently from the request (a filter changed it).' );
	}

	/**
	 * Assesses undoing the change. Read-only.
	 *
	 * @param array<string, mixed> $rollback Rollback reference.
	 * @return Assessment
	 */
	public function assess_rollback( array $rollback ): Assessment {
		$id       = isset( $rollback['target']['id'] ) ? (string) $rollback['target']['id'] : '';
		$refs     = isset( $rollback['refs'] ) && is_array( $rollback['refs'] ) ? $rollback['refs'] : array();
		$template = self::editable( $id, isset( $refs['kind'] ) ? (string) $refs['kind'] : 'template' );
		if ( $template instanceof Assessment ) {
			return $template;
		}
		$state   = self::state( $template );
		$current = array(
			'content'     => $state['content'],
			'title'       => $state['title'],
			'description' => $state['description'],
		);
		if ( ! empty( $refs['created'] ) ) {
			return Assessment::reversible(
				sprintf( 'Remove the customization of template %s that the write created; the theme file is used again.', $id ),
				array(
					'template_id' => $id,
					'current'     => $current,
					'restored'    => array( 'content' => '(the theme file version)' ),
				),
				array( 'fingerprint' => Canonical::hash( $state ) ),
				false
			);
		}
		$revision = Revisions::revision( isset( $refs['revision_id'] ) ? (int) $refs['revision_id'] : 0, isset( $refs['wp_id'] ) ? (int) $refs['wp_id'] : 0 );
		if ( null === $revision ) {
			return Assessment::prohibited( 'revision_missing', sprintf( 'The revision of template %s that this rollback would restore no longer exists.', $id ) );
		}
		return Assessment::reversible(
			sprintf( 'Restore template %s from revision %d.', $id, $revision->ID ),
			array(
				'template_id' => $id,
				'current'     => $current,
				'restored'    => array(
					'content'     => (string) $revision->post_content,
					'title'       => (string) $revision->post_title,
					'description' => (string) $revision->post_excerpt,
				),
			),
			array( 'fingerprint' => Canonical::hash( $state ) ),
			false
		);
	}

	/**
	 * Removes the customization or restores the revision, if the template is still as assessed.
	 *
	 * @param array<string, mixed> $rollback       Rollback reference.
	 * @param array<string, mixed> $expected_state Preconditions of the rollback assessment.
	 * @return Outcome
	 * @throws ToolError When the template changed or WordPress refused.
	 */
	public function rollback( array $rollback, array $expected_state ): Outcome {
		$id       = (string) $rollback['target']['id'];
		$refs     = (array) $rollback['refs'];
		$kind     = isset( $refs['kind'] ) ? (string) $refs['kind'] : 'template';
		$template = self::editable( $id, $kind );
		if ( $template instanceof Assessment || Canonical::hash( self::state( $template ) ) !== ( $expected_state['fingerprint'] ?? '' ) ) {
			throw new ToolError( sprintf( 'Template %s changed after the rollback was assessed; nothing was restored.', esc_textarea( $id ) ) );
		}
		if ( ! empty( $refs['created'] ) ) {
			Rest::write( 'DELETE', self::route( $kind, $id ), array( 'force' => true ) );
			return new Outcome(
				array(
					'template_id'           => $id,
					'customization_removed' => true,
				),
				Rollback::none()
			);
		}
		Revisions::restore( (int) $refs['revision_id'] );
		return new Outcome(
			array(
				'template_id'          => $id,
				'restored_revision_id' => (int) $refs['revision_id'],
			),
			Rollback::none()
		);
	}

	/**
	 * The template, or a refusal: no capability, not a block theme, another theme's template, missing, or a
	 * customization whose revisions are off.
	 *
	 * @param string $id   Template id.
	 * @param string $kind template or template_part.
	 * @return array<string, mixed>|Assessment
	 */
	private static function editable( string $id, string $kind ) {
		$refusal = Menus::refusal();
		if ( null !== $refusal ) {
			return $refusal;
		}
		if ( ! wp_is_block_theme() ) {
			return Assessment::prohibited( 'not_block_theme', 'The active theme is not a block theme, so it has no editable block templates.' );
		}
		$theme = (string) strstr( $id, '//', true );
		if ( get_stylesheet() !== $theme ) {
			return Assessment::prohibited( 'not_active_theme', sprintf( 'Template %s belongs to theme %s, which is not the active theme (%s).', $id, $theme, get_stylesheet() ) );
		}
		$template = Rest::read( self::route( $kind, $id ) );
		if ( null === $template ) {
			return Assessment::prohibited( 'not_found', sprintf( 'The %s %s was not found or you cannot edit it.', 'template_part' === $kind ? 'template part' : 'template', $id ) );
		}
		$wp_id = isset( $template['wp_id'] ) ? (int) $template['wp_id'] : 0;
		if ( $wp_id > 0 ) {
			$post    = get_post( $wp_id );
			$refusal = $post ? Revisions::refusal( $post, 'template ' . $id ) : Assessment::prohibited( 'not_found', sprintf( 'The template %s was not found or you cannot edit it.', $id ) );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		return $template;
	}

	/**
	 * The parts of a template the tool compares: post id, content, title, description.
	 *
	 * @param array<string, mixed> $template Template from REST.
	 * @return array{wp_id: int, content: string, title: string, description: string}
	 */
	private static function state( array $template ): array {
		$raw = static function ( $value ): string {
			return is_array( $value ) ? (string) ( $value['raw'] ?? '' ) : (string) $value;
		};
		return array(
			'wp_id'       => isset( $template['wp_id'] ) ? (int) $template['wp_id'] : 0,
			'content'     => $raw( $template['content'] ?? '' ),
			'title'       => $raw( $template['title'] ?? '' ),
			'description' => (string) ( $template['description'] ?? '' ),
		);
	}

	/**
	 * Requested fields in FIELDS order (approved arguments come back key-sorted).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, string>
	 */
	private static function changes( array $args ): array {
		$changes = array();
		foreach ( array_keys( self::FIELDS ) as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$changes[ $field ] = (string) $args[ $field ];
			}
		}
		return $changes;
	}

	/**
	 * Kind from the arguments.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return string
	 */
	private static function kind( array $args ): string {
		return isset( $args['kind'] ) && 'template_part' === $args['kind'] ? 'template_part' : 'template';
	}

	/**
	 * Controller route of a template.
	 *
	 * @param string $kind template or template_part.
	 * @param string $id   Template id (theme//slug, validated by the schema).
	 * @return string
	 */
	private static function route( string $kind, string $id ): string {
		return '/wp/v2/' . ( 'template_part' === $kind ? 'template-parts' : 'templates' ) . '/' . $id;
	}
}
