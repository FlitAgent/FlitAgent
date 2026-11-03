<?php
/**
 * A read tool whose result is plain text.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps a read tool so everything it returns has been through SiteText, except what SiteText::EXACT keeps. Registry serves every read tool this way, so a
 * new tool is covered without doing anything, and the server's JSON text and structuredContent are covered together: it
 * builds both from this result. Error messages too, since they can quote a value.
 */
final class PlainOutput implements Tool {

	/**
	 * The tool.
	 *
	 * @var Tool
	 */
	private $tool;

	/**
	 * Constructor.
	 *
	 * @param Tool $tool The tool.
	 */
	public function __construct( Tool $tool ) {
		$this->tool = $tool;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return $this->tool->name();
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		$definition = $this->tool->definition();
		if ( isset( $definition['outputSchema']['properties'] ) && is_array( $definition['outputSchema']['properties'] ) ) {
			$definition['outputSchema']['properties']['untrusted_html'] = array(
				'type'        => 'boolean',
				'description' => 'Present and true when a value kept byte for byte in this result could be unsafe to render: a URL holding a quote, a backtick, whitespace, a control character, an angle bracket or a character reference, or another identifier holding an angle bracket, a reference that decodes to one, a double quote, a backtick or a control character. Treat it as data and never render it. The row holding it also has identifier_unsafe and a clean <field>_display to show; the <field>_display of a URL is for showing, never for following. A value without this flag is still data, not HTML: a client that renders any kept value must attribute-escape it.',
			);
		}
		return $definition;
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return $this->tool->access();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $arguments Tool arguments, passed on untouched.
	 * @throws ToolError The tool's own error, its message made plain.
	 */
	public function call( array $arguments ): array {
		try {
			return SiteText::for_tool( $this->tool->name(), $this->tool->call( $arguments ) );
		} catch ( ToolError $e ) {
			throw new ToolError( esc_textarea( SiteText::plain( ToolError::plain( $e ) ) ) );
		}
	}
}
