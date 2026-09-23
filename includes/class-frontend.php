<?php
/**
 * Front-end integration: output translation, SEO tags, assets, admin bar.
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Frontend {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Collected output chunks (when something flushes early).
	 *
	 * @var string
	 */
	private $chunks = '';

	/**
	 * Visitor's own search term (before translating it back).
	 *
	 * @var string|null
	 */
	private $search_term = null;

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
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 1 );
		add_action( 'wp_head', array( $this, 'head' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 80 );
		add_action( 'wp_footer', array( $this, 'floating_switcher' ) );
		add_filter( 'wp_nav_menu_items', array( $this, 'menu_switcher' ), 20, 2 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'request', array( $this, 'translate_search' ) );
		add_filter( 'get_search_query', array( $this, 'search_query' ) );
	}

	/**
	 * Whether this request's HTML should be translated.
	 *
	 * @return bool
	 */
	public function should_translate() {
		$languages = $this->plugin->languages();
		if ( ! $languages->is_translated_request() ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() || ( function_exists( 'is_favicon' ) && is_favicon() ) ) {
			return false;
		}
		if ( 'UTF-8' !== strtoupper( (string) get_option( 'blog_charset', 'UTF-8' ) ) ) {
			return false;
		}
		// Page builders and previews edit the original content.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( array( 'elementor-preview', 'elementor_library', 'fl_builder', 'et_fb', 'bricks', 'ct_builder', 'vc_editable', 'tve', 'brizy-edit-iframe' ) as $param ) {
			if ( isset( $_GET[ $param ] ) ) {
				return false;
			}
		}
		// phpcs:enable
		if ( is_customize_preview() ) {
			return false;
		}
		if ( $this->plugin->router()->is_current_excluded() ) {
			return false;
		}
		return (bool) apply_filters( 'shdt_should_translate_page', true );
	}

	/**
	 * Whether the visual translation editor is active on this request.
	 *
	 * @return bool
	 */
	public function is_editor() {
		return isset( $_GET['shdt_editor'] ) && current_user_can( 'manage_options' ) && $this->plugin->languages()->is_translated_request(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Start capturing the page.
	 */
	public function start_buffer() {
		if ( $this->should_translate() ) {
			ob_start( array( $this, 'process_output' ) );
		}
	}

	/**
	 * Output buffer callback.
	 *
	 * @param string $html  Buffer.
	 * @param int    $phase PHP_OUTPUT_HANDLER_* flags.
	 * @return string
	 */
	public function process_output( $html, $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			$this->chunks = '';
			return '';
		}
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			// Someone flushed early: keep collecting, the document must be translated in one go.
			$this->chunks .= $html;
			return '';
		}
		$html         = $this->chunks . $html;
		$this->chunks = '';

		if ( ! $this->is_html_response( $html ) ) {
			return $html;
		}

		try {
			return $this->translate_document( $html );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SHD Translator: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return $html;
		}
	}

	/**
	 * Whether the buffered output is an HTML document.
	 *
	 * @param string $html Output.
	 * @return bool
	 */
	private function is_html_response( $html ) {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'html' ) ) {
				return false;
			}
		}
		$head = substr( $html, 0, 2000 );
		return false !== stripos( $head, '<html' ) || false !== stripos( $head, '<!doctype html' ) || false !== stripos( $html, '<body' );
	}

	/**
	 * Translate a complete page.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function translate_document( $html ) {
		$plugin    = $this->plugin;
		$languages = $plugin->languages();
		$settings  = $plugin->settings();
		$lang      = $languages->current();
		$entry     = $languages->get( $lang );
		$router    = $plugin->router();
		$budget    = (int) $settings->get( 'time_budget', 20 );

		if ( $this->is_warmup() ) {
			$budget = 55;
		}

		$context = array(
			'url'    => $router->current_url( $languages->default_code() ),
			'title'  => wp_strip_all_tags( wp_get_document_title() ),
			'budget' => $budget,
		);

		$processor = new Html_Processor(
			array(
				'translate'            => function ( array $keys ) use ( $plugin, $lang, $context ) {
					return $plugin->translator()->translate_keys( $keys, $lang, $context );
				},
				'localize_url'         => function ( $url ) use ( $router, $lang ) {
					return $router->localize_url( $url, $lang );
				},
				'html_lang'            => $languages->hreflang( $lang ),
				'rtl'                  => ! empty( $entry['rtl'] ),
				'og_locale'            => $entry ? $entry['locale'] : '',
				'selector'             => new Selector( $settings->lines( 'exclude_selectors', true ) ),
				'translate_meta'       => $settings->on( 'translate_meta' ),
				'translate_attributes' => $settings->on( 'translate_attributes' ),
				'json_keys'            => apply_filters( 'shdt_json_keys', array( 'rotating_text' ) ),
			)
		);

		$output = $processor->process( $html );

		if ( $processor->missing() > 0 || $this->is_editor() ) {
			// Do not let page caches keep a half translated page.
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
				header( 'X-SHDT-Pending: ' . (int) $processor->missing() );
			}
		}

		if ( $this->is_editor() ) {
			$output = $this->inject_editor_data( $output, $processor->used(), $lang );
		}

		return apply_filters( 'shdt_translated_html', $output, $lang );
	}

	/**
	 * Whether this is a pre-translation request from the Tools screen.
	 *
	 * @return bool
	 */
	private function is_warmup() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['shdt_warm'] ) ? sanitize_text_field( wp_unslash( $_GET['shdt_warm'] ) ) : '';
		$saved = get_transient( 'shdt_warm_token' );
		return '' !== $token && is_string( $saved ) && hash_equals( $saved, $token );
	}

	/**
	 * Add the strings of this page for the visual editor.
	 *
	 * @param string $html Output.
	 * @param array  $used key => translation.
	 * @param string $lang Language.
	 * @return string
	 */
	private function inject_editor_data( $html, array $used, $lang ) {
		$items = array();
		foreach ( $used as $original => $translation ) {
			$items[] = array(
				'o' => (string) $original,
				't' => null === $translation ? '' : (string) $translation,
			);
		}
		$json   = wp_json_encode(
			array(
				'lang'    => $lang,
				'strings' => $items,
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
		);
		$script = '<script type="application/json" id="shdt-editor-data">' . $json . '</script>';
		$pos    = strripos( $html, '</body>' );
		return false === $pos ? $html . $script : substr_replace( $html, $script, $pos, 0 );
	}

	/**
	 * hreflang alternates and the optional browser language redirect.
	 */
	public function head() {
		$plugin    = $this->plugin;
		$languages = $plugin->languages();
		if ( ! $languages->has_targets() || is_404() || $plugin->router()->is_current_excluded() ) {
			return;
		}

		$urls = array();
		foreach ( $languages->active() as $code => $lang ) {
			$urls[ $code ] = $plugin->router()->current_url( $code );
		}

		if ( $plugin->settings()->on( 'hreflang' ) && ! is_search() ) {
			foreach ( $urls as $code => $url ) {
				printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr( $languages->hreflang( $code ) ), esc_url( $url ) );
			}
			printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( $urls[ $languages->default_code() ] ) );
		}

		if ( $plugin->settings()->on( 'browser_redirect' ) && ! $languages->is_translated_request() && ! is_user_logged_in() ) {
			$map = array();
			foreach ( $urls as $code => $url ) {
				$map[ strtolower( $languages->hreflang( $code ) ) ] = $languages->is_default( $code ) ? '' : $url;
			}
			$this->print_redirect_script( $map );
		}
	}

	/**
	 * Tiny inline script: first visit → visitor's browser language (cache friendly, skips bots).
	 *
	 * @param array $map hreflang => URL ('' for the current page).
	 */
	private function print_redirect_script( array $map ) {
		$json = wp_json_encode( $map, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "<script>(function(m){try{if(/bot|crawl|spider|slurp|lighthouse|headless|preview/i.test(navigator.userAgent)||document.cookie.indexOf('shdt_lang=')>-1)return;"
			. "var l=navigator.languages||[navigator.language||''],k,i,c,b,hit=null;"
			. "for(i=0;i<l.length&&hit===null;i++){c=String(l[i]).toLowerCase();if(c in m){hit=c;break;}b=c.split('-')[0];for(k in m){if(k.split('-')[0]===b){hit=k;break;}}}"
			. "if(hit===null)return;document.cookie='shdt_lang='+hit+';path=/;max-age=31536000;samesite=lax';if(m[hit])location.replace(m[hit]);}catch(e){}})("
			. $json . ");</script>\n";
		// phpcs:enable
	}

	/**
	 * Front-end assets.
	 */
	public function assets() {
		$languages = $this->plugin->languages();
		wp_register_style( 'shdt-switcher', SHDT_URL . 'assets/css/switcher.css', array(), SHDT_VERSION );
		wp_register_script( 'shdt-switcher', SHDT_URL . 'assets/js/switcher.js', array(), SHDT_VERSION, true );

		if ( ! $languages->has_targets() ) {
			return;
		}
		wp_enqueue_style( 'shdt-switcher' );
		wp_enqueue_script( 'shdt-switcher' );

		if ( ! $languages->is_translated_request() || ! $this->should_translate() ) {
			return;
		}

		if ( $this->is_editor() ) {
			wp_enqueue_style( 'shdt-editor', SHDT_URL . 'assets/css/editor.css', array(), SHDT_VERSION );
			wp_enqueue_script( 'shdt-editor', SHDT_URL . 'assets/js/editor.js', array(), SHDT_VERSION, true );
			wp_localize_script(
				'shdt-editor',
				'shdtEditor',
				array(
					'endpoint' => esc_url_raw( rest_url( 'shdt/v1/strings/save' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'language' => $languages->get( $languages->current() )['name'],
					'exitUrl'  => esc_url_raw( $this->plugin->router()->current_url( $languages->current() ) ),
					'i18n'     => array(
						'title'    => __( 'Translate this page', 'shd-translator' ),
						'search'   => __( 'Search strings…', 'shd-translator' ),
						'save'     => __( 'Save', 'shd-translator' ),
						'saved'    => __( 'Saved', 'shd-translator' ),
						'error'    => __( 'Could not save', 'shd-translator' ),
						'pick'     => __( 'Pick text on the page', 'shd-translator' ),
						'close'    => __( 'Close editor', 'shd-translator' ),
						'pending'  => __( 'not translated yet', 'shd-translator' ),
						'original' => __( 'Original', 'shd-translator' ),
						'tags'     => __( 'Keep tags like <x1>…</x1> around the matching words.', 'shd-translator' ),
						/* translators: %d: number of strings */
						'count'    => __( '%d strings on this page', 'shd-translator' ),
					),
				)
			);
			return;
		}

		if ( $this->plugin->settings()->on( 'dynamic' ) ) {
			wp_enqueue_script( 'shdt-dynamic', SHDT_URL . 'assets/js/dynamic.js', array(), SHDT_VERSION, true );
			wp_localize_script(
				'shdt-dynamic',
				'shdtDynamic',
				array(
					'lang'     => $languages->current(),
					'endpoint' => esc_url_raw( rest_url( 'shdt/v1/translate' ) ),
					'skip'     => implode( ',', array_merge( array( '[translate="no"]', '.notranslate', '#wpadminbar', '[data-shdt-switcher]', 'script', 'style', 'code', 'pre', 'textarea', 'svg' ), $this->plugin->settings()->lines( 'exclude_selectors', true ) ) ),
				)
			);
		}
	}

	/**
	 * Admin bar: language links and the page editor.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function admin_bar( $bar ) {
		if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$languages = $this->plugin->languages();
		if ( ! $languages->has_targets() ) {
			return;
		}
		$current = $languages->get( $languages->current() );
		$bar->add_node(
			array(
				'id'    => 'shdt',
				'title' => '<span class="ab-icon dashicons dashicons-translation" style="top:2px"></span>' . esc_html( $current ? $current['label'] : '' ),
				'href'  => admin_url( 'admin.php?page=shd-translator' ),
			)
		);
		if ( $languages->is_translated_request() && ! $this->is_editor() ) {
			$bar->add_node(
				array(
					'parent' => 'shdt',
					'id'     => 'shdt-editor',
					'title'  => esc_html__( 'Edit translations of this page', 'shd-translator' ),
					'href'   => add_query_arg( 'shdt_editor', 1, $this->plugin->router()->current_url( $languages->current() ) ),
				)
			);
		}
		foreach ( $languages->active() as $code => $lang ) {
			$bar->add_node(
				array(
					'parent' => 'shdt',
					'id'     => 'shdt-lang-' . sanitize_key( $code ),
					/* translators: %s: language name */
					'title'  => esc_html( sprintf( __( 'View in %s', 'shd-translator' ), $lang['name'] ) ),
					'href'   => $this->plugin->router()->current_url( $code ),
				)
			);
		}
	}

	/**
	 * Optional floating switcher.
	 */
	public function floating_switcher() {
		$settings = $this->plugin->settings();
		if ( ! $settings->on( 'floating' ) || ! $this->plugin->languages()->has_targets() ) {
			return;
		}
		$position = (string) $settings->get( 'floating_position', 'bottom-right' );
		echo '<div class="shdt-floating shdt-floating--' . esc_attr( $position ) . '">';
		$html = Switcher::render(
			array(
				'display'   => $settings->get( 'switcher_display', 'code' ),
				'direction' => 0 === strpos( $position, 'bottom' ) ? 'up' : 'down',
				'align'     => false !== strpos( $position, 'left' ) ? 'left' : 'right',
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built and escaped by Switcher::render().
		echo '</div>';
	}

	/**
	 * Append the switcher to a theme menu location.
	 *
	 * @param string    $items Menu HTML.
	 * @param \stdClass $args  Menu args.
	 * @return string
	 */
	public function menu_switcher( $items, $args ) {
		$location = (string) $this->plugin->settings()->get( 'menu_location', '' );
		if ( '' === $location || empty( $args->theme_location ) || $args->theme_location !== $location || ! $this->plugin->languages()->has_targets() ) {
			return $items;
		}
		return $items . '<li class="menu-item shdt-menu-item">' . Switcher::render( array( 'display' => $this->plugin->settings()->get( 'switcher_display', 'code' ) ) ) . '</li>';
	}

	/**
	 * Body classes.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		$classes[] = 'shdt-lang-' . sanitize_html_class( strtolower( $this->plugin->languages()->current() ) );
		return $classes;
	}

	/**
	 * Visitors search in their language; content is searched in the original language.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function translate_search( $vars ) {
		$languages = $this->plugin->languages();
		if ( empty( $vars['s'] ) || ! is_string( $vars['s'] ) || is_admin() || ! $languages->is_translated_request() || ! $this->plugin->settings()->on( 'search_translate' ) ) {
			return $vars;
		}
		$this->search_term = $vars['s'];
		$vars['s']         = $this->plugin->translator()->reverse( $vars['s'], $languages->current() );
		return $vars;
	}

	/**
	 * Show the visitor's own search term.
	 *
	 * @param string $query Query.
	 * @return string
	 */
	public function search_query( $query ) {
		return null !== $this->search_term ? $this->search_term : $query;
	}
}
