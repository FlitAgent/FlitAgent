<?php
/**
 * The get_transient tool.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Settings;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Arguments;
use FlitAgent\Tools\Definition;
use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Reads one transient and its expiry (WP-CLI `transient get`).
 */
final class TransientGet implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_transient';
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
			'Get a transient with FlitAgent',
			'reads one cached transient and when it expires (ISO 8601, null if it never expires). Set network to true for a site transient (get_site_transient). Secret-looking names are refused and nested secret fields redacted.',
			array(
				'name'    => array( 'type' => 'string' ),
				'network' => array(
					'type'        => 'boolean',
					'description' => 'Read a network-wide site transient.',
				),
			),
			array( 'name' )
		);
	}

	/**
	 * Reads the transient.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the name is refused or the transient is not set.
	 */
	public function call( array $arguments ): array {
		Arguments::only( $arguments, array( 'name', 'network' ), $this->name() );
		$name    = (string) Arguments::string( $arguments, 'name', true );
		$network = Arguments::bool( $arguments, 'network', false );
		Names::check( $name, 'transient' );

		$value = $network ? get_site_transient( $name ) : get_transient( $name );
		if ( false === $value ) {
			throw new ToolError( sprintf( 'Transient "%s" is not set (or has expired).', esc_textarea( $name ) ) );
		}

		if ( $network ) {
			$timeout = is_multisite() ? get_site_option( '_site_transient_timeout_' . $name ) : get_option( '_site_transient_timeout_' . $name );
		} else {
			$timeout = get_option( '_transient_timeout_' . $name );
		}

		return array(
			'name'       => $name,
			'network'    => $network,
			'type'       => Values::type( $value ),
			'value'      => Redactor::redact( $value ),
			'expires_at' => Values::iso( $timeout ),
		);
	}
}
