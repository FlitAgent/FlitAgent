<?php
/**
 * Tool: get_beaver_module_schema.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Builders;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Returns one Beaver Builder module's settings fields (from its registered form) and default settings.
 */
final class BeaverModuleSchema implements Tool {

	/**
	 * Returns the module registry.
	 *
	 * @var callable
	 */
	private $modules;

	/**
	 * Returns a module's defaults.
	 *
	 * @var callable
	 */
	private $defaults;

	/**
	 * Constructor.
	 *
	 * @param callable|null $modules  Returns modules like LiveSources::beaver().
	 * @param callable|null $defaults Returns a module's defaults like LiveSources::beaver_defaults().
	 */
	public function __construct( ?callable $modules = null, ?callable $defaults = null ) {
		$this->modules  = $modules ?? array( LiveSources::class, 'beaver' );
		$this->defaults = $defaults ?? array( LiveSources::class, 'beaver_defaults' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'get_beaver_module_schema';
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
			'Get FlitAgent Beaver Builder module schema',
			'returns one Beaver Builder module\'s settings fields from its registered form (name, type, label, tab, section; with names or prefix also default, option keys and responsive) and its default settings. Secret-looking defaults are redacted.',
			array_merge(
				array(
					'slug' => array(
						'type'        => 'string',
						'description' => 'Module slug from list_beaver_modules, e.g. "heading".',
					),
				),
				Args::filter_schema( 'fields' )
			),
			array( 'slug' ),
			array(
				'slug'     => array( 'type' => 'string' ),
				'name'     => array( 'type' => 'string' ),
				'mode'     => array(
					'type' => 'string',
					'enum' => array( 'discovery', 'targeted', 'prefix' ),
				),
				'count'    => array( 'type' => 'integer' ),
				'fields'   => array( 'type' => 'array' ),
				'defaults' => array(
					'type'        => array( 'object', 'array' ),
					'description' => 'Default settings keyed by field name (an empty list when there are none).',
				),
			)
		);
	}

	/**
	 * Returns the schema.
	 *
	 * @param array<string, mixed> $arguments slug, optional names or prefix.
	 * @return array<string, mixed>
	 * @throws ToolError On invalid arguments or an unknown module.
	 */
	public function call( array $arguments ): array {
		Args::only( $arguments, array( 'slug', 'names', 'prefix' ) );
		$slug   = Args::slug( $arguments );
		$filter = Args::filter( $arguments );

		$modules = ( $this->modules )();
		$module  = $modules[ $slug ] ?? null;
		if ( ! is_object( $module ) ) {
			throw new ToolError( sprintf( 'No Beaver Builder module is loaded as "%s". List them with list_beaver_modules.', esc_textarea( $slug ) ) );
		}

		$fields   = self::fields( isset( $module->form ) && is_array( $module->form ) ? $module->form : array(), $filter );
		$defaults = array();
		foreach ( (array) Output::plain( ( $this->defaults )( $slug ) ) as $key => $value ) {
			if ( Args::matches( (string) $key, $filter ) ) {
				$defaults[ $key ] = $value;
			}
		}

		return Output::finish(
			array(
				'slug'     => $slug,
				'name'     => isset( $module->name ) && is_string( $module->name ) && '' !== $module->name ? $module->name : $slug,
				'mode'     => $filter['mode'],
				'count'    => count( $fields ),
				'fields'   => $fields,
				'defaults' => $defaults,
			)
		);
	}

	/**
	 * Walks the form: tabs → sections → fields. Tabs rendered from a template file have no fields and are skipped.
	 *
	 * @param array<mixed>                                         $form   Module form.
	 * @param array{mode: string, names: string[], prefix: string} $filter Filter.
	 * @return array<int, array<string, mixed>>
	 */
	private static function fields( array $form, array $filter ): array {
		$out = array();
		foreach ( $form as $tab => $tab_config ) {
			if ( ! is_array( $tab_config ) || ! isset( $tab_config['sections'] ) || ! is_array( $tab_config['sections'] ) ) {
				continue;
			}
			foreach ( $tab_config['sections'] as $section => $section_config ) {
				if ( ! is_array( $section_config ) || ! isset( $section_config['fields'] ) || ! is_array( $section_config['fields'] ) ) {
					continue;
				}
				foreach ( $section_config['fields'] as $name => $field ) {
					$name = (string) $name;
					if ( ! is_array( $field ) || ! Args::matches( $name, $filter ) ) {
						continue;
					}
					$out[] = self::field( $name, $field, (string) $tab, (string) $section, 'discovery' !== $filter['mode'] );
				}
			}
		}
		return $out;
	}

	/**
	 * One field.
	 *
	 * @param string       $name    Field name.
	 * @param array<mixed> $field   Field config.
	 * @param string       $tab     Tab key.
	 * @param string       $section Section key.
	 * @param bool         $details Whether to add default, options and responsive.
	 * @return array<string, mixed>
	 */
	private static function field( string $name, array $field, string $tab, string $section, bool $details ): array {
		$entry = array(
			'name'    => $name,
			'type'    => isset( $field['type'] ) ? (string) $field['type'] : null,
			'label'   => isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : null,
			'tab'     => $tab,
			'section' => $section,
		);
		if ( ! $details ) {
			return $entry;
		}

		if ( isset( $field['default'] ) && '' !== $field['default'] && array() !== $field['default'] ) {
			$entry['default'] = Redactor::is_secret_key( $name ) ? Redactor::MASK : $field['default'];
		}
		if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
			$entry['options'] = array_map( 'strval', array_keys( $field['options'] ) );
		}
		if ( ! empty( $field['responsive'] ) ) {
			$entry['responsive'] = true;
		}
		return $entry;
	}
}
