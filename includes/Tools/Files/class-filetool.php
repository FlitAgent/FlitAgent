<?php
/**
 * Shared parts of the file tools.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Files;

use FlitAgent\Mcp\Tool;
use FlitAgent\Mcp\ToolError;
use FlitAgent\Tools\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Base for read_file, list_files, search_files and get_file_outline: protected access, the root argument and the
 * sandbox.
 */
abstract class FileTool implements Tool {

	/**
	 * Sandbox injected by tests; null means the running site's roots.
	 *
	 * @var Sandbox|null
	 */
	private $sandbox;

	/**
	 * Creates the tool.
	 *
	 * @param Sandbox|null $sandbox Sandbox; defaults to the running site's roots, built on each call.
	 */
	public function __construct( ?Sandbox $sandbox = null ) {
		$this->sandbox = $sandbox;
	}

	/**
	 * {@inheritDoc}
	 */
	public function access(): string {
		return Tool::ACCESS_PROTECTED;
	}

	/**
	 * The sandbox for this call.
	 *
	 * @return Sandbox
	 */
	protected function sandbox(): Sandbox {
		return null !== $this->sandbox ? $this->sandbox : Sandbox::from_wordpress();
	}

	/**
	 * Input schema for the `root` argument.
	 *
	 * @return array<string, mixed>
	 */
	protected static function root_schema(): array {
		return array(
			'type'        => 'string',
			'enum'        => Sandbox::ROOTS,
			'default'     => 'theme',
			'description' => 'theme: the active theme. parent_theme: its parent, when the active theme is a child theme. themes, plugins, mu_plugins: those wp-content directories. Uploads and wp-config.php are never readable.',
		);
	}

	/**
	 * Refuses arguments the tool does not take.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param string[]             $allowed   Allowed names.
	 * @throws ToolError On an unknown argument.
	 */
	protected function reject_unknown( array $arguments, array $allowed ): void {
		foreach ( array_keys( $arguments ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new ToolError( sprintf( 'Unknown argument. %s accepts only %s.', esc_textarea( $this->name() ), esc_textarea( implode( ', ', $allowed ) ) ) );
			}
		}
	}

	/**
	 * An optional integer argument within bounds.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param string               $name      Argument name.
	 * @param int|null             $fallback  Value when absent.
	 * @param int                  $min       Smallest allowed.
	 * @param int                  $max       Largest allowed.
	 * @return int|null
	 * @throws ToolError When the value is not an integer within bounds.
	 */
	protected static function int_argument( array $arguments, string $name, ?int $fallback, int $min, int $max ): ?int {
		if ( ! array_key_exists( $name, $arguments ) || null === $arguments[ $name ] ) {
			return $fallback;
		}
		$value = $arguments[ $name ];
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			throw new ToolError( sprintf( '%s must be an integer from %d to %d.', esc_textarea( $name ), (int) $min, (int) $max ) );
		}
		return $value;
	}

	/**
	 * An optional boolean argument.
	 *
	 * @param array<string, mixed> $arguments Arguments.
	 * @param string               $name      Argument name.
	 * @param bool                 $fallback  Value when absent.
	 * @return bool
	 * @throws ToolError When the value is not a boolean.
	 */
	protected static function bool_argument( array $arguments, string $name, bool $fallback ): bool {
		if ( ! array_key_exists( $name, $arguments ) || null === $arguments[ $name ] ) {
			return $fallback;
		}
		if ( ! is_bool( $arguments[ $name ] ) ) {
			throw new ToolError( sprintf( '%s must be true or false.', esc_textarea( $name ) ) );
		}
		return $arguments[ $name ];
	}

	/**
	 * Redacts file text, refusing to return it when redaction fails.
	 *
	 * @param string $text Text.
	 * @return string
	 * @throws ToolError When the text could not be redacted safely.
	 */
	protected static function redact( string $text ): string {
		$clean = Redactor::redact_text( $text );
		if ( null === $clean ) {
			throw new ToolError( 'The file could not be checked for secrets safely, so it is not returned.' );
		}
		return $clean;
	}
}
