<?php
/**
 * Write tool: purge_page_cache.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\System\CacheEngines;
use FlitAgent\Writes\Assessment;
use FlitAgent\Writes\Definition;
use FlitAgent\Writes\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * `purge_page_cache`: purges every active page cache plugin, then the CDN (origin first, CDN last), or one URL of this
 * site. A URL purge never falls back to purging everything: engines without a URL API are skipped.
 */
final class PurgePageCache extends EngineTool {

	/**
	 * Tool name.
	 */
	public function name(): string {
		return 'purge_page_cache';
	}

	/**
	 * Definition.
	 *
	 * @return array<string, mixed>
	 */
	public function definition(): array {
		return Definition::write(
			$this->name(),
			'FlitAgent: purge the page cache',
			'Purges the page cache through each active cache plugin\'s own API (LiteSpeed Cache, WP Rocket, SiteGround Speed Optimizer, W3 Total Cache, WP Super Cache, Breeze), then the Cloudflare plugin last, and reports each engine as purged, skipped or failed; one failing engine does not stop the others. With url, purges only that page of this site where the engine supports it (others are skipped, never purged in full). get_cache_info lists the active engines. Applied at once if an administrator enabled auto-approve for it; otherwise returns approval_required. Nothing to roll back: pages are cached again on the next visit.',
			array(
				'url' => array(
					'type'        => 'string',
					'maxLength'   => 2048,
					'description' => 'Optional full URL of one page on this site, e.g. "https://example.com/about/".',
				),
			),
			array(),
			$this->tiers(),
			true
		);
	}

	/**
	 * Assesses the purge. Read-only.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return Assessment
	 * @throws ToolError When the URL is not on this site, or no engine can do the purge.
	 */
	public function assess( array $args ): Assessment {
		$url = isset( $args['url'] ) ? (string) $args['url'] : null;
		if ( null !== $url && ! $this->on_this_site( $url ) ) {
			throw new ToolError( 'url must be an http or https URL on this site (same host and port as the home URL).' );
		}

		$refusal = self::refuse_without_manage_options();
		if ( null !== $refusal ) {
			return $refusal;
		}

		$engines = $this->engines->detected( CacheEngines::PAGE, CacheEngines::CDN );
		if ( array() === $engines ) {
			throw new ToolError( 'No supported page cache or CDN plugin is active (supported: LiteSpeed Cache, WP Rocket, SiteGround Speed Optimizer, W3 Total Cache, WP Super Cache, Breeze, Cloudflare). Use flush_object_cache for the object cache.' );
		}
		if ( null !== $url && array() === array_filter( $engines, array( Purger::class, 'supports_url' ) ) ) {
			throw new ToolError( sprintf( 'None of the active engines (%s) can purge a single URL. Call purge_page_cache without url to purge everything.', esc_textarea( implode( ', ', array_map( array( CacheEngines::class, 'label' ), $engines ) ) ) ) );
		}

		$labels = implode( ', ', array_map( array( CacheEngines::class, 'label' ), $engines ) );
		return Assessment::reversible(
			null === $url ? sprintf( 'Purge the whole page cache: %s.', $labels ) : sprintf( 'Purge %s from the page cache: %s.', $url, $labels ),
			array(
				'engines' => $engines,
				'url'     => $url,
			),
			array( 'engines' => $engines ),
			true
		);
	}

	/**
	 * Purges each engine.
	 *
	 * @param array<string, mixed> $args       Arguments.
	 * @param Assessment           $assessment Assessment.
	 * @return Outcome
	 */
	public function apply( array $args, Assessment $assessment ): Outcome {
		$url = isset( $args['url'] ) ? (string) $args['url'] : null;
		return new Outcome(
			array(
				'url'     => $url,
				'engines' => $this->run( $assessment->preconditions()['engines'], $url ),
			),
			self::regen( 'cache', 'page' ),
			self::no_fingerprint()
		);
	}

	/**
	 * Whether a URL is http(s), on the home URL's host and port, without user info.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function on_this_site( string $url ): bool {
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['host'] ) || empty( $home['host'] ) ) {
			return false;
		}
		if ( ! isset( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$port      = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		$home_port = isset( $home['port'] ) ? (int) $home['port'] : null;
		return strtolower( $parts['host'] ) === strtolower( $home['host'] ) && $port === $home_port;
	}
}
