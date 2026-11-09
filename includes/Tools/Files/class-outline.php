<?php
/**
 * Structural outlines of source files.
 *
 * @package FlitAgent
 */

namespace FlitAgent\Tools\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a symbol outline from source text. PHP is tokenized with token_get_all(), which never executes or includes
 * the code; JavaScript and CSS are scanned line by line with anchored, linear patterns.
 */
final class Outline {

	/**
	 * WordPress hook functions whose literal first argument is a hook name.
	 */
	const HOOK_FUNCTIONS = array( 'add_action', 'add_filter', 'remove_action', 'remove_filter', 'do_action', 'do_action_ref_array', 'apply_filters', 'apply_filters_ref_array' );

	/**
	 * Longest name returned, in characters.
	 */
	const MAX_NAME_CHARS = 200;

	/**
	 * The outline language for a file name: php, js, css or other.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	public static function language( string $path ): string {
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, array( 'php', 'phtml', 'inc' ), true ) ) {
			return 'php';
		}
		if ( in_array( $extension, array( 'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx' ), true ) ) {
			return 'js';
		}
		if ( in_array( $extension, array( 'css', 'scss', 'sass', 'less' ), true ) ) {
			return 'css';
		}
		return 'other';
	}

	/**
	 * Outlines source text.
	 *
	 * @param string $language Language from language().
	 * @param string $text     Source text.
	 * @return array<int, array<string, mixed>>
	 */
	public static function build( string $language, string $text ): array {
		switch ( $language ) {
			case 'php':
				return self::php( $text );
			case 'js':
				return self::by_line( $text, '/^\s*(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s*\*?\s*([A-Za-z_$][\w$]*)/', 'function' );
			case 'css':
				return self::by_line( $text, '/^\s*@(media|supports|container|layer|keyframes)\b([^{;\r\n]*)/i', null );
			default:
				return array();
		}
	}

	/**
	 * Outlines PHP: namespace, class-like declarations, functions and methods, and hooks with literal names.
	 *
	 * @param string $code PHP source.
	 * @return array<int, array<string, mixed>>
	 */
	private static function php( string $code ): array {
		$tokens  = token_get_all( $code );
		$count   = count( $tokens );
		$outline = array();
		$scopes  = array();
		// One per open brace: array( type, class name ).
		$pending = null;
		// Scope type the next "{" opens: class, anon, function.
		$name = null;
		// Class name the pending class scope carries.
		$kinds = self::class_kinds();

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) ) {
				if ( '{' === $token ) {
					$scopes[] = array( null !== $pending ? $pending : 'other', $name );
					$pending  = null;
					$name     = null;
				} elseif ( '}' === $token ) {
					array_pop( $scopes );
				} elseif ( ';' === $token ) {
					$pending = null;
					$name    = null;
				}
				continue;
			}

			$id = $token[0];

			if ( T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
				$scopes[] = array( 'other', null );
				continue;
			}

			if ( T_NAMESPACE === $id ) {
				$namespace = self::qualified_name( $tokens, self::next( $tokens, $i ) );
				if ( '' !== $namespace ) {
					$outline[] = self::item( 'namespace', $namespace, $token[2] );
				}
				continue;
			}

			if ( isset( $kinds[ $id ] ) ) {
				$previous = self::previous( $tokens, $i );
				if ( null !== $previous && is_array( $tokens[ $previous ] ) && in_array( $tokens[ $previous ][0], array( T_NEW, T_DOUBLE_COLON ), true ) ) {
					// new class {...} is anonymous; Foo::class is a constant.
					$pending = T_NEW === $tokens[ $previous ][0] ? 'anon' : $pending;
					continue;
				}
				$next = self::next( $tokens, $i );
				if ( null !== $next && is_array( $tokens[ $next ] ) && T_STRING === $tokens[ $next ][0] ) {
					$pending   = 'class';
					$name      = $tokens[ $next ][1];
					$outline[] = self::item( $kinds[ $id ], $name, $tokens[ $next ][2] );
				}
				continue;
			}

			if ( T_FUNCTION === $id ) {
				$pending = 'function';
				$next    = self::next( $tokens, $i );
				if ( null !== $next && '&' === self::text( $tokens[ $next ] ) ) {
					$next = self::next( $tokens, $next );
				}
				// No name: a closure.
				if ( null === $next || ! is_array( $tokens[ $next ] ) || T_STRING !== $tokens[ $next ][0] ) {
					continue;
				}
				$innermost = end( $scopes );
				if ( false !== $innermost && 'class' === $innermost[0] ) {
					$outline[] = self::item( 'method', $innermost[1] . '::' . $tokens[ $next ][1], $tokens[ $next ][2] );
				} elseif ( ! self::inside_body( $scopes ) ) {
					$outline[] = self::item( 'function', $tokens[ $next ][1], $tokens[ $next ][2] );
				}
				continue;
			}

			$hook = self::hook( $tokens, $i );
			if ( null !== $hook ) {
				$outline[] = $hook;
			}
		}//end for

		return $outline;
	}

	/**
	 * A hook item when the token at $i calls a hook function with a literal name.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $i      Index.
	 * @return array<string, mixed>|null
	 */
	private static function hook( array $tokens, int $i ): ?array {
		$token    = $tokens[ $i ];
		$function = strtolower( ltrim( (string) $token[1], '\\' ) );
		if ( ! in_array( $function, self::HOOK_FUNCTIONS, true ) ) {
			return null;
		}
		$ids = array( T_STRING );
		if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
			$ids[] = constant( 'T_NAME_FULLY_QUALIFIED' );
		}
		if ( ! in_array( $token[0], $ids, true ) ) {
			return null;
		}

		$previous = self::previous( $tokens, $i );
		$not_call = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST );
		if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
			$not_call[] = constant( 'T_NULLSAFE_OBJECT_OPERATOR' );
		}
		if ( null !== $previous && is_array( $tokens[ $previous ] ) && in_array( $tokens[ $previous ][0], $not_call, true ) ) {
			return null;
		}

		$open = self::next( $tokens, $i );
		if ( null === $open || '(' !== $tokens[ $open ] ) {
			return null;
		}
		$argument = self::next( $tokens, $open );
		if ( null === $argument || ! is_array( $tokens[ $argument ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $argument ][0] ) {
			return null;
		}

		$item         = self::item( 'hook', stripslashes( substr( $tokens[ $argument ][1], 1, -1 ) ), $token[2] );
		$item['call'] = $function;
		return $item;
	}

	/**
	 * Outlines text line by line with one anchored pattern.
	 *
	 * @param string      $text    Text.
	 * @param string      $pattern Pattern; group 1 is the name (or the at-rule keyword when $type is null).
	 * @param string|null $type    Item type; null for CSS at-rules, typed by their keyword.
	 * @return array<int, array<string, mixed>>
	 */
	private static function by_line( string $text, string $pattern, ?string $type ): array {
		$outline = array();
		foreach ( ReadFile::lines( $text ) as $index => $line ) {
			if ( 1 !== preg_match( $pattern, $line, $match ) ) {
				continue;
			}
			if ( null === $type ) {
				$keyword   = strtolower( $match[1] );
				$outline[] = self::item( $keyword, rtrim( '@' . $keyword . ' ' . trim( $match[2] ) ), $index + 1 );
			} else {
				$outline[] = self::item( $type, $match[1], $index + 1 );
			}
		}
		return $outline;
	}

	/**
	 * An outline item.
	 *
	 * @param string $type Type.
	 * @param string $name Name.
	 * @param int    $line Line.
	 * @return array<string, mixed>
	 */
	private static function item( string $type, string $name, int $line ): array {
		$name = 1 === preg_match( '/^.{0,' . self::MAX_NAME_CHARS . '}/su', $name, $match ) ? $match[0] : '';
		return array(
			'type' => $type,
			'name' => $name,
			'line' => $line,
		);
	}

	/**
	 * Token ids of class-like declarations, mapped to their outline type.
	 *
	 * @return array<int, string>
	 */
	private static function class_kinds(): array {
		$kinds = array(
			T_CLASS     => 'class',
			T_INTERFACE => 'interface',
			T_TRAIT     => 'trait',
		);
		if ( defined( 'T_ENUM' ) ) {
			$kinds[ constant( 'T_ENUM' ) ] = 'enum';
		}
		return $kinds;
	}

	/**
	 * Whether the current position is inside a function body or an anonymous class.
	 *
	 * @param array<int, array{0: string, 1: string|null}> $scopes Open scopes.
	 * @return bool
	 */
	private static function inside_body( array $scopes ): bool {
		foreach ( $scopes as $scope ) {
			if ( 'function' === $scope[0] || 'anon' === $scope[0] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A namespace name starting at $i (PHP 7 splits it into parts; PHP 8 has one token).
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int|null          $i      Index of the first name token.
	 * @return string
	 */
	private static function qualified_name( array $tokens, ?int $i ): string {
		$parts = array( T_STRING, T_NS_SEPARATOR );
		if ( defined( 'T_NAME_QUALIFIED' ) ) {
			$parts[] = constant( 'T_NAME_QUALIFIED' );
		}
		// A relative name such as namespace\foo() is not a declaration.
		if ( null === $i || ! is_array( $tokens[ $i ] ) || T_NS_SEPARATOR === $tokens[ $i ][0] ) {
			return '';
		}
		$name = '';
		while ( isset( $tokens[ $i ] ) && is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], $parts, true ) ) {
			$name .= $tokens[ $i ][1];
			++$i;
		}
		return $name;
	}

	/**
	 * Index of the next token that is not whitespace or a comment.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $i      Index.
	 * @return int|null
	 */
	private static function next( array $tokens, int $i ): ?int {
		$count = count( $tokens );
		for ( ++$i; $i < $count; $i++ ) {
			if ( ! self::is_trivia( $tokens[ $i ] ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Index of the previous token that is not whitespace or a comment.
	 *
	 * @param array<int, mixed> $tokens Tokens.
	 * @param int               $i      Index.
	 * @return int|null
	 */
	private static function previous( array $tokens, int $i ): ?int {
		for ( --$i; $i >= 0; $i-- ) {
			if ( ! self::is_trivia( $tokens[ $i ] ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Whether a token is whitespace or a comment.
	 *
	 * @param mixed $token Token.
	 * @return bool
	 */
	private static function is_trivia( $token ): bool {
		return is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
	}

	/**
	 * A token's text.
	 *
	 * @param mixed $token Token.
	 * @return string
	 */
	private static function text( $token ): string {
		return is_array( $token ) ? (string) $token[1] : (string) $token;
	}
}
