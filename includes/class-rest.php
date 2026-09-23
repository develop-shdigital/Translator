<?php
/**
 * REST API: dynamic content translation (public) and admin tools.
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Rest {

	const NS = 'shdt/v1';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Admin permission.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register routes.
	 */
	public function routes() {
		$admin = array( $this, 'can_manage' );

		register_rest_route(
			self::NS,
			'/translate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'translate' ),
				'permission_callback' => '__return_true',
			)
		);

		$routes = array(
			array( '/strings', 'GET', 'list_strings' ),
			array( '/strings/save', 'POST', 'save_string' ),
			array( '/strings/(?P<id>\d+)', 'POST', 'update_string' ),
			array( '/strings/(?P<id>\d+)', 'DELETE', 'delete_string' ),
			array( '/strings/(?P<id>\d+)/retranslate', 'POST', 'retranslate_string' ),
			array( '/clear', 'POST', 'clear' ),
			array( '/export', 'GET', 'export' ),
			array( '/import', 'POST', 'import' ),
			array( '/test-engine', 'POST', 'test_engine' ),
			array( '/urls', 'GET', 'urls' ),
			array( '/queue/run', 'POST', 'run_queue' ),
			array( '/pending', 'GET', 'pending' ),
			array( '/browser', 'POST', 'browser_results' ),
			array( '/stats', 'GET', 'stats' ),
			array( '/resume', 'POST', 'resume' ),
		);
		foreach ( $routes as $route ) {
			register_rest_route(
				self::NS,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => $admin,
				)
			);
		}
	}

	/**
	 * Visitor IP for rate limiting.
	 *
	 * @return string
	 */
	private function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
	}

	/**
	 * Translate text inserted by JavaScript (AJAX content, popups, form messages).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function translate( \WP_REST_Request $request ) {
		$settings  = $this->plugin->settings();
		$languages = $this->plugin->languages();
		$lang      = sanitize_text_field( (string) $request->get_param( 'lang' ) );
		$strings   = $request->get_param( 'strings' );

		if ( ! $settings->on( 'dynamic' ) || ! $languages->is_active( $lang ) || $languages->is_default( $lang ) || ! is_array( $strings ) ) {
			return new \WP_Error( 'shdt_invalid', 'Invalid request', array( 'status' => 400 ) );
		}

		// Rate limit per visitor: 60 calls per 5 minutes.
		$bucket = 'shdt_rl_' . md5( $this->ip() );
		$calls  = (int) get_transient( $bucket );
		if ( $calls >= (int) apply_filters( 'shdt_dynamic_rate_limit', 60 ) ) {
			return new \WP_Error( 'shdt_rate_limited', 'Too many requests', array( 'status' => 429 ) );
		}
		set_transient( $bucket, $calls + 1, 5 * MINUTE_IN_SECONDS );

		$keys = array();
		foreach ( array_slice( $strings, 0, 50 ) as $string ) {
			if ( ! is_string( $string ) || strlen( $string ) > 2000 ) {
				continue;
			}
			$key = Text::normalize( $string );
			if ( Text::is_translatable( $key ) && ! Text::has_placeholders( $key ) ) {
				$keys[ $key ] = true;
			}
		}
		$keys = array_map( 'strval', array_keys( $keys ) );
		if ( ! $keys ) {
			return rest_ensure_response( array( 'translations' => new \stdClass() ) );
		}

		// Text that is already a translation (e.g. cloned slides) stays as it is.
		$hashes = array();
		foreach ( $keys as $key ) {
			$hashes[ Store::hash( $key ) ] = $key;
		}
		$known = $this->plugin->store()->known_translations( $lang, array_keys( $hashes ) );
		$keys  = array_values( array_diff( $keys, array_intersect_key( $hashes, $known ) ) );

		// Daily cap on brand-new strings coming from browsers.
		$day_key = 'shdt_dyn_' . gmdate( 'Ymd' );
		$today   = (int) get_transient( $day_key );
		$engine  = $today < (int) apply_filters( 'shdt_dynamic_daily_limit', 5000 );

		$page = wp_get_referer();
		if ( $page ) {
			$local = $this->plugin->router()->localize_url( $page, $languages->default_code() );
			$page  = esc_url_raw( is_string( $local ) ? $local : $page );
		}
		$result = $keys ? $this->plugin->translator()->translate_keys(
			$keys,
			$lang,
			array(
				'url'    => $page ? $page : '',
				'budget' => 10,
				'engine' => $engine,
				'queue'  => $engine,
			)
		) : array();

		$out = array();
		foreach ( $result as $key => $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$out[ $key ] = $value;
			}
		}
		if ( $engine ) {
			set_transient( $day_key, $today + count( $keys ), DAY_IN_SECONDS );
		}

		return rest_ensure_response( array( 'translations' => $out ? $out : new \stdClass() ) );
	}

	/**
	 * List strings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_strings( \WP_REST_Request $request ) {
		$status = $request->get_param( 'status' );
		return rest_ensure_response(
			$this->plugin->store()->query(
				array(
					'lang'     => sanitize_text_field( (string) $request->get_param( 'lang' ) ),
					'status'   => '' === (string) $status || null === $status ? '' : absint( $status ),
					'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
					'page'     => max( 1, absint( $request->get_param( 'page' ) ) ),
					'per_page' => max( 1, min( 200, absint( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 50 ) ) ),
				)
			)
		);
	}

	/**
	 * Validate a manual translation against its original.
	 *
	 * @param string $original   Original key.
	 * @param string $translated Translation.
	 * @return string|\WP_Error
	 */
	private function clean_manual( $original, $translated ) {
		$translated = Text::normalize( Text::canonical_placeholders( (string) $translated ) );
		if ( '' === $translated ) {
			return new \WP_Error( 'shdt_empty', __( 'The translation is empty.', 'shd-translator' ), array( 'status' => 400 ) );
		}
		if ( Text::has_placeholders( $original ) && ! Text::placeholders_match( $original, $translated ) ) {
			return new \WP_Error( 'shdt_tags', __( 'Keep every tag like <x1>…</x1> of the original exactly once.', 'shd-translator' ), array( 'status' => 400 ) );
		}
		if ( ! Text::has_placeholders( $original ) && Text::has_placeholders( $translated ) ) {
			$translated = Text::normalize( preg_replace( Text::PLACEHOLDER, '', $translated ) );
		}
		return $translated;
	}

	/**
	 * Save a translation by original text (visual editor).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_string( \WP_REST_Request $request ) {
		$lang     = sanitize_text_field( (string) $request->get_param( 'lang' ) );
		$original = Text::normalize( (string) $request->get_param( 'original' ) );
		if ( ! $this->plugin->languages()->is_active( $lang ) || '' === $original ) {
			return new \WP_Error( 'shdt_invalid', 'Invalid request', array( 'status' => 400 ) );
		}
		$translated = $this->clean_manual( $original, $request->get_param( 'translated' ) );
		if ( is_wp_error( $translated ) ) {
			return $translated;
		}
		$id = $this->plugin->store()->save_manual( $lang, $original, $translated );
		return rest_ensure_response(
			array(
				'id'         => $id,
				'translated' => $translated,
			)
		);
	}

	/**
	 * Update a row.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_string( \WP_REST_Request $request ) {
		$store = $this->plugin->store();
		$row   = $store->get_row( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'shdt_not_found', 'Not found', array( 'status' => 404 ) );
		}
		$translated = $this->clean_manual( $row['original'], $request->get_param( 'translated' ) );
		if ( is_wp_error( $translated ) ) {
			return $translated;
		}
		$store->update_row( (int) $row['id'], $translated );
		return rest_ensure_response( array( 'row' => $store->get_row( (int) $row['id'] ) ) );
	}

	/**
	 * Delete a row.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_string( \WP_REST_Request $request ) {
		return rest_ensure_response( array( 'deleted' => $this->plugin->store()->delete_rows( array( (int) $request['id'] ) ) ) );
	}

	/**
	 * Machine translate one row again.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retranslate_string( \WP_REST_Request $request ) {
		$store     = $this->plugin->store();
		$languages = $this->plugin->languages();
		$row       = $store->get_row( (int) $request['id'] );
		if ( ! $row || ! $languages->is_active( $row['lang'] ) ) {
			return new \WP_Error( 'shdt_not_found', 'Not found', array( 'status' => 404 ) );
		}
		$results = $this->plugin->translator()->machine_translate(
			array( $row['original'] ),
			$languages->get( $languages->default_code() ),
			$languages->get( $row['lang'] ),
			array(),
			microtime( true ) + 30
		);
		if ( ! array_key_exists( $row['original'], $results ) ) {
			$last = get_option( 'shdt_last_error' );
			return new \WP_Error( 'shdt_failed', is_array( $last ) ? $last['message'] : __( 'Translation failed.', 'shd-translator' ), array( 'status' => 502 ) );
		}
		$store->delete_rows( array( (int) $row['id'] ) );
		$this->plugin->translator()->save( $row['lang'], $results, $row['url'] );
		$rows = $store->get_many( $row['lang'], array( Store::hash( $row['original'] ) ) );
		return rest_ensure_response(
			array(
				'translated' => false === $results[ $row['original'] ] ? '' : $results[ $row['original'] ],
				'rows'       => $rows,
			)
		);
	}

	/**
	 * Clear translations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function clear( \WP_REST_Request $request ) {
		$scope = sanitize_key( (string) $request->get_param( 'scope' ) );
		$scope = in_array( $scope, array( 'auto', 'pending', 'all' ), true ) ? $scope : 'auto';
		$count = $this->plugin->store()->clear( sanitize_text_field( (string) $request->get_param( 'lang' ) ), $scope );
		return rest_ensure_response( array( 'deleted' => $count ) );
	}

	/**
	 * Export translations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function export( \WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'plugin'  => 'shd-translator',
				'version' => SHDT_VERSION,
				'source'  => $this->plugin->languages()->default_code(),
				'rows'    => $this->plugin->store()->export( sanitize_text_field( (string) $request->get_param( 'lang' ) ) ),
			)
		);
	}

	/**
	 * Import translations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function import( \WP_REST_Request $request ) {
		$rows = $request->get_param( 'rows' );
		return rest_ensure_response( array( 'imported' => is_array( $rows ) ? $this->plugin->store()->import( $rows ) : 0 ) );
	}

	/**
	 * Test an engine.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function test_engine( \WP_REST_Request $request ) {
		list( $ok, $message ) = $this->plugin->translator()->test( sanitize_key( (string) $request->get_param( 'engine' ) ) );
		return rest_ensure_response(
			array(
				'ok'      => $ok,
				'message' => $message,
			)
		);
	}

	/**
	 * URLs to pre-translate (all public content, every target language).
	 *
	 * @return \WP_REST_Response
	 */
	public function urls() {
		$urls = array( home_url( '/' ) );

		$types = get_post_types(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			)
		);
		$types = array_diff( array_merge( $types, array( 'page' ) ), array( 'attachment', 'elementor_library', 'e-floating-buttons' ) );
		$ids   = get_posts(
			array(
				'post_type'        => array_values( array_unique( $types ) ),
				'post_status'      => 'publish',
				'posts_per_page'   => (int) apply_filters( 'shdt_warmup_limit', 1000 ),
				'fields'           => 'ids',
				'orderby'          => 'menu_order date',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'has_password'     => false,
			)
		);
		foreach ( $ids as $id ) {
			$link = get_permalink( $id );
			if ( $link ) {
				$urls[] = $link;
			}
		}
		$urls = array_values( array_unique( apply_filters( 'shdt_warmup_urls', $urls ) ) );

		$token = wp_generate_password( 20, false );
		set_transient( 'shdt_warm_token', $token, 2 * HOUR_IN_SECONDS );

		$router = $this->plugin->router();
		$list   = array();
		foreach ( $this->plugin->languages()->targets() as $code => $lang ) {
			foreach ( $urls as $url ) {
				$local = $router->localize_url( $url, $code );
				if ( is_string( $local ) ) {
					$list[] = array(
						'lang' => $code,
						'name' => $lang['name'],
						'url'  => add_query_arg( 'shdt_warm', $token, $local ),
					);
				}
			}
		}
		return rest_ensure_response(
			array(
				'pages' => count( $urls ),
				'urls'  => $list,
			)
		);
	}

	/**
	 * Run the background queue now.
	 *
	 * @return \WP_REST_Response
	 */
	public function run_queue() {
		list( $done, $failed, $remaining ) = $this->plugin->queue()->run( 20 );
		return rest_ensure_response(
			array(
				'translated' => $done,
				'failed'     => $failed,
				'remaining'  => $remaining,
				'paused'     => $this->paused_engines(),
			)
		);
	}

	/**
	 * Pending strings for browser-assisted translation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function pending( \WP_REST_Request $request ) {
		$languages = $this->plugin->languages();
		$source    = $languages->get( $languages->default_code() );
		$rows      = $this->plugin->store()->get_pending( max( 1, min( 200, absint( $request->get_param( 'limit' ) ? $request->get_param( 'limit' ) : 100 ) ) ) );
		$out       = array();
		foreach ( $rows as $row ) {
			$target = $languages->get( $row['lang'] );
			if ( ! $target || $languages->is_default( $row['lang'] ) ) {
				continue;
			}
			$out[] = array(
				'id'       => (int) $row['id'],
				'lang'     => $row['lang'],
				'original' => $row['original'],
				'sl'       => $source['google'],
				'tl'       => $target['google'],
			);
		}
		return rest_ensure_response(
			array(
				'rows'      => $out,
				'remaining' => $this->plugin->store()->count_pending(),
			)
		);
	}

	/**
	 * Store translations produced in the admin's browser.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function browser_results( \WP_REST_Request $request ) {
		$items   = $request->get_param( 'items' );
		$store   = $this->plugin->store();
		$saved   = 0;
		$failed  = array();
		$by_lang = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( empty( $item['id'] ) || ! isset( $item['translated'] ) ) {
				continue;
			}
			$row = $store->get_row( (int) $item['id'] );
			if ( ! $row || Store::PENDING !== (int) $row['status'] ) {
				continue;
			}
			$lang   = $this->plugin->languages()->get( $row['lang'] );
			$value  = Text::normalize( Text::canonical_placeholders( html_entity_decode( (string) $item['translated'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			$source = $row['original'];
			if ( ! $lang || '' === $value ) {
				$failed[] = (int) $row['id'];
				continue;
			}
			if ( Text::has_placeholders( $source ) && ! Text::placeholders_match( $source, $value ) ) {
				$value = false;
			} elseif ( Text::has_placeholders( $value ) ) {
				$value = Text::normalize( preg_replace( Text::PLACEHOLDER, '', $value ) );
			}
			$by_lang[ $row['lang'] ][ $source ] = false === $value ? false : Text::postprocess( $value, $lang['locale'] );
			$saved++;
		}
		foreach ( $by_lang as $lang => $results ) {
			$this->plugin->translator()->save( $lang, $results, '', 'browser' );
		}
		$store->bump_attempts( $failed );
		return rest_ensure_response(
			array(
				'saved'     => $saved,
				'remaining' => $store->count_pending(),
			)
		);
	}

	/**
	 * Engines currently paused.
	 *
	 * @return array
	 */
	private function paused_engines() {
		$out = array();
		foreach ( array_keys( Translator::engine_classes() ) as $id ) {
			$pause = $this->plugin->translator()->paused( $id );
			if ( $pause ) {
				$out[ $id ] = $pause;
			}
		}
		return $out;
	}

	/**
	 * Statistics.
	 *
	 * @return \WP_REST_Response
	 */
	public function stats() {
		return rest_ensure_response(
			array(
				'stats'   => $this->plugin->store()->stats(),
				'pending' => $this->plugin->store()->count_pending(),
				'paused'  => $this->paused_engines(),
				'error'   => get_option( 'shdt_last_error' ),
			)
		);
	}

	/**
	 * Resume paused engines.
	 *
	 * @return \WP_REST_Response
	 */
	public function resume() {
		$this->plugin->translator()->resume_all();
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
