<?php
/**
 * Write tool: save_elementor_page.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

use FlitAgent\Writes\Assessment;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the Elementor layout of a post or page (not a template).
 */
final class SaveElementorPage extends ElementorSave {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'save_elementor_page';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function title(): string {
		return 'FlitAgent: save an Elementor page';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function describe(): string {
		return 'Replaces the Elementor element tree of one post or page through Elementor\'s own document save (which filters HTML for users without unfiltered_html), marks it as built with Elementor and regenerates its CSS. For templates use save_elementor_template.';
	}

	/**
	 * Templates go through save_elementor_template.
	 *
	 * @param object $post Post.
	 * @return Assessment|null
	 */
	protected function check_type( $post ): ?Assessment {
		if ( 'elementor_library' === $post->post_type ) {
			return Assessment::prohibited( 'wrong_type', sprintf( 'Post %d is an Elementor template; use save_elementor_template.', (int) $post->ID ) );
		}
		return null;
	}
}
