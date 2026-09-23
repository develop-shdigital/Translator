<?php
/**
 * Translation orchestration: memory cache → database → engines → queue.
 *
 * Every string is machine-translated once per language and then served from
 * the database, so page views after the first one cost nothing and manual
 * corrections always win.
 *
 * @package SHDT
 */

namespace SHDT;

use SHDT\Engines\Engine;

defined( 'ABSPATH' ) || exit;

class Translator {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Languages.
	 *
	 * @var Languages
	 */
	private $languages;

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Per-request cache: lang => key => translation|false.
	 *
	 * @var array
	 */
	private $memory = array();

	/**
	 * Engine instances.
	 *
	 * @var Engine[]
	 */
	private $engines = array();

	/**
	 * Engine that produced each result in this request.
	 *
	 * @var array
	 */
	private $last_engine = array();

	/**
	 * Constructor.
	 *
	 * @param Settings  $settings  Settings.
	 * @param Languages $languages Languages.
	 * @param Store     $store     Store.
	 */
	public function __construct( Settings $settings, Languages $languages, Store $store ) {
		$this->settings  = $settings;
		$this->languages = $languages;
		$this->store     = $store;
	}

	/**
	 * Available engine classes.
	 *
	 * @return array id => class
	 */
	public static function engine_classes() {
		return apply_filters(
			'shdt_engines',
			array(
				'google'         => Engines\Google::class,
				'anthropic'      => Engines\Anthropic::class,
				'deepl'          => Engines\Deepl::class,
				'openai'         => Engines\OpenAI::class,
				'libretranslate' => Engines\Libretranslate::class,
				'mymemory'       => Engines\Mymemory::class,
			)
		);
	}

	/**
	 * Get an engine instance.
	 *
	 * @param string $id Engine id.
	 * @return Engine|null
	 */
	public function engine( $id ) {
		if ( ! isset( $this->engines[ $id ] ) ) {
			$classes = self::engine_classes();
			if ( ! isset( $classes[ $id ] ) || ! class_exists( $classes[ $id ] ) ) {
				return null;
			}
			$this->engines[ $id ] = new $classes[ $id ]( $this->settings );
		}
		return $this->engines[ $id ];
	}

	/**
	 * Engines to try, in order.
	 *
	 * @param bool $include_paused Include paused engines.
	 * @return Engine[]
	 */
	public function chain( $include_paused = false ) {
		$ids = array( (string) $this->settings->get( 'engine', 'google' ) );
		if ( $this->settings->on( 'fallback_free' ) ) {
			$ids[] = 'google';
			$ids[] = 'mymemory';
		}
		$chain = array();
		foreach ( array_unique( $ids ) as $id ) {
			$engine = $this->engine( $id );
			if ( $engine && $engine->is_available() && ( $include_paused || ! $this->paused( $id ) ) ) {
				$chain[] = $engine;
			}
		}
		return apply_filters( 'shdt_engine_chain', $chain );
	}

	/**
	 * Pause info for an engine (circuit breaker), or false.
	 *
	 * @param string $id Engine id.
	 * @return array|false
	 */
	public function paused( $id ) {
		return get_transient( 'shdt_pause_' . $id );
	}

	/**
	 * Pause an engine.
	 *
	 * @param string $id      Engine id.
	 * @param int    $seconds Duration.
	 * @param string $message Reason.
	 */
	public function pause( $id, $seconds, $message ) {
		set_transient(
			'shdt_pause_' . $id,
			array(
				'until'   => time() + $seconds,
				'message' => $message,
			),
			$seconds
		);
	}

	/**
	 * Clear all engine pauses.
	 */
	public function resume_all() {
		foreach ( array_keys( self::engine_classes() ) as $id ) {
			delete_transient( 'shdt_pause_' . $id );
		}
	}

	/**
	 * Translate normalised keys for a page.
	 *
	 * @param string[] $keys    Keys (normalised, may contain placeholders).
	 * @param string   $lang    Target language.
	 * @param array    $context url, title, budget (seconds), engine (bool), queue (bool: remember misses).
	 * @return array key => translation (string), false (translate in pieces) or missing.
	 */
	public function translate_keys( array $keys, $lang, array $context = array() ) {
		$context = wp_parse_args(
			$context,
			array(
				'url'    => '',
				'title'  => '',
				'budget' => (int) $this->settings->get( 'time_budget', 20 ),
				'engine' => true,
				'queue'  => true,
			)
		);

		$target = $this->languages->get( $lang );
		if ( ! $target || $this->languages->is_default( $lang ) ) {
			return array();
		}

		$out  = array();
		$need = array();
		foreach ( $keys as $key ) {
			if ( isset( $this->memory[ $lang ] ) && array_key_exists( $key, $this->memory[ $lang ] ) ) {
				$out[ $key ] = $this->memory[ $lang ][ $key ];
			} else {
				$need[ Store::hash( $key ) ] = $key;
			}
		}

		$missing = array();
		if ( $need ) {
			$rows = $this->store->get_many( $lang, array_keys( $need ) );
			foreach ( $need as $hash => $key ) {
				if ( isset( $rows[ $hash ] ) && $rows[ $hash ]['status'] > Store::PENDING ) {
					$value                         = (string) $rows[ $hash ]['translated'];
					$value                         = '' === $value ? false : $value;
					$out[ $key ]                   = $value;
					$this->memory[ $lang ][ $key ] = $value;
				} else {
					$missing[ $key ] = true;
				}
			}
		}

		if ( $missing && $context['engine'] && $context['budget'] > 0 && $this->settings->on( 'auto_translate' ) ) {
			$source   = $this->languages->get( $this->languages->default_code() );
			$deadline = microtime( true ) + (float) $context['budget'];
			$results  = $this->machine_translate( array_keys( $missing ), $source, $target, $context, $deadline );
			$this->save( $lang, $results, $context['url'] );
			foreach ( $results as $key => $value ) {
				$out[ $key ]                   = $value;
				$this->memory[ $lang ][ $key ] = $value;
				unset( $missing[ $key ] );
			}
		}

		if ( $missing && $context['queue'] ) {
			$this->store->add_pending( $lang, array_keys( $missing ), $context['url'] );
			if ( $this->settings->on( 'auto_translate' ) ) {
				shdt()->queue()->schedule();
			}
		}

		return $out;
	}

	/**
	 * Store machine results (false means "translate this sentence in pieces").
	 *
	 * @param string $lang    Language.
	 * @param array  $results key => string|false.
	 * @param string $url     Page URL.
	 * @param string $engine  Engine label override.
	 */
	public function save( $lang, array $results, $url = '', $engine = '' ) {
		$items = array();
		foreach ( $results as $key => $value ) {
			$items[] = array(
				'original'   => (string) $key,
				'translated' => false === $value ? '' : (string) $value,
				'engine'     => '' !== $engine ? $engine : ( isset( $this->last_engine[ $key ] ) ? $this->last_engine[ $key ] : '' ),
				'url'        => $url,
			);
		}
		$this->store->save_many( $lang, $items );
	}

	/**
	 * Run keys through the engine chain.
	 *
	 * @param string[] $keys     Keys.
	 * @param array    $source   Source language.
	 * @param array    $target   Target language.
	 * @param array    $context  Context.
	 * @param float    $deadline microtime deadline.
	 * @return array key => string|false
	 */
	public function machine_translate( array $keys, array $source, array $target, array $context, $deadline ) {
		$results = array();
		$todo    = array_fill_keys( $keys, true );
		$terms   = $this->settings->lines( 'glossary' );

		foreach ( $this->chain() as $engine ) {
			if ( ! $todo || microtime( true ) >= $deadline ) {
				break;
			}

			$maps     = array();
			$prepared = array();
			foreach ( array_keys( $todo ) as $key ) {
				$key = (string) $key;
				if ( method_exists( $engine, 'protects_terms' ) && $engine->protects_terms() ) {
					list( $prepared[ $key ], $maps[ $key ] ) = Text::protect_terms( $key, $terms );
				} else {
					$prepared[ $key ] = $key;
					$maps[ $key ]     = array();
				}
			}

			$batches = $this->batch( $prepared, $engine->max_batch(), $engine->max_chars() );
			if ( ! $batches ) {
				continue;
			}
			$payload = array();
			foreach ( $batches as $index => $batch ) {
				$payload[ $index ] = array_values( $batch );
			}

			$answers = $engine->translate_batches( $payload, $source, $target, $context, $deadline );

			foreach ( $batches as $index => $batch ) {
				if ( empty( $answers[ $index ] ) ) {
					continue;
				}
				$batch_keys = array_keys( $batch );
				foreach ( $answers[ $index ] as $i => $translation ) {
					if ( ! isset( $batch_keys[ $i ] ) ) {
						continue;
					}
					$key   = (string) $batch_keys[ $i ];
					$value = $this->finish( $key, (string) $translation, $maps[ $key ], $target );
					if ( null === $value ) {
						continue;
					}
					$results[ $key ]           = $value;
					$this->last_engine[ $key ] = $engine->id();
					unset( $todo[ $key ] );
				}
			}

			$error = $engine->error();
			if ( $error && $error->pause > 0 ) {
				$this->pause( $engine->id(), $error->pause, $error->getMessage() );
			}
		}

		return $results;
	}

	/**
	 * Validate and clean one engine answer.
	 *
	 * @param string $key         Original key.
	 * @param string $translation Engine output (protected terms still as placeholders).
	 * @param array  $map         Protected terms.
	 * @param array  $target      Target language.
	 * @return string|false|null Translation, false (placeholders broken) or null (unusable).
	 */
	private function finish( $key, $translation, array $map, array $target ) {
		$translation = Text::canonical_placeholders( $translation );

		foreach ( array_keys( $map ) as $id ) {
			if ( false === strpos( $translation, '<x' . $id . '/>' ) ) {
				return null; // A protected term got lost: let another engine / the queue retry.
			}
		}
		$translation = Text::normalize( Text::restore_terms( $translation, $map ) );
		if ( '' === $translation ) {
			return null;
		}

		if ( Text::has_placeholders( $key ) ) {
			if ( ! Text::placeholders_match( $key, $translation ) ) {
				return false;
			}
		} elseif ( Text::has_placeholders( $translation ) ) {
			$translation = Text::normalize( preg_replace( Text::PLACEHOLDER, '', $translation ) );
		}

		return Text::postprocess( $translation, $target['locale'] );
	}

	/**
	 * Group strings into engine sized batches.
	 *
	 * @param array $prepared  key => text.
	 * @param int   $max_batch Max strings.
	 * @param int   $max_chars Max characters.
	 * @return array[] Batches of key => text.
	 */
	private function batch( array $prepared, $max_batch, $max_chars ) {
		$batches = array();
		$current = array();
		$chars   = 0;
		foreach ( $prepared as $key => $text ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
			if ( $length > $max_chars && $max_chars < 1000 ) {
				continue; // Engine cannot take such long texts at all.
			}
			if ( $current && ( count( $current ) >= $max_batch || $chars + $length > $max_chars ) ) {
				$batches[] = $current;
				$current   = array();
				$chars     = 0;
			}
			$current[ $key ] = $text;
			$chars          += $length;
		}
		if ( $current ) {
			$batches[] = $current;
		}
		return $batches;
	}

	/**
	 * Translate queued strings (background).
	 *
	 * @param array $rows     Rows with id, lang, original.
	 * @param float $deadline microtime deadline.
	 * @return array [ translated count, failed count ]
	 */
	public function translate_rows( array $rows, $deadline ) {
		$by_lang = array();
		foreach ( $rows as $row ) {
			$by_lang[ $row['lang'] ][ $row['original'] ] = (int) $row['id'];
		}

		$done   = 0;
		$failed = array();
		$source = $this->languages->get( $this->languages->default_code() );
		foreach ( $by_lang as $lang => $originals ) {
			$target = $this->languages->get( $lang );
			if ( ! $target || $this->languages->is_default( $lang ) ) {
				$failed = array_merge( $failed, array_values( $originals ) );
				continue;
			}
			$results = microtime( true ) < $deadline ? $this->machine_translate( array_map( 'strval', array_keys( $originals ) ), $source, $target, array(), $deadline ) : array();
			$this->save( $lang, $results );
			$done += count( $results );
			foreach ( $originals as $original => $id ) {
				if ( ! array_key_exists( (string) $original, $results ) ) {
					$failed[] = $id;
				}
			}
		}
		$this->store->bump_attempts( $failed );
		return array( $done, count( $failed ) );
	}

	/**
	 * Translate a visitor's search term back into the site language.
	 *
	 * @param string $text Search term.
	 * @param string $lang Language it was typed in.
	 * @return string
	 */
	public function reverse( $text, $lang ) {
		$text = Text::normalize( $text );
		if ( '' === $text || $this->languages->is_default( $lang ) ) {
			return $text;
		}
		$original = $this->store->original_for( $lang, $text );
		if ( null !== $original && ! Text::has_placeholders( $original ) ) {
			return $original;
		}
		$cache_key = 'shdt_rev_' . md5( $lang . '|' . $text );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$source  = $this->languages->get( $lang );
		$target  = $this->languages->get( $this->languages->default_code() );
		$results = $source && $target ? $this->machine_translate( array( $text ), $source, $target, array(), microtime( true ) + 5 ) : array();
		$result  = isset( $results[ $text ] ) && is_string( $results[ $text ] ) ? $results[ $text ] : $text;
		set_transient( $cache_key, $result, WEEK_IN_SECONDS );
		return $result;
	}

	/**
	 * Try an engine with a sample sentence.
	 *
	 * @param string $id Engine id.
	 * @return array [ ok, message ]
	 */
	public function test( $id ) {
		$engine = $this->engine( $id );
		if ( ! $engine ) {
			return array( false, __( 'Unknown engine.', 'shd-translator' ) );
		}
		if ( ! $engine->is_available() ) {
			return array( false, __( 'This engine is not configured yet (API key or URL missing).', 'shd-translator' ) );
		}
		$targets = $this->languages->targets();
		$target  = $targets ? reset( $targets ) : Languages::make_entry( 'de' );
		$source  = $this->languages->get( $this->languages->default_code() );
		if ( ! isset( $target['google'] ) ) {
			$catalog = Languages::catalog();
			$target  = array_merge( $catalog[ $target['code'] ], $target );
		}
		$sample  = 'Welcome! <x1>Discover</x1> our services and get in touch today.';
		$answers = $engine->translate_batches( array( array( $sample ) ), $source, $target, array(), microtime( true ) + 30 );
		if ( ! empty( $answers[0][0] ) ) {
			delete_transient( 'shdt_pause_' . $id );
			return array( true, $answers[0][0] );
		}
		$error = $engine->error();
		$last  = get_option( 'shdt_last_error' );
		if ( $error ) {
			return array( false, $error->getMessage() );
		}
		return array( false, is_array( $last ) && $last['engine'] === $id ? $last['message'] : __( 'No answer from the engine.', 'shd-translator' ) );
	}
}
