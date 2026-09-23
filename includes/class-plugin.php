<?php
/**
 * Plugin container and bootstrap.
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Services.
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
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * Translator.
	 *
	 * @var Translator
	 */
	private $translator;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private $queue;

	/**
	 * Frontend.
	 *
	 * @var Frontend
	 */
	private $frontend;

	/**
	 * Singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire everything up. Runs while plugins are being loaded.
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->settings   = new Settings();
		$this->languages  = new Languages( $this->settings );
		$this->store      = new Store();
		$this->router     = new Router( $this->languages, $this->settings );
		$this->translator = new Translator( $this->settings, $this->languages, $this->store );
		$this->queue      = new Queue();
		$this->frontend   = new Frontend( $this );

		// Language must be known before WordPress parses the request and loads translations.
		$this->router->detect();

		if ( $this->settings->on( 'switch_locale' ) && $this->languages->is_translated_request() && ( ! is_admin() || wp_doing_ajax() ) ) {
			add_filter( 'locale', array( $this, 'filter_locale' ), 20 );
		}

		$this->router->hooks();
		$this->queue->hooks();
		$this->frontend->hooks();
		( new Rest( $this ) )->hooks();
		( new Switcher( $this ) )->hooks();
		( new Elementor\Integration() )->hooks();

		if ( is_admin() ) {
			( new Admin\Admin( $this ) )->hooks();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Use the language's WordPress locale on translated pages (dates, theme and plugin strings).
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public function filter_locale( $locale ) {
		$lang = $this->languages->get( $this->languages->current() );
		return $lang && ! empty( $lang['locale'] ) ? $lang['locale'] : $locale;
	}

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'shd-translator', false, dirname( SHDT_BASENAME ) . '/languages' );
	}

	/**
	 * Settings.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Languages.
	 *
	 * @return Languages
	 */
	public function languages() {
		return $this->languages;
	}

	/**
	 * Router.
	 *
	 * @return Router
	 */
	public function router() {
		return $this->router;
	}

	/**
	 * Store.
	 *
	 * @return Store
	 */
	public function store() {
		return $this->store;
	}

	/**
	 * Translator.
	 *
	 * @return Translator
	 */
	public function translator() {
		return $this->translator;
	}

	/**
	 * Queue.
	 *
	 * @return Queue
	 */
	public function queue() {
		return $this->queue;
	}

	/**
	 * Frontend.
	 *
	 * @return Frontend
	 */
	public function frontend() {
		return $this->frontend;
	}
}
