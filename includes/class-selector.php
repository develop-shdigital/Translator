<?php
/**
 * Minimal CSS selector matcher for exclusion rules.
 *
 * Supports compound selectors on a single element:
 *   tag  .class  #id  [attr]  [attr=value]  [attr*=value]  [attr^=value]  [attr$=value]  [attr~=value]  [attr|=value]
 *   e.g.  div.site-footer  #copyright  [data-no-translate]  a[href^="tel:+41"]  a[href$=".pdf"]
 * An element matching a rule is excluded together with all of its descendants,
 * so descendant combinators are not needed: use ".site-footer" instead of
 * ".site-footer p". Selectors with combinators (space, >, +, ~) or
 * pseudo-classes (:not(), :first-child …) are rejected instead of being
 * silently widened; see Selector::invalid().
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Selector {

	/**
	 * Parsed rules.
	 *
	 * @var array
	 */
	private $rules = array();

	/**
	 * Selectors that could not be used.
	 *
	 * @var string[]
	 */
	private $invalid = array();

	/**
	 * Constructor.
	 *
	 * @param string[] $selectors Selector strings (each may be a comma separated list).
	 */
	public function __construct( array $selectors ) {
		foreach ( $selectors as $line ) {
			foreach ( self::split_list( (string) $line ) as $selector ) {
				$rule = self::parse( $selector );
				if ( $rule ) {
					$this->rules[] = $rule;
				} else {
					$this->invalid[] = $selector;
				}
			}
		}
	}

	/**
	 * Whether there are rules.
	 *
	 * @return bool
	 */
	public function is_empty() {
		return ! $this->rules;
	}

	/**
	 * Selectors that were ignored (unsupported syntax).
	 *
	 * @return string[]
	 */
	public function invalid() {
		return $this->invalid;
	}

	/**
	 * Selectors that were accepted, as written.
	 *
	 * @return string[]
	 */
	public function valid() {
		$out = array();
		foreach ( $this->rules as $rule ) {
			$out[] = $rule['source'];
		}
		return $out;
	}

	/**
	 * Split "a, b[title='x,y']" on commas outside brackets and quotes.
	 *
	 * @param string $list Selector list.
	 * @return string[]
	 */
	public static function split_list( $list ) {
		$out     = array();
		$current = '';
		$depth   = 0;
		$quote   = '';
		$length  = strlen( $list );
		for ( $i = 0; $i < $length; $i++ ) {
			$c = $list[ $i ];
			if ( '' !== $quote ) {
				$current .= $c;
				if ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '[' === $c ) {
				$depth++;
			} elseif ( ']' === $c ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ',' === $c && 0 === $depth ) {
				$out[]   = trim( $current );
				$current = '';
				continue;
			}
			$current .= $c;
		}
		$out[] = trim( $current );
		return array_values( array_filter( $out, 'strlen' ) );
	}

	/**
	 * Parse one compound selector.
	 *
	 * @param string $selector Selector.
	 * @return array|null Null when empty or unsupported.
	 */
	public static function parse( $selector ) {
		$selector = trim( (string) $selector );
		if ( '' === $selector ) {
			return null;
		}

		$rule   = array(
			'source'  => $selector,
			'tag'     => '',
			'id'      => '',
			'classes' => array(),
			'attrs'   => array(),
		);
		$simple = '';
		$length = strlen( $selector );

		for ( $i = 0; $i < $length; $i++ ) {
			$c = $selector[ $i ];
			if ( '[' === $c ) {
				// Attribute block: find the matching "]" outside quotes.
				$quote = '';
				$end   = -1;
				for ( $j = $i + 1; $j < $length; $j++ ) {
					$d = $selector[ $j ];
					if ( '' !== $quote ) {
						if ( $d === $quote ) {
							$quote = '';
						}
					} elseif ( '"' === $d || "'" === $d ) {
						$quote = $d;
					} elseif ( ']' === $d ) {
						$end = $j;
						break;
					}
				}
				if ( $end < 0 ) {
					return null;
				}
				$attr = self::parse_attribute( substr( $selector, $i + 1, $end - $i - 1 ) );
				if ( ! $attr ) {
					return null;
				}
				$rule['attrs'][] = $attr;
				$i               = $end;
				$simple         .= "\0"; // Keeps positions: an attribute block separates tokens.
				continue;
			}
			if ( ctype_space( $c ) || '>' === $c || '+' === $c || '~' === $c || ':' === $c || ',' === $c ) {
				return null; // Combinators and pseudo-classes are not supported.
			}
			$simple .= $c;
		}

		if ( ! preg_match( '/^(?:([a-zA-Z][a-zA-Z0-9\-]*)|\*)?((?:[.#][\w\-]+|\x00)*)$/', $simple, $m ) ) {
			return null;
		}
		$rule['tag'] = isset( $m[1] ) ? strtolower( $m[1] ) : '';
		if ( preg_match_all( '/([.#])([\w\-]+)/', $m[2], $parts, PREG_SET_ORDER ) ) {
			foreach ( $parts as $part ) {
				if ( '#' === $part[1] ) {
					$rule['id'] = $part[2];
				} else {
					$rule['classes'][] = $part[2];
				}
			}
		}

		if ( '' === $rule['tag'] && '' === $rule['id'] && ! $rule['classes'] && ! $rule['attrs'] ) {
			return null;
		}
		return $rule;
	}

	/**
	 * Parse the inside of an attribute selector: name, name=value, name^="value" …
	 *
	 * @param string $inner Text between the brackets.
	 * @return array|null [ name, operator, value|null ]
	 */
	private static function parse_attribute( $inner ) {
		if ( ! preg_match( '/^\s*([\w\-:]+)\s*(?:([*^$~|]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))\s*(?:[iIsS])?)?\s*$/', $inner, $m ) ) {
			return null;
		}
		$value = null;
		if ( isset( $m[2] ) && '' !== $m[2] ) {
			if ( isset( $m[5] ) && '' !== $m[5] ) {
				$value = $m[5];
			} elseif ( isset( $m[4] ) && '' !== $m[4] ) {
				$value = $m[4];
			} else {
				$value = isset( $m[3] ) ? $m[3] : '';
			}
		}
		return array( strtolower( $m[1] ), isset( $m[2] ) ? $m[2] : '', $value );
	}

	/**
	 * Whether an element matches any rule.
	 *
	 * @param string $tag   Lower-case tag name.
	 * @param array  $attrs Attribute name => decoded value (null when valueless).
	 * @return bool
	 */
	public function matches( $tag, array $attrs ) {
		foreach ( $this->rules as $rule ) {
			if ( self::match_rule( $rule, $tag, $attrs ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match a single rule.
	 *
	 * @param array  $rule  Rule.
	 * @param string $tag   Tag.
	 * @param array  $attrs Attributes.
	 * @return bool
	 */
	private static function match_rule( array $rule, $tag, array $attrs ) {
		if ( '' !== $rule['tag'] && $rule['tag'] !== $tag ) {
			return false;
		}
		if ( '' !== $rule['id'] && ( ! isset( $attrs['id'] ) || $attrs['id'] !== $rule['id'] ) ) {
			return false;
		}
		if ( $rule['classes'] ) {
			if ( empty( $attrs['class'] ) ) {
				return false;
			}
			$classes = preg_split( '/\s+/', trim( $attrs['class'] ) );
			foreach ( $rule['classes'] as $class ) {
				if ( ! in_array( $class, $classes, true ) ) {
					return false;
				}
			}
		}
		foreach ( $rule['attrs'] as $attr ) {
			list( $name, $op, $value ) = $attr;
			if ( ! array_key_exists( $name, $attrs ) ) {
				return false;
			}
			if ( null === $value ) {
				continue;
			}
			$actual = (string) $attrs[ $name ];
			switch ( $op ) {
				case '=':
					$ok = $actual === $value;
					break;
				case '*=':
					$ok = '' !== $value && false !== strpos( $actual, $value );
					break;
				case '^=':
					$ok = '' !== $value && 0 === strpos( $actual, $value );
					break;
				case '$=':
					$ok = '' !== $value && substr( $actual, -strlen( $value ) ) === $value;
					break;
				case '~=':
					$ok = in_array( $value, preg_split( '/\s+/', trim( $actual ) ), true );
					break;
				case '|=':
					$ok = $actual === $value || 0 === strpos( $actual, $value . '-' );
					break;
				default:
					$ok = true;
			}
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}
}
