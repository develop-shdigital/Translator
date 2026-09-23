<?php
/**
 * Admin screens.
 *
 * @package SHDT
 */

namespace SHDT\Admin;

use SHDT\Installer;
use SHDT\Languages;
use SHDT\Plugin;
use SHDT\Translator;

defined( 'ABSPATH' ) || exit;

class Admin {

	const SLUG = 'shd-translator';

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
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_shdt_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_shdt_install_packs', array( $this, 'install_language_packs' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . SHDT_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Upgrades and first-run redirect.
	 */
	public function admin_init() {
		Installer::maybe_install();

		if ( get_transient( 'shdt_activation_redirect' ) && current_user_can( 'manage_options' ) && ! wp_doing_ajax() && ! isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_transient( 'shdt_activation_redirect' );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&welcome=1' ) );
			exit;
		}
	}

	/**
	 * Menu.
	 */
	public function menu() {
		add_menu_page(
			__( 'SHD Translator', 'shd-translator' ),
			__( 'Translator', 'shd-translator' ),
			'manage_options',
			self::SLUG,
			array( $this, 'page_settings' ),
			'dashicons-translation',
			81
		);
		add_submenu_page( self::SLUG, __( 'Languages & Settings', 'shd-translator' ), __( 'Languages & Settings', 'shd-translator' ), 'manage_options', self::SLUG, array( $this, 'page_settings' ) );
		add_submenu_page( self::SLUG, __( 'Translations', 'shd-translator' ), __( 'Translations', 'shd-translator' ), 'manage_options', self::SLUG . '-strings', array( $this, 'page_strings' ) );
		add_submenu_page( self::SLUG, __( 'Tools', 'shd-translator' ), __( 'Tools', 'shd-translator' ), 'manage_options', self::SLUG . '-tools', array( $this, 'page_tools' ) );
	}

	/**
	 * Whether we are on one of our screens.
	 *
	 * @param string $hook Screen hook.
	 * @return bool
	 */
	private function is_our_screen( $hook ) {
		return false !== strpos( (string) $hook, self::SLUG );
	}

	/**
	 * Admin assets.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( $hook ) {
		if ( ! $this->is_our_screen( $hook ) ) {
			return;
		}
		wp_enqueue_style( 'shdt-switcher', SHDT_URL . 'assets/css/switcher.css', array(), SHDT_VERSION );
		wp_enqueue_style( 'shdt-admin', SHDT_URL . 'assets/css/admin.css', array( 'shdt-switcher' ), SHDT_VERSION );
		wp_enqueue_script( 'shdt-admin', SHDT_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable' ), SHDT_VERSION, true );

		$catalog = array();
		foreach ( Languages::catalog() as $code => $lang ) {
			$catalog[ $code ] = array(
				'english' => $lang['english'],
				'native'  => $lang['native'],
				'locale'  => $lang['locale'],
				'flag'    => $lang['flag'],
			);
		}

		wp_localize_script(
			'shdt-admin',
			'shdtAdmin',
			array(
				'rest'     => esc_url_raw( rest_url( 'shdt/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'catalog'  => $catalog,
				'flagsUrl' => SHDT_URL . 'assets/flags/',
				'google'   => 'https://clients5.google.com/translate_a/t?client=dict-chrome-ex',
				'i18n'     => array(
					'remove'        => __( 'Remove', 'shd-translator' ),
					'original'      => __( 'Original', 'shd-translator' ),
					'saved'         => __( 'Saved', 'shd-translator' ),
					'error'         => __( 'Something went wrong', 'shd-translator' ),
					'confirmClear'  => __( 'Delete these translations? This cannot be undone.', 'shd-translator' ),
					'confirmDelete' => __( 'Delete this translation?', 'shd-translator' ),
					'testing'       => __( 'Testing…', 'shd-translator' ),
					'working'       => __( 'Working…', 'shd-translator' ),
					'done'          => __( 'Done', 'shd-translator' ),
					'stopped'       => __( 'Stopped', 'shd-translator' ),
					/* translators: 1: number done, 2: total or remaining */
					'pagesDone'     => __( '%1$d of %2$d pages translated', 'shd-translator' ),
					/* translators: 1: number done, 2: total or remaining */
					'queueStatus'   => __( '%1$d translated, %2$d still waiting', 'shd-translator' ),
					/* translators: 1: number done, 2: total or remaining */
					'browserStatus' => __( '%1$d strings translated in your browser, %2$d waiting', 'shd-translator' ),
					/* translators: %d: number of translations */
					'imported'      => __( '%d translations imported', 'shd-translator' ),
					/* translators: %d: number of translations */
					'deleted'       => __( '%d translations deleted', 'shd-translator' ),
					'noPending'     => __( 'Nothing is waiting for translation.', 'shd-translator' ),
					'paused'        => __( 'The translation service paused (rate limit). Try "Translate with my browser" below or wait a few minutes.', 'shd-translator' ),
					'changeSource'  => __( 'Changing the original language means existing translations no longer match. Continue?', 'shd-translator' ),
				),
			)
		);
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'shd-translator' ) . '</a>' );
		return $links;
	}

	/**
	 * Conflicts and service notices.
	 */
	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$conflicts = array();
		foreach ( array(
			'polylang/polylang.php'                => 'Polylang',
			'polylang-pro/polylang.php'            => 'Polylang Pro',
			'sitepress-multilingual-cms/sitepress.php' => 'WPML',
			'translatepress-multilingual/index.php' => 'TranslatePress',
			'weglot/weglot.php'                    => 'Weglot',
			'gtranslate/gtranslate.php'            => 'GTranslate',
		) as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$conflicts[] = $name;
			}
		}
		if ( $conflicts ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: plugin names */
						__( 'SHD Translator: %s is also active. Two translation plugins will fight over URLs and content — keep only one.', 'shd-translator' ),
						implode( ', ', $conflicts )
					)
				)
			);
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::SLUG ) ) {
			return;
		}
		if ( 'directory' === $this->plugin->settings()->get( 'url_mode' ) && ! get_option( 'permalink_structure' ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				wp_kses_post(
					sprintf(
						/* translators: %s: permalinks URL */
						__( 'Language folders (/de/) need pretty permalinks. <a href="%s">Choose a permalink structure</a> or switch the URL format to "?lang=de".', 'shd-translator' ),
						esc_url( admin_url( 'options-permalink.php' ) )
					)
				)
			);
		}
	}

	/**
	 * Save settings.
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'shd-translator' ) );
		}
		check_admin_referer( 'shdt_save_settings' );

		$settings = $this->plugin->settings();
		$clean    = $settings->sanitize( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed and sanitised field by field.

		// Keep stored API keys when the field is left untouched (they are shown masked).
		foreach ( array( 'anthropic_key', 'deepl_key', 'openai_key', 'libre_key' ) as $key ) {
			if ( isset( $clean[ $key ] ) && self::MASK === $clean[ $key ] ) {
				$clean[ $key ] = $settings->get( $key );
			}
		}

		$settings->update( $clean );
		$this->plugin->languages()->flush();
		$this->plugin->translator()->resume_all();

		wp_safe_redirect( add_query_arg( 'updated', 1, admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	const MASK = '••••••••';

	/**
	 * Masked value for a secret.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function mask( $value ) {
		return '' === (string) $value ? '' : self::MASK;
	}

	/**
	 * Download WordPress core language packs for the active locales.
	 */
	public function install_language_packs() {
		if ( ! current_user_can( 'install_languages' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'shd-translator' ) );
		}
		check_admin_referer( 'shdt_install_packs' );

		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$installed = array();
		if ( wp_can_install_language_pack() ) {
			$available = get_available_languages();
			foreach ( $this->plugin->languages()->active() as $lang ) {
				$locale = $lang['locale'];
				if ( 'en_US' === $locale || in_array( $locale, $available, true ) ) {
					continue;
				}
				$result = wp_download_language_pack( $locale );
				if ( $result ) {
					$installed[] = $result;
				}
			}
		}

		wp_safe_redirect( add_query_arg( 'packs', count( $installed ), admin_url( 'admin.php?page=' . self::SLUG . '-tools' ) ) );
		exit;
	}

	/**
	 * Settings page.
	 */
	public function page_settings() {
		$plugin   = $this->plugin;
		$settings = $plugin->settings()->all();
		$catalog  = Languages::catalog();
		$engines  = array();
		foreach ( array_keys( Translator::engine_classes() ) as $id ) {
			$engines[ $id ] = $plugin->translator()->engine( $id );
		}
		include __DIR__ . '/views/settings.php';
	}

	/**
	 * Translations page.
	 */
	public function page_strings() {
		$plugin = $this->plugin;
		include __DIR__ . '/views/strings.php';
	}

	/**
	 * Tools page.
	 */
	public function page_tools() {
		$plugin = $this->plugin;
		include __DIR__ . '/views/tools.php';
	}
}
