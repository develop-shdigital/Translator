<?php
/**
 * Translates a full HTML document in place.
 *
 * The document is tokenised with a small, forgiving scanner instead of being
 * re-serialised through DOMDocument: every byte that is not translated or
 * rewritten is emitted exactly as the theme / Elementor produced it. This keeps
 * inline scripts, SVG, custom elements and invalid-but-working markup intact.
 *
 * Sentences that contain inline markup (links, <strong>, <span> …) are
 * translated as one unit with numbered placeholders, so engines see the full
 * sentence. If an engine breaks the placeholders the pieces are translated
 * separately instead.
 *
 * This class has no WordPress dependencies; translation and URL localisation
 * are injected as callbacks.
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Html_Processor {

	const T_TEXT  = 1;
	const T_OPEN  = 2;
	const T_CLOSE = 3;
	const T_RAW   = 4;
	const T_OTHER = 5;

	const TAG_RE = '~<([a-zA-Z][a-zA-Z0-9:\-]*)((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>~A';

	/**
	 * Elements whose content is not HTML.
	 *
	 * @var array
	 */
	private static $raw = array(
		'script'    => 1,
		'style'     => 1,
		'textarea'  => 1,
		'title'     => 1,
		'noscript'  => 1,
		'iframe'    => 1,
		'xmp'       => 1,
		'noembed'   => 1,
		'noframes'  => 1,
		'plaintext' => 1,
	);

	/**
	 * Void elements.
	 *
	 * @var array
	 */
	private static $void = array(
		'area'   => 1,
		'base'   => 1,
		'br'     => 1,
		'col'    => 1,
		'embed'  => 1,
		'hr'     => 1,
		'img'    => 1,
		'input'  => 1,
		'link'   => 1,
		'meta'   => 1,
		'param'  => 1,
		'source' => 1,
		'track'  => 1,
		'wbr'    => 1,
	);

	/**
	 * Inline elements that may appear inside a sentence.
	 *
	 * @var array
	 */
	private static $inline = array(
		'a'      => 1,
		'abbr'   => 1,
		'b'      => 1,
		'bdi'    => 1,
		'bdo'    => 1,
		'br'     => 1,
		'cite'   => 1,
		'data'   => 1,
		'del'    => 1,
		'dfn'    => 1,
		'em'     => 1,
		'font'   => 1,
		'i'      => 1,
		'img'    => 1,
		'ins'    => 1,
		'label'  => 1,
		'mark'   => 1,
		'q'      => 1,
		's'      => 1,
		'small'  => 1,
		'span'   => 1,
		'strong' => 1,
		'sub'    => 1,
		'sup'    => 1,
		'time'   => 1,
		'u'      => 1,
		'wbr'    => 1,
	);

	/**
	 * Elements whose content is never translated.
	 *
	 * @var array
	 */
	private static $skip_tags = array(
		'code'     => 1,
		'kbd'      => 1,
		'samp'     => 1,
		'pre'      => 1,
		'svg'      => 1,
		'math'     => 1,
		'script'   => 1,
		'style'    => 1,
		'noscript' => 1,
		'textarea' => 1,
		'iframe'   => 1,
		'object'   => 1,
	);

	/**
	 * Attributes translated on any element.
	 *
	 * @var string[]
	 */
	private static $text_attrs = array( 'title', 'alt', 'placeholder', 'aria-label', 'aria-description', 'data-elementor-lightbox-title', 'data-elementor-lightbox-description', 'data-tooltip' );

	/**
	 * Meta tags whose content is translated.
	 *
	 * @var array
	 */
	private static $meta_text = array(
		'description'         => 1,
		'og:title'            => 1,
		'og:description'      => 1,
		'og:image:alt'        => 1,
		'twitter:title'       => 1,
		'twitter:description' => 1,
		'twitter:image:alt'   => 1,
	);

	/**
	 * Options.
	 *
	 * @var array
	 */
	private $opt;

	/**
	 * Tokens.
	 *
	 * @var array
	 */
	private $tokens = array();

	/**
	 * Translatable segments.
	 *
	 * @var array
	 */
	private $segments = array();

	/**
	 * Translations used on the page (for the visual editor).
	 *
	 * @var array
	 */
	private $used = array();

	/**
	 * Number of strings without translation.
	 *
	 * @var int
	 */
	private $missing = 0;

	/**
	 * Constructor.
	 *
	 * @param array $options {
	 *     @type callable      $translate      function( string[] $keys ): array key => string|false|null.
	 *     @type callable|null $localize_url   function( string $url ): ?string.
	 *     @type string        $html_lang      Value for <html lang>.
	 *     @type bool          $rtl            Target is right-to-left.
	 *     @type string        $og_locale      Value for og:locale.
	 *     @type Selector|null $selector       Extra exclusion rules.
	 *     @type bool          $translate_meta Translate <title> and SEO meta tags.
	 *     @type bool          $translate_attributes Translate alt/title/placeholder….
	 *     @type string[]      $json_keys      Keys translated inside Elementor data-settings JSON.
	 * }
	 */
	public function __construct( array $options ) {
		$this->opt = array_merge(
			array(
				'translate'            => null,
				'localize_url'         => null,
				'html_lang'            => '',
				'rtl'                  => false,
				'og_locale'            => '',
				'selector'             => null,
				'translate_meta'       => true,
				'translate_attributes' => true,
				'json_keys'            => array( 'rotating_text' ),
			),
			$options
		);
	}

	/**
	 * Translate a document.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function process( $html ) {
		$this->tokens   = $this->tokenize( $html );
		$this->segments = array();
		$this->used     = array();
		$this->missing  = 0;

		$this->walk();

		$keys         = $this->collect_keys( $this->segments );
		$translations = $keys ? $this->translate( $keys ) : array();

		// Sentences whose markup could not be preserved are translated piece by piece.
		$fallback = array();
		foreach ( $this->segments as $index => $segment ) {
			if ( 'run' !== $segment['kind'] || false !== $this->lookup( $translations, $segment['key'] ) ) {
				continue;
			}
			$this->segments[ $index ]['skip'] = true;
			foreach ( $segment['texts'] as $token_index ) {
				$piece = $this->text_segment( $token_index );
				if ( $piece ) {
					$fallback[] = $piece;
				}
			}
		}
		if ( $fallback ) {
			$more = array_values( array_diff( $this->collect_keys( $fallback ), array_keys( $translations ) ) );
			if ( $more ) {
				$translations += $this->translate( $more );
			}
			$this->segments = array_merge( $this->segments, $fallback );
		}

		$this->apply( $translations );
		return $this->render();
	}

	/**
	 * Translations used on the page, key => translation|null.
	 *
	 * @return array
	 */
	public function used() {
		return $this->used;
	}

	/**
	 * Number of strings that had no translation yet.
	 *
	 * @return int
	 */
	public function missing() {
		return $this->missing;
	}

	/**
	 * Call the translation callback.
	 *
	 * @param string[] $keys Keys.
	 * @return array
	 */
	private function translate( array $keys ) {
		if ( ! is_callable( $this->opt['translate'] ) ) {
			return array();
		}
		$result = call_user_func( $this->opt['translate'], $keys );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Read a translation result: string, false (split) or null (missing).
	 *
	 * @param array  $translations Results.
	 * @param string $key          Key.
	 * @return string|false|null
	 */
	private function lookup( array $translations, $key ) {
		return array_key_exists( $key, $translations ) ? $translations[ $key ] : null;
	}

	/* ------------------------------------------------------------------
	 * Tokeniser
	 * ------------------------------------------------------------------ */

	/**
	 * Split HTML into tokens.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	public function tokenize( $html ) {
		$tokens = array();
		$len    = strlen( $html );
		$pos    = 0;

		while ( $pos < $len ) {
			$lt = strpos( $html, '<', $pos );
			if ( false === $lt ) {
				$this->push_text( $tokens, substr( $html, $pos ) );
				break;
			}
			if ( $lt > $pos ) {
				$this->push_text( $tokens, substr( $html, $pos, $lt - $pos ) );
			}
			$next = $lt + 1 < $len ? $html[ $lt + 1 ] : '';

			if ( '!' === $next || '?' === $next ) {
				if ( 0 === substr_compare( $html, '<!--', $lt, 4 ) ) {
					$end = strpos( $html, '-->', $lt + 4 );
					$end = false === $end ? $len : $end + 3;
				} else {
					$end = strpos( $html, '>', $lt );
					$end = false === $end ? $len : $end + 1;
				}
				$tokens[] = array(
					't'   => self::T_OTHER,
					'raw' => substr( $html, $lt, $end - $lt ),
				);
				$pos      = $end;
				continue;
			}

			if ( '/' === $next ) {
				if ( preg_match( '~</([a-zA-Z][a-zA-Z0-9:\-]*)[^>]*>~A', $html, $m, 0, $lt ) ) {
					$tokens[] = array(
						't'    => self::T_CLOSE,
						'raw'  => $m[0],
						'name' => strtolower( $m[1] ),
					);
					$pos      = $lt + strlen( $m[0] );
					continue;
				}
			} elseif ( '' !== $next && ctype_alpha( $next ) && preg_match( self::TAG_RE, $html, $m, 0, $lt ) ) {
				$name     = strtolower( $m[1] );
				$attr_str = $m[2];
				$tokens[] = array(
					't'     => self::T_OPEN,
					'raw'   => $m[0],
					'name'  => $name,
					'attrs' => $this->parse_attrs( $attr_str, 1 + strlen( $m[1] ) ),
					'self'  => '' !== $attr_str && '/' === substr( rtrim( $attr_str ), -1 ),
				);
				$pos      = $lt + strlen( $m[0] );

				if ( isset( self::$raw[ $name ] ) ) {
					$end = $len;
					if ( preg_match( '~</' . preg_quote( $name, '~' ) . '[\s/>]~i', $html, $mm, PREG_OFFSET_CAPTURE, $pos ) ) {
						$end = $mm[0][1];
					}
					if ( $end > $pos ) {
						$tokens[] = array(
							't'    => self::T_RAW,
							'raw'  => substr( $html, $pos, $end - $pos ),
							'name' => $name,
						);
					}
					$pos = $end;
				}
				continue;
			}

			// A "<" that does not start a tag is plain text.
			$this->push_text( $tokens, '<' );
			$pos = $lt + 1;
		}

		return $tokens;
	}

	/**
	 * Append text, merging with a previous text token.
	 *
	 * @param array  $tokens Tokens.
	 * @param string $text   Text.
	 */
	private function push_text( array &$tokens, $text ) {
		$last = count( $tokens ) - 1;
		if ( $last >= 0 && self::T_TEXT === $tokens[ $last ]['t'] ) {
			$tokens[ $last ]['raw'] .= $text;
			return;
		}
		$tokens[] = array(
			't'   => self::T_TEXT,
			'raw' => $text,
		);
	}

	/**
	 * Parse attributes, remembering where each value sits in the raw tag.
	 *
	 * @param string $str  Attribute part of the tag.
	 * @param int    $base Offset of $str inside the raw tag.
	 * @return array name => [ v => decoded value|null, o => offset, l => length, q => quote ]
	 */
	private function parse_attrs( $str, $base ) {
		$attrs = array();
		if ( '' === trim( $str ) ) {
			return $attrs;
		}
		preg_match_all( '~([^\s"\'>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+)))?~', $str, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		foreach ( $matches as $m ) {
			$name = strtolower( $m[1][0] );
			if ( isset( $attrs[ $name ] ) ) {
				continue;
			}
			$attr = array(
				'v' => null,
				'o' => $base + $m[1][1] + strlen( $m[1][0] ),
				'l' => 0,
				'q' => null,
			);
			foreach ( array( 2 => '"', 3 => "'", 4 => '' ) as $group => $quote ) {
				if ( isset( $m[ $group ] ) && $m[ $group ][1] >= 0 ) {
					$attr = array(
						'v' => Text::decode( $m[ $group ][0] ),
						'o' => $base + $m[ $group ][1],
						'l' => strlen( $m[ $group ][0] ),
						'q' => $quote,
					);
					break;
				}
			}
			$attrs[ $name ] = $attr;
		}
		return $attrs;
	}

	/* ------------------------------------------------------------------
	 * Segment collection
	 * ------------------------------------------------------------------ */

	/**
	 * Walk tokens and collect translatable segments.
	 */
	private function walk() {
		$skip  = null;
		$run   = array();
		$count = count( $this->tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $this->tokens[ $i ];

			switch ( $token['t'] ) {
				case self::T_TEXT:
					if ( ! $skip ) {
						$run[] = $i;
					}
					break;

				case self::T_OPEN:
					$name = $token['name'];
					if ( $skip ) {
						if ( $name === $skip[0] && ! isset( self::$void[ $name ] ) ) {
							$skip[1]++;
						}
						$this->handle_tag( $i, false, $skip[2] );
						break;
					}
					if ( $this->is_excluded( $token ) ) {
						$this->flush( $run );
						// Links inside the admin bar and the language switcher keep their URLs.
						$rewrite = ! isset( $token['attrs']['data-shdt-switcher'] ) && ! ( isset( $token['attrs']['id']['v'] ) && 'wpadminbar' === $token['attrs']['id']['v'] );
						$this->handle_tag( $i, false, $rewrite );
						if ( ! isset( self::$void[ $name ] ) && ! isset( self::$raw[ $name ] ) ) {
							$skip = array( $name, 1, $rewrite );
						}
						break;
					}
					$this->handle_tag( $i, true );
					if ( isset( self::$inline[ $name ] ) ) {
						$run[] = $i;
					} else {
						$this->flush( $run );
					}
					break;

				case self::T_CLOSE:
					if ( $skip ) {
						if ( $token['name'] === $skip[0] && --$skip[1] <= 0 ) {
							$skip = null;
						}
						break;
					}
					if ( isset( self::$inline[ $token['name'] ] ) ) {
						$run[] = $i;
					} else {
						$this->flush( $run );
					}
					break;

				case self::T_RAW:
					$this->flush( $run );
					if ( ! $skip && 'title' === $token['name'] && $this->opt['translate_meta'] ) {
						$segment = $this->text_segment( $i );
						if ( $segment ) {
							$this->segments[] = $segment;
						}
					}
					break;

				default:
					$this->flush( $run );
			}
		}
		$this->flush( $run );
	}

	/**
	 * Whether an element (and its content) is excluded from translation.
	 *
	 * @param array $token Open tag token.
	 * @return bool
	 */
	private function is_excluded( array $token ) {
		if ( isset( self::$skip_tags[ $token['name'] ] ) ) {
			return true;
		}
		$attrs = $token['attrs'];
		if ( ! $attrs ) {
			return false;
		}
		if ( isset( $attrs['translate'] ) && 'no' === strtolower( (string) $attrs['translate']['v'] ) ) {
			return true;
		}
		if ( isset( $attrs['data-shdt-switcher'] ) || isset( $attrs['data-shdt-lang'] ) || isset( $attrs['data-no-translation'] ) || isset( $attrs['data-notranslate'] ) ) {
			return true;
		}
		if ( isset( $attrs['class']['v'] ) && preg_match( '/(?:^|\s)(?:notranslate|shdt-no-translate|skiptranslate)(?:\s|$)/', $attrs['class']['v'] ) ) {
			return true;
		}
		if ( isset( $attrs['id']['v'] ) && 'wpadminbar' === $attrs['id']['v'] ) {
			return true;
		}
		if ( $this->opt['selector'] instanceof Selector && ! $this->opt['selector']->is_empty() ) {
			$flat = array();
			foreach ( $attrs as $name => $attr ) {
				$flat[ $name ] = $attr['v'];
			}
			return $this->opt['selector']->matches( $token['name'], $flat );
		}
		return false;
	}

	/**
	 * Handle attributes of an open tag: links, lang, translatable attributes.
	 *
	 * @param int  $i         Token index.
	 * @param bool $translate Whether attribute text may be translated.
	 * @param bool $rewrite   Whether link URLs may be localised.
	 */
	private function handle_tag( $i, $translate, $rewrite = true ) {
		$token = $this->tokens[ $i ];
		$name  = $token['name'];
		$attrs = $token['attrs'];

		if ( $rewrite ) {
			$this->rewrite_link( $i );
		}

		if ( 'html' === $name ) {
			if ( '' !== $this->opt['html_lang'] ) {
				$this->set_attr( $i, 'lang', $this->opt['html_lang'] );
			}
			if ( $this->opt['rtl'] ) {
				$this->set_attr( $i, 'dir', 'rtl' );
			} elseif ( isset( $attrs['dir'] ) && 'rtl' === strtolower( (string) $attrs['dir']['v'] ) ) {
				$this->set_attr( $i, 'dir', 'ltr' );
			}
			return;
		}

		if ( 'meta' === $name ) {
			$prop = '';
			if ( isset( $attrs['property']['v'] ) ) {
				$prop = strtolower( $attrs['property']['v'] );
			} elseif ( isset( $attrs['name']['v'] ) ) {
				$prop = strtolower( $attrs['name']['v'] );
			}
			if ( 'og:locale' === $prop && '' !== $this->opt['og_locale'] && isset( $attrs['content'] ) ) {
				$this->set_attr( $i, 'content', $this->opt['og_locale'] );
			} elseif ( $translate && $this->opt['translate_meta'] && isset( self::$meta_text[ $prop ] ) ) {
				$this->attr_segment( $i, 'content' );
			}
			return;
		}

		if ( ! $translate || ! $this->opt['translate_attributes'] || ! $attrs ) {
			return;
		}

		foreach ( self::$text_attrs as $attr ) {
			if ( isset( $attrs[ $attr ] ) ) {
				$this->attr_segment( $i, $attr );
			}
		}

		if ( 'input' === $name && isset( $attrs['value'], $attrs['type']['v'] ) && in_array( strtolower( $attrs['type']['v'] ), array( 'submit', 'button', 'reset' ), true ) ) {
			$this->attr_segment( $i, 'value' );
		}

		if ( isset( $attrs['label'] ) && in_array( $name, array( 'option', 'optgroup', 'track' ), true ) ) {
			$this->attr_segment( $i, 'label' );
		}

		if ( isset( $attrs['data-settings']['v'] ) && $this->opt['json_keys'] ) {
			$this->json_segment( $i, 'data-settings' );
		}
	}

	/**
	 * Localise the URL of links and forms.
	 *
	 * @param int $i Token index.
	 */
	private function rewrite_link( $i ) {
		if ( ! is_callable( $this->opt['localize_url'] ) ) {
			return;
		}
		$token = $this->tokens[ $i ];
		$attrs = $token['attrs'];
		$attr  = '';

		switch ( $token['name'] ) {
			case 'a':
			case 'area':
				$attr = 'href';
				break;
			case 'form':
				$attr = 'action';
				break;
			case 'link':
				$rel = isset( $attrs['rel']['v'] ) ? strtolower( $attrs['rel']['v'] ) : '';
				if ( in_array( $rel, array( 'canonical', 'next', 'prev' ), true ) ) {
					$attr = 'href';
				}
				break;
			case 'meta':
				if ( isset( $attrs['property']['v'] ) && 'og:url' === strtolower( $attrs['property']['v'] ) ) {
					$attr = 'content';
				}
				break;
		}

		if ( '' === $attr || ! isset( $attrs[ $attr ]['v'] ) ) {
			return;
		}
		// Language switcher links, hreflang alternates and downloads keep their URL.
		if ( isset( $attrs['hreflang'] ) || isset( $attrs['data-shdt-lang'] ) || isset( $attrs['download'] ) || isset( $attrs['data-no-translation-url'] ) ) {
			return;
		}

		$url = $attrs[ $attr ]['v'];
		$new = call_user_func( $this->opt['localize_url'], $url );
		if ( is_string( $new ) && $new !== $url ) {
			$this->set_attr( $i, $attr, $new );
		}
	}

	/**
	 * Set (or add) an attribute value on a token.
	 *
	 * @param int    $i     Token index.
	 * @param string $name  Attribute.
	 * @param string $value Plain value.
	 */
	private function set_attr( $i, $name, $value ) {
		if ( isset( $this->tokens[ $i ]['attrs'][ $name ] ) && null !== $this->tokens[ $i ]['attrs'][ $name ]['q'] ) {
			$this->tokens[ $i ]['mods'][ $name ] = Text::escape_attr( $value );
		} else {
			$this->tokens[ $i ]['add'][ $name ] = Text::escape_attr( $value );
		}
	}

	/**
	 * Register an attribute value as a segment.
	 *
	 * @param int    $i    Token index.
	 * @param string $attr Attribute.
	 */
	private function attr_segment( $i, $attr ) {
		$value = $this->tokens[ $i ]['attrs'][ $attr ]['v'];
		if ( null === $value || '' === $value ) {
			return;
		}
		list( $lead, $core, $trail ) = Text::split_whitespace( $value );
		$key                         = Text::normalize( $core );
		if ( ! Text::is_translatable( $key ) || Text::has_placeholders( $key ) ) {
			return;
		}
		$this->segments[] = array(
			'kind'  => 'attr',
			'token' => $i,
			'attr'  => $attr,
			'key'   => $key,
			'lead'  => $lead,
			'trail' => $trail,
		);
	}

	/**
	 * Register strings inside a JSON attribute (Elementor widget settings).
	 *
	 * @param int    $i    Token index.
	 * @param string $attr Attribute.
	 */
	private function json_segment( $i, $attr ) {
		$raw = $this->tokens[ $i ]['attrs'][ $attr ]['v'];
		$hit = false;
		foreach ( $this->opt['json_keys'] as $key ) {
			if ( false !== strpos( $raw, '"' . $key . '"' ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			return;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return;
		}
		$fields = array();
		foreach ( $this->opt['json_keys'] as $key ) {
			if ( empty( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				continue;
			}
			$lines = array();
			foreach ( preg_split( '/\r\n|\r|\n/', $data[ $key ] ) as $line ) {
				$lines[] = Text::normalize( $line );
			}
			$fields[ $key ] = $lines;
		}
		if ( $fields ) {
			$this->segments[] = array(
				'kind'   => 'json',
				'token'  => $i,
				'attr'   => $attr,
				'data'   => $data,
				'fields' => $fields,
			);
		}
	}

	/**
	 * Segment for a single text (or raw title) token.
	 *
	 * @param int $i Token index.
	 * @return array|null
	 */
	private function text_segment( $i ) {
		list( $lead, $core, $trail ) = Text::split_whitespace( Text::decode( $this->tokens[ $i ]['raw'] ) );
		$key                         = Text::normalize( $core );
		if ( ! Text::is_translatable( $key ) || Text::has_placeholders( $key ) ) {
			return null;
		}
		return array(
			'kind'  => 'text',
			'token' => $i,
			'key'   => $key,
			'lead'  => $lead,
			'trail' => $trail,
		);
	}

	/**
	 * Turn a run of inline tokens into segments.
	 *
	 * @param int[] $run Token indices (reset after flushing).
	 */
	private function flush( array &$run ) {
		if ( ! $run ) {
			return;
		}
		$texts = array();
		foreach ( $run as $index ) {
			if ( self::T_TEXT === $this->tokens[ $index ]['t'] && '' !== Text::normalize( Text::decode( $this->tokens[ $index ]['raw'] ) ) ) {
				$texts[] = $index;
			}
		}

		// Sibling elements separated only by spaces or punctuation ("<a>Home</a> » <a>Blog</a>")
		// are independent labels, not one sentence: translate each on its own.
		if ( count( $texts ) > 1 ) {
			$groups = $this->top_level_groups( $run );
			if ( $groups ) {
				$run = array();
				foreach ( $groups as $group ) {
					$this->flush( $group );
				}
				return;
			}
		}

		if ( 1 === count( $texts ) ) {
			$segment = $this->text_segment( $texts[0] );
			if ( $segment ) {
				$this->segments[] = $segment;
			}
		} elseif ( $texts ) {
			$segment = $this->run_segment( $run, $texts );
			if ( $segment ) {
				$this->segments[] = $segment;
			} else {
				foreach ( $texts as $index ) {
					$segment = $this->text_segment( $index );
					if ( $segment ) {
						$this->segments[] = $segment;
					}
				}
			}
		}
		$run = array();
	}

	/**
	 * Split a run into top-level element groups when the text between them has no letters.
	 *
	 * @param int[] $run Token indices.
	 * @return array[]|null Groups, or null when the run reads as one sentence.
	 */
	private function top_level_groups( array $run ) {
		$groups  = array();
		$current = array();
		$depth   = 0;
		foreach ( $run as $index ) {
			$token = $this->tokens[ $index ];
			if ( self::T_TEXT === $token['t'] ) {
				if ( 0 === $depth ) {
					if ( preg_match( '/\p{L}/u', Text::decode( $token['raw'] ) ) ) {
						return null;
					}
					if ( $current ) {
						$groups[] = $current;
						$current  = array();
					}
					continue;
				}
				$current[] = $index;
				continue;
			}
			$current[] = $index;
			if ( self::T_OPEN === $token['t'] && ! isset( self::$void[ $token['name'] ] ) ) {
				$depth++;
			} elseif ( self::T_CLOSE === $token['t'] ) {
				$depth = max( 0, $depth - 1 );
				if ( 0 === $depth ) {
					$groups[] = $current;
					$current  = array();
				}
			} elseif ( 0 === $depth ) {
				// Void element at top level (e.g. <br>) separates groups.
				$groups[] = $current;
				$current  = array();
			}
		}
		if ( $current ) {
			$groups[] = $current;
		}
		return count( $groups ) > 1 ? $groups : null;
	}

	/**
	 * Build a placeholder segment for a sentence with inline markup.
	 *
	 * @param int[] $run   Token indices of the run.
	 * @param int[] $texts Indices of non-empty text tokens in the run.
	 * @return array|null
	 */
	private function run_segment( array $run, array $texts ) {
		// Pair open and close tags inside the run.
		$pair  = array();
		$stack = array();
		foreach ( $run as $index ) {
			$token = $this->tokens[ $index ];
			if ( self::T_OPEN === $token['t'] ) {
				if ( isset( self::$void[ $token['name'] ] ) ) {
					$pair[ $index ] = $index;
				} else {
					$stack[] = $index;
				}
			} elseif ( self::T_CLOSE === $token['t'] ) {
				for ( $k = count( $stack ) - 1; $k >= 0; $k-- ) {
					if ( $this->tokens[ $stack[ $k ] ]['name'] === $token['name'] ) {
						$pair[ $index ]       = $stack[ $k ];
						$pair[ $stack[ $k ] ] = $index;
						array_splice( $stack, $k );
						break;
					}
				}
			} elseif ( Text::has_placeholders( Text::decode( $token['raw'] ) ) ) {
				return null;
			}
		}

		$position = array_flip( $run );
		$start    = $position[ $texts[0] ];
		$end      = $position[ $texts[ count( $texts ) - 1 ] ];

		// Grow the range until every tag inside it is balanced.
		do {
			$changed = false;
			for ( $p = $start; $p <= $end; $p++ ) {
				$index = $run[ $p ];
				if ( self::T_TEXT === $this->tokens[ $index ]['t'] ) {
					continue;
				}
				if ( ! isset( $pair[ $index ] ) ) {
					return null;
				}
				$q = $position[ $pair[ $index ] ];
				if ( $q < $start ) {
					$start   = $q;
					$changed = true;
				}
				if ( $q > $end ) {
					$end     = $q;
					$changed = true;
				}
			}
		} while ( $changed );

		// Drop wrappers around the whole sentence (<strong>whole text</strong>).
		while ( $start < $end && self::T_OPEN === $this->tokens[ $run[ $start ] ]['t'] && isset( $pair[ $run[ $start ] ] ) && $pair[ $run[ $start ] ] === $run[ $end ] && $run[ $start ] !== $run[ $end ] ) {
			$start++;
			$end--;
		}

		$map = array();
		$ids = array();
		$n   = 0;
		$str = '';
		for ( $p = $start; $p <= $end; $p++ ) {
			$index = $run[ $p ];
			$token = $this->tokens[ $index ];
			if ( self::T_TEXT === $token['t'] ) {
				$str .= Text::decode( $token['raw'] );
			} elseif ( self::T_OPEN === $token['t'] ) {
				$n++;
				$ids[ $index ] = $n;
				if ( $pair[ $index ] === $index ) {
					$map[ $n ] = array( 'void' => $index );
					$str      .= '<x' . $n . '/>';
				} else {
					$map[ $n ] = array(
						'open'  => $index,
						'close' => $pair[ $index ],
					);
					$str      .= '<x' . $n . '>';
				}
			} else {
				$str .= '</x' . $ids[ $pair[ $index ] ] . '>';
			}
		}

		list( $lead, $core, $trail ) = Text::split_whitespace( $str );
		$key                         = Text::normalize( $core );
		if ( ! Text::is_translatable( $key ) ) {
			return null;
		}

		// Only one piece of text left after trimming wrappers: plain text segment.
		if ( ! Text::has_placeholders( $key ) ) {
			$inner = array();
			foreach ( $texts as $index ) {
				if ( $position[ $index ] >= $start && $position[ $index ] <= $end ) {
					$inner[] = $index;
				}
			}
			if ( 1 === count( $inner ) ) {
				return $this->text_segment( $inner[0] );
			}
		}

		$range = array();
		for ( $p = $start; $p <= $end; $p++ ) {
			$range[] = $run[ $p ];
		}

		return array(
			'kind'  => 'run',
			'key'   => $key,
			'lead'  => $lead,
			'trail' => $trail,
			'range' => $range,
			'map'   => $map,
			'texts' => $texts,
		);
	}

	/**
	 * Unique keys of segments.
	 *
	 * @param array $segments Segments.
	 * @return string[]
	 */
	private function collect_keys( array $segments ) {
		$keys = array();
		foreach ( $segments as $segment ) {
			if ( ! empty( $segment['skip'] ) ) {
				continue;
			}
			if ( 'json' === $segment['kind'] ) {
				foreach ( $segment['fields'] as $lines ) {
					foreach ( $lines as $line ) {
						if ( Text::is_translatable( $line ) ) {
							$keys[ $line ] = true;
						}
					}
				}
				continue;
			}
			$keys[ $segment['key'] ] = true;
		}
		return array_keys( $keys );
	}

	/* ------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------ */

	/**
	 * Write translations into tokens.
	 *
	 * @param array $translations key => translation.
	 */
	private function apply( array $translations ) {
		$runs = array();
		foreach ( $this->segments as $segment ) {
			if ( ! empty( $segment['skip'] ) ) {
				continue;
			}
			if ( 'run' === $segment['kind'] ) {
				$runs[] = $segment;
				continue;
			}

			if ( 'json' === $segment['kind'] ) {
				$data = $segment['data'];
				foreach ( $segment['fields'] as $field => $lines ) {
					$out = array();
					foreach ( $lines as $line ) {
						$t     = $this->lookup( $translations, $line );
						$out[] = is_string( $t ) && '' !== $t ? $t : $line;
						$this->track( $line, $t );
					}
					$data[ $field ] = implode( "\n", $out );
				}
				$json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( false !== $json ) {
					$this->tokens[ $segment['token'] ]['mods'][ $segment['attr'] ] = Text::escape_attr( $json );
				}
				continue;
			}

			$t = $this->lookup( $translations, $segment['key'] );
			$this->track( $segment['key'], $t );
			if ( ! is_string( $t ) || '' === $t ) {
				continue;
			}
			if ( 'attr' === $segment['kind'] ) {
				$this->set_attr( $segment['token'], $segment['attr'], $segment['lead'] . $t . $segment['trail'] );
			} else {
				$this->tokens[ $segment['token'] ]['out'] = Text::escape_text( $segment['lead'] . $t . $segment['trail'] );
			}
		}

		// Runs last: they embed the (already rewritten) inline tags.
		foreach ( $runs as $segment ) {
			$t = $this->lookup( $translations, $segment['key'] );
			$this->track( $segment['key'], $t );
			if ( ! is_string( $t ) || '' === $t ) {
				continue;
			}
			$parts = preg_split( Text::PLACEHOLDER, $t, -1, PREG_SPLIT_DELIM_CAPTURE );
			$html  = Text::escape_text( $segment['lead'] );
			$count = count( $parts );
			for ( $p = 0; $p < $count; $p++ ) {
				if ( 0 === $p % 4 ) {
					$html .= Text::escape_text( $parts[ $p ] );
					continue;
				}
				$close = '/' === $parts[ $p ];
				$id    = (int) $parts[ $p + 1 ];
				$p    += 2;
				if ( ! isset( $segment['map'][ $id ] ) ) {
					continue;
				}
				$entry = $segment['map'][ $id ];
				if ( isset( $entry['void'] ) ) {
					$html .= $this->render_token( $entry['void'] );
				} elseif ( $close ) {
					$html .= $this->tokens[ $entry['close'] ]['raw'];
				} else {
					$html .= $this->render_token( $entry['open'] );
				}
			}
			$html .= Text::escape_text( $segment['trail'] );

			$first = true;
			foreach ( $segment['range'] as $index ) {
				$this->tokens[ $index ]['out'] = $first ? $html : '';
				$first                         = false;
			}
		}
	}

	/**
	 * Remember a translation for the editor and count missing ones.
	 *
	 * @param string            $key Key.
	 * @param string|false|null $t   Translation.
	 */
	private function track( $key, $t ) {
		if ( ! Text::is_translatable( $key ) ) {
			return;
		}
		if ( ! array_key_exists( $key, $this->used ) && null === $t ) {
			$this->missing++;
		}
		$this->used[ $key ] = is_string( $t ) ? $t : null;
	}

	/**
	 * Render a token (applying attribute changes).
	 *
	 * @param int $i Token index.
	 * @return string
	 */
	private function render_token( $i ) {
		$token = $this->tokens[ $i ];
		if ( self::T_OPEN !== $token['t'] || ( empty( $token['mods'] ) && empty( $token['add'] ) ) ) {
			return $token['raw'];
		}

		$raw    = $token['raw'];
		$edits  = array();
		$insert = '';
		if ( ! empty( $token['mods'] ) ) {
			foreach ( $token['mods'] as $name => $escaped ) {
				$attr    = $token['attrs'][ $name ];
				$edits[] = array( $attr['o'], $attr['l'], '' === $attr['q'] ? '"' . $escaped . '"' : $escaped );
			}
		}
		if ( ! empty( $token['add'] ) ) {
			foreach ( $token['add'] as $name => $escaped ) {
				if ( isset( $token['attrs'][ $name ] ) ) {
					// Valueless attribute present: give it a value in place.
					$edits[] = array( $token['attrs'][ $name ]['o'], 0, '="' . $escaped . '"' );
				} else {
					$insert .= ' ' . $name . '="' . $escaped . '"';
				}
			}
		}
		usort(
			$edits,
			function ( $a, $b ) {
				return $b[0] - $a[0];
			}
		);
		foreach ( $edits as $edit ) {
			$raw = substr_replace( $raw, $edit[2], $edit[0], $edit[1] );
		}
		if ( '' !== $insert ) {
			$at  = '/>' === substr( $raw, -2 ) ? strlen( $raw ) - 2 : strlen( $raw ) - 1;
			$raw = substr( $raw, 0, $at ) . $insert . substr( $raw, $at );
		}
		return $raw;
	}

	/**
	 * Assemble the document.
	 *
	 * @return string
	 */
	private function render() {
		$html = '';
		foreach ( $this->tokens as $i => $token ) {
			if ( isset( $token['out'] ) ) {
				$html .= $token['out'];
			} else {
				$html .= self::T_OPEN === $token['t'] ? $this->render_token( $i ) : $token['raw'];
			}
		}
		return $html;
	}
}
