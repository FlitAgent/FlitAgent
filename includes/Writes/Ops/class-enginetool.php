<?php
/**
 * Base of the tools that purge several cache engines.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Writes\Ops;

use FlitAgent\Tools\System\CacheEngines;
use FlitAgent\Writes\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * Purges each detected engine in order and reports every one (`purged`, `skipped` or `failed` with a safe message); a
 * failing engine never stops the others.
 */
abstract class EngineTool extends RegenTool {

	/**
	 * Engine detector.
	 *
	 * @var CacheEngines
	 */
	protected $engines;

	/**
	 * Receives an engine id and a URL or null; throws to fail.
	 *
	 * @var callable
	 */
	private $purger;

	/**
	 * Constructor.
	 *
	 * @param CacheEngines|null $engines Detector, for tests.
	 * @param callable|null     $purger  Purger, for tests.
	 */
	public function __construct( ?CacheEngines $engines = null, ?callable $purger = null ) {
		$this->engines = $engines ? $engines : new CacheEngines();
		$this->purger  = $purger ? $purger : new Purger();
	}

	/**
	 * Purges the engines, in order.
	 *
	 * @param string[]    $engines Engine ids.
	 * @param string|null $url     URL, or null for everything.
	 * @return array<int, array<string, string>>
	 */
	protected function run( array $engines, ?string $url ): array {
		$report = array();
		foreach ( $engines as $engine ) {
			$row = array(
				'engine' => $engine,
				'label'  => CacheEngines::label( $engine ),
				'kind'   => CacheEngines::kind( $engine ),
			);
			if ( null !== $url && ! Purger::supports_url( $engine ) ) {
				$row['status']  = 'skipped';
				$row['message'] = 'This engine cannot purge a single URL, so it was not purged.';
			} else {
				try {
					call_user_func( $this->purger, $engine, $url );
					$row['status'] = 'purged';
				} catch ( \Throwable $e ) {
					$row['status']  = 'failed';
					$row['message'] = ErrorText::of( $e );
				}
			}
			$report[] = $row;
		}
		return $report;
	}

	/**
	 * Flags failed engines: a warning per failure, or a verification failure when nothing was purged because of them.
	 *
	 * @param array<string, mixed> $args    Arguments.
	 * @param Outcome              $outcome Outcome.
	 * @return Outcome
	 */
	public function verify( array $args, Outcome $outcome ): Outcome {
		$result   = $outcome->result();
		$statuses = array_column( isset( $result['engines'] ) ? $result['engines'] : array(), 'status' );
		$failed   = count( array_keys( $statuses, 'failed', true ) );
		if ( 0 === $failed ) {
			return $outcome;
		}
		if ( ! in_array( 'purged', $statuses, true ) ) {
			return $outcome->with_verification_failure( 'No engine could be purged; see engines for each error.' );
		}
		foreach ( $result['engines'] as $row ) {
			if ( 'failed' === $row['status'] ) {
				$outcome = $outcome->with_warning( 'engine_failed', sprintf( '%s could not be purged: %s', $row['label'], $row['message'] ) );
			}
		}
		return $outcome;
	}
}
