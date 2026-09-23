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
	 * Decode HTML entities.
	 *
	 * @param string $html HTML text.
	 * @return string
	 */
	public static function decode( $html ) {
		return html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
