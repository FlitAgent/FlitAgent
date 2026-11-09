<?php
/**
 * The list_rewrite_rules tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\System;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Lists stored rewrite rules (WP-CLI `rewrite list`).
 */
final class RewriteRules implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_rewrite_rules';
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * {@inheritDoc}
	 */
	public function definition(): array {
		return Definition::read_only(
			$this->name(),
			'List rewrite rules with FlitAgent',
			'lists the permalink structure and the stored rewrite rules (regex pattern and the query it maps to), in matching order. search keeps rules whose pattern or query contains the text. Plain permalinks have no rules.',
			array(
				'search' => array( 'type' => 'string' ),
				'limit'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 500,
					'default' => 100,
				),
			)
		);
	}

	/**
	 * Runs the tool.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError On invalid arguments.
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array( 'search', 'limit' ), $this->name() );
		$search = Arguments::string( $arguments, 'search', false );
		$limit  = Arguments::int( $arguments, 'limit', 100, 1, 500 );

		$structure = (string) get_option( 'permalink_structure' );
		$stored    = get_option( 'rewrite_rules' );
		$rules     = array();
		foreach ( is_array( $stored ) ? $stored : array() as $pattern => $query ) {
			if ( null !== $search && false === stripos( (string) $pattern, $search ) && false === stripos( (string) $query, $search ) ) {
				continue;
			}
			$rules[] = array(
				'pattern' => (string) $pattern,
				'query'   => (string) $query,
			);
		}

		return array(
			'permalink_structure' => $structure,
			'rules'               => array_slice( $rules, 0, $limit ),
			'total'               => count( $rules ),
			'note'                => '' === $structure ? 'Plain permalinks are in use, so WordPress stores no rewrite rules.' : null,
		);
	}
}
