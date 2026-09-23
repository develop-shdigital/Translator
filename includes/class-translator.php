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
	 * Keys an engine actually received during the last machine_translate() call.
	 *
	 * @var array key => true
	 */
	private $attempted = array();

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
	 * Id of the engine selected in the settings.
	 *
	 * @return string
	 */
	public function primary_id() {
		return (string) $this->settings->get( 'engine', 'google' );
	}

	/**
	 * Whether two engine ids produce the same kind of translation.
	 * "browser" is the free Google engine running in the admin's browser.
	 *
	 * @param string $a Engine id.
	 * @param string $b Engine id.
	 * @return bool
	 */
	public static function same_engine( $a, $b ) {
		$a = 'browser' === $a ? 'google' : $a;
		$b = 'browser' === $b ? 'google' : $b;
		return $a === $b;
	}

	/**
	 * Engine ids whose translations count as made by $engine.
	 *
	 * @param string $engine Engine id.
	 * @return string[]
	 */
	public static function equivalent_ids( $engine ) {
		return 'google' === $engine || 'browser' === $engine ? array( 'google', 'browser' ) : array( $engine );
	}

	/**
	 * Engines to try, in order.
	 *
	 * @param bool        $include_paused Include paused engines.
	 * @param string|null $lang           Target language (skips engines paused for it).
	 * @return Engine[]
	 */
	public function chain( $include_paused = false, $lang = null ) {
		$ids = array( $this->primary_id() );
		if ( $this->settings->on( 'fallback_free' ) ) {
			$ids[] = 'google';
			$ids[] = 'mymemory';
		}
		$chain = array();
		foreach ( array_unique( $ids ) as $id ) {
			$engine = $this->engine( $id );
			if ( $engine && $engine->is_available() && ( $include_paused || ! $this->paused( $id, $lang ) ) ) {
				$chain[] = $engine;
			}
		}
		return apply_filters( 'shdt_engine_chain', $chain );
	}

	/**
	 * Transient key of a pause.
	 *
	 * @param string      $id   Engine id.
	 * @param string|null $lang Language, null for the whole engine.
	 * @return string
	 */
	private static function pause_key( $id, $lang = null ) {
		return 'shdt_pause_' . $id . ( null === $lang || '' === $lang ? '' : '_' . strtolower( preg_replace( '/[^A-Za-z0-9\-]/', '', $lang ) ) );
	}

	/**
	 * Pause info for an engine (whole engine, or for one language), or false.
	 *
	 * @param string      $id   Engine id.
	 * @param string|null $lang Also check the pause of this language.
	 * @return array|false
	 */
	public function paused( $id, $lang = null ) {
		$pause = get_transient( self::pause_key( $id ) );
		if ( ! $pause && null !== $lang ) {
			$pause = get_transient( self::pause_key( $id, $lang ) );
		}
		return $pause;
	}

	/**
	 * Pause an engine (circuit breaker).
	 *
	 * @param string      $id      Engine id.
	 * @param int         $seconds Duration.
	 * @param string      $message Reason.
	 * @param string|null $lang    Only this target language.
	 */
	public function pause( $id, $seconds, $message, $lang = null ) {
		set_transient(
			self::pause_key( $id, $lang ),
			array(
				'until'   => time() + $seconds,
				'message' => $message,
				'lang'    => $lang,
			),
			$seconds
		);
	}

	/**
	 * All current pauses, for the admin screens.
	 *
	 * @param string|null $only Only this engine.
	 * @return array[] engine, label, lang, until, message
	 */
	public function pauses( $only = null ) {
		$out   = array();
		$langs = array_merge( array( null ), array_keys( $this->languages->active() ) );
		foreach ( null === $only ? array_keys( self::engine_classes() ) : array( (string) $only ) as $id ) {
			foreach ( $langs as $lang ) {
				$pause = get_transient( self::pause_key( $id, $lang ) );
				if ( ! is_array( $pause ) ) {
					continue;
				}
				$engine = $this->engine( $id );
				$out[]  = array(
					'engine'  => $id,
					'label'   => $engine ? $engine->label() : $id,
					'lang'    => $lang,
					'until'   => isset( $pause['until'] ) ? (int) $pause['until'] : 0,
					'message' => isset( $pause['message'] ) ? (string) $pause['message'] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * Clear all engine pauses.
	 */
	public function resume_all() {
		$langs = array_merge( array( null ), array_keys( $this->languages->active() ) );
		foreach ( array_keys( self::engine_classes() ) as $id ) {
			foreach ( $langs as $lang ) {
				delete_transient( self::pause_key( $id, $lang ) );
			}
		}
	}

	/**
	 * Translate normalised keys for a page.
	 *
	 * @param string[] $keys    Keys (normalised, may contain placeholders).
	 * @param string   $lang    Target language.
	 * @param array    $context url, title, budget (seconds), deadline (microtime shared by all calls
	 *                          of one page view), engine (bool), queue (bool: remember misses),
	 *                          isolate (bool: strings come from visitors, send each one on its own).
	 * @return array key => translation (string), false (translate in pieces) or missing.
	 */
	public function translate_keys( array $keys, $lang, array $context = array() ) {
		$context = wp_parse_args(
			$context,
			array(
				'url'    => '',
				'title'  => '',
				'budget'   => (int) $this->settings->get( 'time_budget', 20 ),
				'deadline' => 0,
				'engine'   => true,
				'queue'    => true,
				'isolate'  => false,
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

		$deadline = microtime( true ) + (float) $context['budget'];
		if ( $context['deadline'] > 0 ) {
			$deadline = min( $deadline, (float) $context['deadline'] );
		}
		if ( $missing && $context['engine'] && $deadline - microtime( true ) >= 1 && $this->settings->on( 'auto_translate' ) ) {
			$source  = $this->languages->get( $this->languages->default_code() );
			$results = $this->machine_translate( array_keys( $missing ), $source, $target, $context, $deadline );
			$this->save( $lang, $results, $context['url'] );
			foreach ( $results as $key => $value ) {
				$out[ $key ]                   = $value;
				$this->memory[ $lang ][ $key ] = $value;
				unset( $missing[ $key ] );
			}
		}

		if ( $missing && $context['queue'] ) {
			$this->store->add_pending( $lang, array_keys( $missing ), $context['url'], $context['isolate'] ? Store::VISITOR : '' );
			if ( $this->settings->on( 'auto_translate' ) ) {
				shdt()->queue()->schedule();
			}
		}

		return $out;
	}

	/**
	 * Store machine results (false means "translate this sentence in pieces").
	 *
	 * Results produced by another engine than the selected one (e.g. Google while
	 * OpenAI was paused, failing or not set up yet) are shown right away but stored
	 * as OUTDATED, and the queue redoes them with the selected engine once it works.
	 *
	 * @param string $lang    Language.
	 * @param array  $results key => string|false.
	 * @param string $url     Page URL.
	 * @param string $engine  Engine id override (e.g. "browser").
	 */
	public function save( $lang, array $results, $url = '', $engine = '' ) {
		$primary  = $this->primary_id();
		$items    = array();
		$outdated = false;
		foreach ( $results as $key => $value ) {
			$by      = '' !== $engine ? $engine : ( isset( $this->last_engine[ $key ] ) ? $this->last_engine[ $key ] : '' );
			$other   = '' !== $by && ! self::same_engine( $by, $primary );
			$items[] = array(
				'original'   => (string) $key,
				'translated' => false === $value ? '' : (string) $value,
				'engine'     => $by,
				'url'        => $url,
				'status'     => $other ? Store::OUTDATED : Store::AUTO,
			);
			$outdated = $outdated || $other;
		}
		$this->store->save_many( $lang, $items );

		if ( $outdated ) {
			delete_transient( Store::FALLBACK_COUNTS );
			$this->schedule_upgrade( $lang );
		}
	}

	/**
	 * Make sure the queue comes back to redo fallback translations with the
	 * selected engine: shortly after its pause ends, or in five minutes.
	 *
	 * @param string|null $lang Language of the new rows.
	 */
	public function schedule_upgrade( $lang = null ) {
		$primary = $this->engine( $this->primary_id() );
		if ( ! $primary || ! $primary->is_available() || Queue::is_running() ) {
			return;
		}
		$pause = $this->paused( $primary->id(), $lang );
		$delay = is_array( $pause ) && isset( $pause['until'] ) ? max( 60, (int) $pause['until'] - time() + 30 ) : 5 * MINUTE_IN_SECONDS;
		shdt()->queue()->schedule( $delay );
	}

	/**
	 * Keys sent to at least one engine by the last machine_translate() call.
	 *
	 * @return array key => true
	 */
	public function attempted() {
		return $this->attempted;
	}

	/**
	 * Run keys through the engine chain.
	 *
	 * @param string[] $keys     Keys.
	 * @param array    $source   Source language.
	 * @param array    $target   Target language.
	 * @param array    $context  Context.
	 * @param float    $deadline     microtime deadline.
	 * @param bool     $primary_only Only use the selected engine (upgrading outdated rows).
	 * @return array key => string|false
	 */
	public function machine_translate( array $keys, array $source, array $target, array $context, $deadline, $primary_only = false ) {
		$results         = array();
		$todo            = array_fill_keys( $keys, true );
		$terms           = $this->settings->lines( 'glossary' );
		$this->attempted = array();

		$chain = $this->chain( false, $target['code'] );
		if ( $primary_only ) {
			$primary = $this->primary_id();
			$chain   = array_values(
				array_filter(
					$chain,
					function ( $engine ) use ( $primary ) {
						return $engine->id() === $primary;
					}
				)
			);
		}

		foreach ( $chain as $engine ) {
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

			// Text typed or injected by visitors never shares an AI request with
			// site texts, so instructions hidden in it cannot steer their translation.
			$max_batch = ! empty( $context['isolate'] ) && $engine instanceof Engines\AI_Engine ? 1 : $engine->max_batch();
			$batches   = $this->batch( $prepared, $max_batch, $engine->max_chars() );
			if ( ! $batches ) {
				continue;
			}
			$payload = array();
			foreach ( $batches as $index => $batch ) {
				$payload[ $index ] = array_values( $batch );
			}

			$answers  = $engine->translate_batches( $payload, $source, $target, $context, $deadline );
			$sent     = method_exists( $engine, 'sent' ) ? $engine->sent() : array_keys( $batches );
			$failures = method_exists( $engine, 'failures' ) ? $engine->failures() : array();
			foreach ( $sent as $index ) {
				// No credit, bad key, outage, network: not the texts' fault, no attempt used.
				if ( isset( $failures[ $index ] ) && ! $failures[ $index ]->counts ) {
					continue;
				}
				foreach ( array_keys( $batches[ $index ] ) as $key ) {
					$this->attempted[ (string) $key ] = true;
				}
			}

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
				$lang = Engines\Engine_Exception::SCOPE_LANGUAGE === $error->scope ? $target['code'] : null;
				$this->pause( $engine->id(), $error->pause, $error->getMessage(), $lang );
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
	 * Missing translations use the whole engine chain. Outdated ones (made by a
	 * fallback or a previous engine) are only redone by the selected engine;
	 * while it is paused they are left alone and keep showing the old text.
	 *
	 * @param array $rows     Rows with id, lang, original, status.
	 * @param float $deadline microtime deadline.
	 * @return array [ translated count, failed count ]
	 */
	public function translate_rows( array $rows, $deadline ) {
		$groups = array();
		foreach ( $rows as $row ) {
			$upgrade = isset( $row['status'] ) && Store::OUTDATED === (int) $row['status'];
			$isolate = isset( $row['engine'] ) && Store::VISITOR === $row['engine'];
			$groups[ $row['lang'] . '|' . ( $upgrade ? 1 : 0 ) . '|' . ( $isolate ? 1 : 0 ) ][ $row['original'] ] = (int) $row['id'];
		}

		$done   = 0;
		$failed = array();
		$source = $this->languages->get( $this->languages->default_code() );
		foreach ( $groups as $group => $originals ) {
			list( $lang, $upgrade, $isolate ) = explode( '|', $group );
			$target                           = $this->languages->get( $lang );
			if ( ! $target || $this->languages->is_default( $lang ) ) {
				$failed = array_merge( $failed, array_values( $originals ) );
				continue;
			}
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			$keys    = array_map( 'strval', array_keys( $originals ) );
			$results = $this->machine_translate( $keys, $source, $target, array( 'isolate' => '1' === $isolate ), $deadline, '1' === $upgrade );
			$tried   = $this->attempted();
			$this->save( $lang, $results );
			$done += count( $results );
			foreach ( $originals as $original => $id ) {
				$original = (string) $original;
				if ( ! array_key_exists( $original, $results ) && isset( $tried[ $original ] ) ) {
					$failed[] = $id; // Sent but not translated. Rows never sent keep their attempts.
				}
			}
		}
		$this->store->bump_attempts( $failed );
		return array( $done, count( $failed ) );
	}

	/**
	 * Translate a visitor's search term back into the site language.
	 *
	 * Known translations are looked up in the database. Anything else costs an
	 * engine call, so those are limited: short terms only, a few per visitor
	 * and a daily cap for the whole site (filters shdt_search_rate_limit and
	 * shdt_search_daily_limit).
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
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		if ( $length > 100 || ! $this->settings->on( 'auto_translate' ) ) {
			return $text;
		}
		$cache_key = 'shdt_rev_' . md5( $lang . '|' . $text );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$source = $this->languages->get( $lang );
		$target = $this->languages->get( $this->languages->default_code() );
		if ( ! $source || ! $target ) {
			return $text;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		if ( ! self::take( 'shdt_rev_rl_' . md5( $ip ), (int) apply_filters( 'shdt_search_rate_limit', 20 ), 10 * MINUTE_IN_SECONDS )
			|| ! self::take( 'shdt_rev_day_' . gmdate( 'Ymd' ), (int) apply_filters( 'shdt_search_daily_limit', 500 ), DAY_IN_SECONDS ) ) {
			return $text;
		}

		$results = $this->machine_translate( array( $text ), $source, $target, array( 'isolate' => true ), microtime( true ) + 5 );
		if ( isset( $results[ $text ] ) && is_string( $results[ $text ] ) ) {
			// A fallback engine's answer is kept only briefly: the selected one may work again soon.
			$by = isset( $this->last_engine[ $text ] ) ? $this->last_engine[ $text ] : '';
			set_transient( $cache_key, $results[ $text ], self::same_engine( $by, $this->primary_id() ) ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );
			return $results[ $text ];
		}
		// No engine answered (paused, offline): try again a little later.
		set_transient( $cache_key, $text, 5 * MINUTE_IN_SECONDS );
		return $text;
	}

	/**
	 * Take one unit from a counter kept in a transient.
	 *
	 * @param string $key   Transient name.
	 * @param int    $limit Units available.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return bool False when the limit is reached.
	 */
	private static function take( $key, $limit, $ttl ) {
		$used = (int) get_transient( $key );
		if ( $used >= $limit ) {
			return false;
		}
		set_transient( $key, $used + 1, $ttl );
		return true;
	}

	/**
	 * Try an engine with a few sample texts (sent as one batch, like page texts).
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
			$missing = method_exists( $engine, 'missing' ) ? $engine->missing() : array();
			if ( $missing ) {
				/* translators: %s: list of missing settings, e.g. "API key, Model" */
				return array( false, sprintf( __( 'This engine is not configured yet: %s missing. Enter it and save.', 'shd-translator' ), implode( ', ', $missing ) ) );
			}
			return array( false, __( 'This engine is not configured yet (API key or URL missing).', 'shd-translator' ) );
		}
		$targets = $this->languages->targets();
		$target  = $targets ? reset( $targets ) : Languages::make_entry( 'de' );
		$source  = $this->languages->get( $this->languages->default_code() );
		if ( ! isset( $target['google'] ) ) {
			$catalog = Languages::catalog();
			$target  = array_merge( $catalog[ $target['code'] ], $target );
		}
		$samples = array(
			'Welcome! <x1>Discover</x1> our services and get in touch today.',
			'Contact us',
			'Opening hours',
		);
		$answers = $engine->translate_batches( array( $samples ), $source, $target, array(), microtime( true ) + 30 );
		if ( isset( $answers[0] ) && count( array_filter( (array) $answers[0], 'strlen' ) ) === count( $samples ) ) {
			delete_transient( self::pause_key( $id ) );
			delete_transient( self::pause_key( $id, $target['code'] ) );
			Engines\Base_Engine::healthy( $id );
			$last = get_option( 'shdt_last_error' );
			if ( is_array( $last ) && isset( $last['engine'] ) && $last['engine'] === $id ) {
				delete_option( 'shdt_last_error' );
			}
			if ( $id === $this->primary_id() ) {
				$this->recovered();
			}
			return array( true, implode( ' · ', (array) $answers[0] ) );
		}
		$error = $engine->error();
		if ( ! $error && method_exists( $engine, 'failures' ) ) {
			$failures = $engine->failures();
			$error    = $failures ? reset( $failures ) : null;
		}
		if ( $error ) {
			return array( false, $error->getMessage() );
		}
		$last = get_option( 'shdt_last_error' );
		return array( false, is_array( $last ) && $last['engine'] === $id ? $last['message'] : __( 'No answer from the engine.', 'shd-translator' ) );
	}

	/**
	 * The selected engine works (again): give failed texts a fresh set of attempts
	 * and let the queue redo fallback translations soon.
	 */
	public function recovered() {
		$this->store->reset_attempts();
		if ( $this->store->count_pending() > 0 ) {
			shdt()->queue()->schedule( 30, true );
		}
	}
}
