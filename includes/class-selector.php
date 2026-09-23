<?php
/**
 * Minimal CSS selector matcher for exclusion rules.
 *
 * Supports compound selectors on a single element:
 *   tag  .class  #id  [attr]  [attr=value]  [attr*=value]  [attr^=value]
 *   e.g.  div.site-footer  #copyright  [data-no-translate]  a[href^="tel:"]
 * An element matching a rule is excluded together with all of its descendants,
 * so descendant combinators are not needed in practice.
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
	 * Constructor.
	 *
	 * @param string[] $selectors Selector strings.
	 */
	public function __construct( array $selectors ) {
		foreach ( $selectors as $selector ) {
			$rule = self::parse( trim( $selector ) );
			if ( $rule ) {
				$this->rules[] = $rule;
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
	 * Parse one compound selector. For "a b" only the last compound is used.
	 *
	 * @param string $selector Selector.
	 * @return array|null
	 */
	public static function parse( $selector ) {
		if ( '' === $selector ) {
			return null;
		}
		$parts    = preg_split( '/\s*[\s>+~]\s*/', $selector );
		$selector = end( $parts );

		$rule = array(
			'tag'     => '',
			'id'      => '',
			'classes' => array(),
			'attrs'   => array(),
		);

		if ( preg_match( '/^([a-zA-Z][a-zA-Z0-9\-]*|\*)/', $selector, $m ) ) {
			$rule['tag'] = '*' === $m[1] ? '' : strtolower( $m[1] );
		}
		if ( preg_match_all( '/#([\w\-]+)/', $selector, $m ) ) {
			$rule['id'] = end( $m[1] );
		}
		if ( preg_match_all( '/\.([\w\-]+)/', $selector, $m ) ) {
			$rule['classes'] = $m[1];
		}
		if ( preg_match_all( '/\[\s*([\w\-:]+)\s*(?:([*^$~|]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]*)))?\s*\]/', $selector, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $attr ) {
				$value = null;
				if ( isset( $attr[2] ) && '' !== $attr[2] ) {
					$value = isset( $attr[5] ) && '' !== $attr[5] ? $attr[5] : ( isset( $attr[4] ) && '' !== $attr[4] ? $attr[4] : ( isset( $attr[3] ) ? $attr[3] : '' ) );
				}
				$rule['attrs'][] = array( strtolower( $attr[1] ), isset( $attr[2] ) ? $attr[2] : '', $value );
			}
		}

		if ( '' === $rule['tag'] && '' === $rule['id'] && ! $rule['classes'] && ! $rule['attrs'] ) {
			return null;
		}
		return $rule;
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
					$ok = in_array( $value, preg_split( '/\s+/', $actual ), true );
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
