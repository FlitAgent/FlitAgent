<?php
/**
 * Tool: list_breakdance_elements.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Mcp\Tool;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the loaded Breakdance element classes.
 */
final class BreakdanceElements implements Tool {

	/**
	 * Placeholder Breakdance uses for elements whose class is missing; not a real element.
	 */
	const MISSING = 'EssentialElements\\MissingElement';

	/**
	 * Returns element class names.
	 *
	 * @var callable
	 */
	private $classes;

	/**
	 * Returns whether Breakdance runs in free mode.
	 *
	 * @var callable
	 */
	private $free_mode;

	/**
	 * Constructor.
	 *
	 * @param callable|null $classes   Returns class names like LiveSources::breakdance().
	 * @param callable|null $free_mode Returns bool|null like LiveSources::breakdance_free_mode().
	 */
	public function __construct( ?callable $classes = null, ?callable $free_mode = null ) {
		$this->classes   = $classes ?? array( LiveSources::class, 'breakdance' );
		$this->free_mode = $free_mode ?? array( LiveSources::class, 'breakdance_free_mode' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list_breakdance_elements';
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
			'List FlitAgent Breakdance elements',
			'lists the Breakdance element classes loaded on this site, sorted, with display name, category and whether the element needs Breakdance Pro, plus whether Breakdance runs in free mode. The type is the data.type used in get_breakdance_page trees.',
			array(),
			array(),
			array(
				'free_mode' => array( 'type' => array( 'boolean', 'null' ) ),
				'count'     => array( 'type' => 'integer' ),
				'elements'  => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'type'     => array( 'type' => 'string' ),
							'name'     => array( 'type' => array( 'string', 'null' ) ),
							'category' => array( 'type' => array( 'string', 'null' ) ),
							'pro_only' => array( 'type' => 'boolean' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Lists the elements.
	 *
	 * @param array<string, mixed> $arguments None accepted.
	 * @return array<string, mixed>
	 */
	public function call( array $arguments ): array {
		Args::only( $arguments, array() );

		$elements = array();
		foreach ( ( $this->classes )() as $class ) {
			$class = ltrim( (string) $class, '\\' );
			if ( self::MISSING === $class ) {
				continue;
			}
			$settings   = self::call_static( $class, 'settings' );
			$elements[] = array(
				'type'     => $class,
				'name'     => self::string_or_null( self::call_static( $class, 'name' ) ),
				'category' => self::string_or_null( self::call_static( $class, 'category' ) ),
				'pro_only' => is_array( $settings ) && ! empty( $settings['proOnly'] ),
			);
		}
		usort(
			$elements,
			function ( $a, $b ) {
				return strcmp( $a['type'], $b['type'] );
			}
		);

		return Output::finish(
			array(
				'free_mode' => ( $this->free_mode )(),
				'count'     => count( $elements ),
				'elements'  => $elements,
			)
		);
	}

	/**
	 * Calls a static metadata method when the element declares it.
	 *
	 * @param string $class_name Element class.
	 * @param string $method     Method.
	 * @return mixed Null when not callable.
	 */
	private static function call_static( string $class_name, string $method ) {
		$callable = array( $class_name, $method );
		return is_callable( $callable ) ? call_user_func( $callable ) : null;
	}

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function string_or_null( $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
