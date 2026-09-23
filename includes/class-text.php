<?php
/**
 * String helpers: normalisation, placeholders, glossary protection.
 *
 * Inline markup inside a sentence is sent to engines as numbered placeholder
 * tags so the sentence keeps its context:
 *   Click <a href="/x">here</a> now  =>  Click <x1>here</x1> now
 * Void elements (e.g. <br>) and protected glossary terms become <xN/>.
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Text {

	/**
	 * Tolerant placeholder pattern (engines sometimes add spaces or change case).
	 */
	const PLACEHOLDER_LOOSE = '~<\s*(/?)\s*[xX]\s*(\d+)\s*(/?)\s*>~';

	/**
	 * Canonical placeholder pattern.
	 */
	const PLACEHOLDER = '~<(/?)x(\d+)(/?)>~';

	/**
	 * Collapse HTML whitespace (keeps non-breaking spaces) and trim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = preg_replace( '/[\x09\x0A\x0C\x0D\x20]+/', ' ', (string) $text );
		return trim( $text, " \t\n\r\f" );
	}

	/**
	 * Split text into [ leading whitespace, core, trailing whitespace ].
	 *
	 * @param string $text Decoded text.
	 * @return array
	 */
	public static function split_whitespace( $text ) {
		if ( ! preg_match( '/^([\s\x{00A0}]*)(.*?)([\s\x{00A0}]*)$/su', $text, $m ) ) {
			return array( '', $text, '' );
		}
		return array( $m[1], $m[2], $m[3] );
	}

	/**
	 * Decode HTML entities the way browsers do.
	 *
	 * Besides html_entity_decode() this handles what browsers also accept:
	 * numeric references without ";", the Windows-1252 range &#128;–&#159;
	 * (e.g. &#146; is ’) and the legacy named references without ";" (&copy 2024).
	 *
	 * @param string $html      HTML text.
	 * @param bool   $attribute Decoding an attribute value (stricter legacy rule).
	 * @return string
	 */
	public static function decode( $html, $attribute = false ) {
		$html = (string) $html;
		if ( false === strpos( $html, '&' ) ) {
			return $html;
		}
		// One pass, so "&amp;copy" stays "&copy" (no double decoding).
		return preg_replace_callback(
			'/&(?:#(?:([0-9]{1,7})|[xX]([0-9a-fA-F]{1,6}))(;?)|([A-Za-z][A-Za-z0-9]{0,31})(;?))(?=(.?))/s',
			function ( $m ) use ( $attribute ) {
				if ( '' !== $m[1] || ( isset( $m[2] ) && '' !== $m[2] ) ) {
					$code = '' !== $m[1] ? (int) $m[1] : hexdec( $m[2] );
					$map  = self::cp1252();
					if ( isset( $map[ $code ] ) ) {
						$code = $map[ $code ];
					}
					if ( 0 === $code || $code > 0x10FFFF || ( $code >= 0xD800 && $code <= 0xDFFF ) ) {
						$code = 0xFFFD;
					}
					return html_entity_decode( '&#' . $code . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
				$name = $m[4];
				$semi = $m[5];
				if ( ';' === $semi ) {
					$decoded = html_entity_decode( '&' . $name . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					if ( '&' . $name . ';' !== $decoded ) {
						return $decoded;
					}
				}
				// Legacy references without ";" (longest match), e.g. "&copy 2024", "&notit".
				if ( ! preg_match( '/^(' . self::legacy_entities() . ')/', $name, $legacy ) ) {
					return $m[0];
				}
				$rest = substr( $name, strlen( $legacy[1] ) );
				$next = '' !== $rest ? $rest[0] : ( '' !== $semi ? ';' : $m[6] );
				if ( $attribute && preg_match( '/^[A-Za-z0-9=]$/', $next ) ) {
					return $m[0]; // In attributes "&copy=1" stays as it is (query strings).
				}
				return html_entity_decode( '&' . $legacy[1] . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . $rest . $semi;
			},
			$html
		);
	}

	/**
	 * Windows-1252 code points that browsers use for &#128;–&#159;.
	 *
	 * @return array
	 */
	private static function cp1252() {
		return array(
			128 => 0x20AC,
			130 => 0x201A,
			131 => 0x0192,
			132 => 0x201E,
			133 => 0x2026,
			134 => 0x2020,
			135 => 0x2021,
			136 => 0x02C6,
			137 => 0x2030,
			138 => 0x0160,
			139 => 0x2039,
			140 => 0x0152,
			142 => 0x017D,
			145 => 0x2018,
			146 => 0x2019,
			147 => 0x201C,
			148 => 0x201D,
			149 => 0x2022,
			150 => 0x2013,
			151 => 0x2014,
			152 => 0x02DC,
			153 => 0x2122,
			154 => 0x0161,
			155 => 0x203A,
			156 => 0x0153,
			158 => 0x017E,
			159 => 0x0178,
		);
	}

	/**
	 * Regex alternation of the named references browsers accept without ";"
	 * (longest first, so "&notin" decodes as "¬in" like in browsers).
	 *
	 * @return string
	 */
	private static function legacy_entities() {
		static $alternation = null;
		if ( null === $alternation ) {
			$names = array( 'AElig', 'AMP', 'Aacute', 'Acirc', 'Agrave', 'Aring', 'Atilde', 'Auml', 'COPY', 'Ccedil', 'ETH', 'Eacute', 'Ecirc', 'Egrave', 'Euml', 'GT', 'Iacute', 'Icirc', 'Igrave', 'Iuml', 'LT', 'Ntilde', 'Oacute', 'Ocirc', 'Ograve', 'Oslash', 'Otilde', 'Ouml', 'QUOT', 'REG', 'THORN', 'Uacute', 'Ucirc', 'Ugrave', 'Uuml', 'Yacute', 'aacute', 'acirc', 'acute', 'aelig', 'agrave', 'amp', 'aring', 'atilde', 'auml', 'brvbar', 'ccedil', 'cedil', 'cent', 'copy', 'curren', 'deg', 'divide', 'eacute', 'ecirc', 'egrave', 'eth', 'euml', 'frac12', 'frac14', 'frac34', 'gt', 'iacute', 'icirc', 'iexcl', 'igrave', 'iquest', 'iuml', 'laquo', 'lt', 'macr', 'micro', 'middot', 'nbsp', 'not', 'ntilde', 'oacute', 'ocirc', 'ograve', 'ordf', 'ordm', 'oslash', 'otilde', 'ouml', 'para', 'plusmn', 'pound', 'quot', 'raquo', 'reg', 'sect', 'shy', 'sup1', 'sup2', 'sup3', 'szlig', 'thorn', 'times', 'uacute', 'ucirc', 'ugrave', 'uml', 'uuml', 'yacute', 'yen', 'yuml' );
			usort(
				$names,
				function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			$alternation = implode( '|', $names );
		}
		return $alternation;
	}

	/**
	 * Escape plain text for an HTML text node.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function escape_text( $text ) {
		return htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Escape plain text for an attribute value.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function escape_attr( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Whether a normalised string is worth translating.
	 *
	 * @param string $text Normalised text (may contain placeholders).
	 * @return bool
	 */
	public static function is_translatable( $text ) {
		if ( '' === $text || strlen( $text ) > 20000 ) {
			return false;
		}
		$plain = trim( preg_replace( self::PLACEHOLDER, ' ', $text ) );
		// Needs at least one letter.
		if ( ! preg_match( '/\p{L}/u', $plain ) ) {
			return false;
		}
		// URLs, e-mail addresses, file names.
		if ( preg_match( '~^(?:https?://|www\.)\S+$~i', $plain ) || preg_match( '/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i', $plain ) ) {
			return false;
		}
		if ( preg_match( '/^[\w\-]+\.(?:jpe?g|png|gif|webp|svg|pdf|zip|docx?|xlsx?|mp4|mp3)$/i', $plain ) ) {
			return false;
		}
		// Template tokens / shortcodes that leaked into the output.
		if ( preg_match( '/^(?:\{\{.*\}\}|\[[a-z_\-]+[^\]]*\]|%[sd\d$]+)$/i', $plain ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Canonicalise placeholder spelling in engine output.
	 *
	 * @param string $text Engine output.
	 * @return string
	 */
	public static function canonical_placeholders( $text ) {
		return preg_replace( self::PLACEHOLDER_LOOSE, '<$1x$2$3>', $text );
	}

	/**
	 * Whether the translation contains exactly the placeholders of the source,
	 * each once and correctly nested.
	 *
	 * @param string $source     Source with placeholders.
	 * @param string $translated Canonicalised translation.
	 * @return bool
	 */
	public static function placeholders_match( $source, $translated ) {
		preg_match_all( self::PLACEHOLDER, $source, $a, PREG_SET_ORDER );
		preg_match_all( self::PLACEHOLDER, $translated, $b, PREG_SET_ORDER );
		if ( count( $a ) !== count( $b ) ) {
			return false;
		}
		if ( ! $a ) {
			return true;
		}
		$sig = function ( $matches ) {
			$list = array();
			foreach ( $matches as $m ) {
				$list[] = $m[1] . $m[2] . $m[3];
			}
			sort( $list );
			return implode( '|', $list );
		};
		if ( $sig( $a ) !== $sig( $b ) ) {
			return false;
		}
		// Nesting check.
		$stack = array();
		foreach ( $b as $m ) {
			if ( '/' === $m[3] ) {
				continue;
			}
			if ( '/' === $m[1] ) {
				if ( ! $stack || array_pop( $stack ) !== $m[2] ) {
					return false;
				}
			} else {
				$stack[] = $m[2];
			}
		}
		return ! $stack;
	}

	/**
	 * Whether the string contains placeholders.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_placeholders( $text ) {
		return (bool) preg_match( self::PLACEHOLDER, $text );
	}

	/**
	 * Replace glossary terms (never translate) with void placeholders.
	 *
	 * @param string   $text  Text.
	 * @param string[] $terms Terms.
	 * @return array [ protected text, map id => term ]
	 */
	public static function protect_terms( $text, array $terms ) {
		$map = array();
		if ( ! $terms ) {
			return array( $text, $map );
		}
		$id = 100;
		// Longest terms first so "SH Digital AG" wins over "SH Digital".
		usort(
			$terms,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $terms as $term ) {
			if ( '' === $term || false === stripos( $text, $term ) ) {
				continue;
			}
			$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/u';
			$text    = preg_replace_callback(
				$pattern,
				function ( $m ) use ( &$map, &$id ) {
					$map[ $id ] = $m[0];
					return '<x' . ( $id++ ) . '/>';
				},
				$text
			);
		}
		return array( $text, $map );
	}

	/**
	 * Restore protected glossary terms.
	 *
	 * @param string $text Text.
	 * @param array  $map  id => term.
	 * @return string
	 */
	public static function restore_terms( $text, array $map ) {
		foreach ( $map as $id => $term ) {
			$text = str_replace( '<x' . $id . '/>', $term, $text );
		}
		return $text;
	}

	/**
	 * Language specific clean-up of machine output.
	 *
	 * @param string $text   Translation.
	 * @param string $locale Target locale.
	 * @return string
	 */
	public static function postprocess( $text, $locale ) {
		// Swiss Standard German never uses the sharp s.
		if ( 'de_CH' === $locale || 'de_CH_informal' === $locale || 'de_LI' === $locale ) {
			if ( shdt()->settings()->on( 'swiss_ss' ) ) {
				$text = str_replace( array( 'ß', 'ẞ' ), array( 'ss', 'SS' ), $text );
			}
		}
		/**
		 * Filter a machine translation before it is stored.
		 *
		 * @param string $text   Translation.
		 * @param string $locale Target locale.
		 */
		return apply_filters( 'shdt_postprocess_translation', $text, $locale );
	}
}
