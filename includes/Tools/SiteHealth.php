<?php
/**
 * Tool: get_site_health.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools;

use FlitAgent\Mcp\Tool;

defined( 'ABSPATH' ) || exit;

/**
 * Returns software versions, configuration flags and the last saved Site Health counts.
 *
 * It never runs Site Health tests: they make loopback and HTTP requests. It reads the counts WordPress saved the last
 * time the Site Health screen or its weekly cron check ran.
 */
final class SiteHealth implements Tool {

	/**
	 * Transient where WordPress saves the last Site Health status counts as JSON.
	 */
	const STATUS_TRANSIENT = 'health-check-site-status-result';

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_site_health';
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
		$string  = array( 'type' => 'string' );
		$boolean = array( 'type' => 'boolean' );
		$integer = array( 'type' => 'integer' );

		return array(
			'name'         => $this->name(),
			'title'        => 'Get FlitAgent site health',
			'description'  => 'From the FlitAgent WordPress plugin: returns the WordPress, PHP and database versions, whether the site is multisite and in debug mode, and the issue counts (good, recommended, critical) from the last Site Health check WordPress saved. Requires an administrator login.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'wordpress_version' => $string,
					'php_version'       => $string,
					'database'          => array(
						'type'        => 'object',
						'description' => 'Database server. For sqlite (the SQLite integration), version is the MySQL version it emulates.',
						'properties'  => array(
							'type'    => array(
								'type' => 'string',
								'enum' => array( 'mysql', 'mariadb', 'sqlite' ),
							),
							'version' => $string,
						),
						'required'    => array( 'type', 'version' ),
					),
					'is_multisite'      => $boolean,
					'debug_mode'        => $boolean,
					'site_health'       => array(
						'type'        => array( 'object', 'null' ),
						'description' => 'Null when WordPress has not saved a Site Health result yet.',
						'properties'  => array(
							'good'        => $integer,
							'recommended' => $integer,
							'critical'    => $integer,
						),
						'required'    => array( 'good', 'recommended', 'critical' ),
					),
					'site_health_note'  => $string,
				),
				'required'   => array( 'wordpress_version', 'php_version', 'database', 'is_multisite', 'debug_mode', 'site_health', 'site_health_note' ),
			),
			'annotations'  => Annotations::read_only( 'Get FlitAgent site health' ),
		);
	}

	/**
	 * Returns the health summary. Takes no arguments.
	 *
	 * @param array<string, mixed> $arguments Ignored.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		$counts = self::saved_counts();

		return array(
			'wordpress_version' => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
			'php_version'       => PHP_VERSION,
			'database'          => self::database(),
			'is_multisite'      => (bool) is_multisite(),
			'debug_mode'        => defined( 'WP_DEBUG' ) && (bool) WP_DEBUG,
			'site_health'       => $counts,
			'site_health_note'  => null === $counts
				? 'No Site Health result is saved yet. Open Tools > Site Health in wp-admin to run the checks.'
				: 'Counts from the last Site Health check WordPress saved. Open Tools > Site Health in wp-admin to refresh them.',
		);
	}

	/**
	 * Reads the saved Site Health counts.
	 *
	 * @return array<string, int>|null Null when nothing valid is saved.
	 */
	public static function saved_counts(): ?array {
		$saved = get_transient( self::STATUS_TRANSIENT );
		if ( ! is_string( $saved ) ) {
			return null;
		}

		$decoded = json_decode( $saved, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$counts = array();
		foreach ( array( 'good', 'recommended', 'critical' ) as $key ) {
			if ( ! isset( $decoded[ $key ] ) || ! is_numeric( $decoded[ $key ] ) ) {
				return null;
			}
			$counts[ $key ] = (int) $decoded[ $key ];
		}
		return $counts;
	}

	/**
	 * Identifies the database server from the connection, without running a query.
	 *
	 * @return array<string, string>
	 */
	private static function database(): array {
		global $wpdb;

		$info = (string) $wpdb->db_server_info();

		if ( false !== stripos( $info, 'mariadb' ) ) {
			// Older client libraries prefix MariaDB versions with "5.5.5-".
			$info = (string) preg_replace( '/^5\.5\.5-/', '', $info );

			return array(
				'type'    => 'mariadb',
				'version' => (string) preg_replace( '/[^0-9.].*/', '', $info ),
			);
		}

		return array(
			'type'    => false !== stripos( $info, 'sqlite' ) ? 'sqlite' : 'mysql',
			'version' => (string) $wpdb->db_version(),
		);
	}
}
