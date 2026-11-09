<?php
/**
 * Write tool: save_elementor_template.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Builders;

use FlitAgent\Writes\Assessment;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the elements of an Elementor template (`elementor_library`). Always destructive: templates can render
 * site-wide (Theme Builder headers, footers, archives) or be reused across pages. Display conditions are not changed.
 */
final class SaveElementorTemplate extends ElementorSave {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'save_elementor_template';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function title(): string {
		return 'FlitAgent: save an Elementor template';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function describe(): string {
		return 'Replaces the element tree of one existing Elementor template (elementor_library post: section, page, header, footer, single, archive, popup...) through Elementor\'s own document save and regenerates its CSS. Display conditions are not changed. Templates can appear site-wide, so every save needs approval.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function always_destructive(): bool {
		return true;
	}

	/**
	 * Only templates.
	 *
	 * @param object $post Post.
	 * @return Assessment|null
	 */
	protected function check_type( $post ): ?Assessment {
		if ( 'elementor_library' !== $post->post_type ) {
			return Assessment::prohibited( 'wrong_type', sprintf( 'Post %d is not an Elementor template; use save_elementor_page.', (int) $post->ID ) );
		}
		return null;
	}
}
