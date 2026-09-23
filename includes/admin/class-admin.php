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
		add_action( 'admin_post_shdt_retranslate', array( $this, 'retranslate' ) );
		add_action( 'admin_post_shdt_keep_translations', array( $this, 'keep_translations' ) );
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

		$invalid = get_transient( 'shdt_invalid_selectors' );
		if ( is_array( $invalid ) && $invalid ) {
			printf(
				'<div class="notice notice-warning"><p>%s <code>%s</code></p><p>%s</p></div>',
				esc_html__( 'These exclusion selectors are not supported and are ignored:', 'shd-translator' ),
				implode( '</code> <code>', array_map( 'esc_html', $invalid ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item escaped.
				esc_html__( 'Use one element per rule without spaces, ">" or ":" – for example ".site-footer" instead of ".site-footer p". Everything inside a matching element is excluded anyway.', 'shd-translator' )
			);
		}

		$translator = $this->plugin->translator();
		$primary    = $translator->primary_id();
		$engine     = $translator->engine( $primary );
		$switch     = get_transient( 'shdt_engine_switch' );
		if ( is_array( $switch ) && isset( $switch['engine'] ) && $switch['engine'] === $primary && $engine ) {
			$count = $this->plugin->store()->count_other_engine( Translator::equivalent_ids( $primary ) );
			if ( $count > 0 ) {
				echo '<div class="notice notice-info shdt-switch-notice"><p><strong>';
				echo esc_html(
					sprintf(
						/* translators: 1: number of texts, 2: engine name */
						_n( '%1$s text on your site was translated before you switched to %2$s.', '%1$s texts on your site were translated before you switched to %2$s.', $count, 'shd-translator' ),
						number_format_i18n( $count ),
						$engine->label()
					)
				);
				echo '</strong> ' . esc_html__( 'Stored translations are reused, so they stay as they are unless you re-translate them. The current texts remain online until the new ones are ready; your manual edits are kept.', 'shd-translator' ) . '</p><p>';
				/* translators: %s: engine name */
				echo self::action_button( 'shdt_retranslate', sprintf( __( 'Re-translate them with %s', 'shd-translator' ), $engine->label() ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in action_button().
				echo ' ' . self::action_button( 'shdt_keep_translations', __( 'Keep the current translations', 'shd-translator' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in action_button().
				echo '</p></div>';
			} else {
				delete_transient( 'shdt_engine_switch' );
			}
		}

		// The selected engine is paused: say why, and that the free engines fill in meanwhile.
		foreach ( $translator->pauses() as $pause ) {
			if ( $pause['engine'] !== $primary ) {
				continue;
			}
			$scope = null === $pause['lang'] ? '' : ' (' . $pause['lang'] . ')';
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: engine name, 2: language in brackets or empty, 3: time */
						__( '%1$s%2$s is paused for %3$s:', 'shd-translator' ),
						$pause['label'],
						$scope,
						human_time_diff( time(), max( time() + 60, $pause['until'] ) )
					)
				),
				esc_html( $pause['message'] ),
				esc_html(
					$this->plugin->settings()->on( 'fallback_free' )
						? __( 'Meanwhile the free engines translate new texts; those are redone automatically with the selected engine once it works again. Fix the cause, then use Tools → Resume now.', 'shd-translator' )
						: __( 'New texts wait until it works again. Fix the cause, then use Tools → Resume now.', 'shd-translator' )
				)
			);
		}
		if ( 'directory' === $this->plugin->settings()->get( 'url_mode' ) && ! \SHDT\Router::pretty_permalinks() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				wp_kses_post(
					sprintf(
						/* translators: %s: permalinks URL */
						__( 'Language folders (/de/) need pretty permalinks, so your translated pages use addresses like "?lang=de" for now. <a href="%s">Choose a permalink structure</a> to get /de/ addresses.', 'shd-translator' ),
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

		$settings   = $this->plugin->settings();
		$old_engine = (string) $settings->get( 'engine', 'google' );
		$clean      = $settings->sanitize( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed and sanitised field by field.

		// Keep stored API keys when the field is left untouched (they are shown masked),
		// but never send a stored key to a server address that was just changed.
		$servers = array(
			'openai_key' => 'openai_base',
			'libre_key'  => 'libre_url',
		);
		foreach ( array( 'anthropic_key', 'deepl_key', 'openai_key', 'libre_key' ) as $key ) {
			if ( isset( $clean[ $key ] ) && self::MASK === $clean[ $key ] ) {
				$moved         = isset( $servers[ $key ], $clean[ $servers[ $key ] ] ) && (string) $clean[ $servers[ $key ] ] !== (string) $settings->get( $servers[ $key ] );
				$clean[ $key ] = $moved ? '' : $settings->get( $key );
			}
		}

		$settings->update( $clean );
		$this->plugin->languages()->flush();

		$selectors = new \SHDT\Selector( $settings->lines( 'exclude_selectors' ) );
		if ( $selectors->invalid() ) {
			set_transient( 'shdt_invalid_selectors', $selectors->invalid(), HOUR_IN_SECONDS );
		} else {
			delete_transient( 'shdt_invalid_selectors' );
		}
		$this->plugin->translator()->resume_all();

		// Switched engine: offer to redo what the previous engine translated.
		if ( $clean['engine'] !== $old_engine ) {
			delete_transient( 'shdt_engine_switch' );
			$engine = $this->plugin->translator()->engine( $clean['engine'] );
			$count  = $this->plugin->store()->count_other_engine( Translator::equivalent_ids( $clean['engine'] ) );
			if ( $engine && $engine->is_available() && $count > 0 ) {
				set_transient(
					'shdt_engine_switch',
					array(
						'engine' => $clean['engine'],
						'count'  => $count,
					),
					30 * DAY_IN_SECONDS
				);
			}
		}

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
	 * Redo automatic translations of other engines with the selected engine.
	 * The old texts stay online until the new ones replace them.
	 */
	public function retranslate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'shd-translator' ) );
		}
		check_admin_referer( 'shdt_retranslate' );

		$engine = $this->plugin->translator()->primary_id();
		$count  = $this->plugin->store()->mark_outdated( Translator::equivalent_ids( $engine ) );
		delete_transient( 'shdt_engine_switch' );
		if ( $count > 0 ) {
			$this->plugin->queue()->schedule( 5 );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'retranslate' => $count,
					'autorun'     => $count > 0 ? 1 : 0,
				),
				admin_url( 'admin.php?page=' . self::SLUG . '-tools' )
			)
		);
		exit;
	}

	/**
	 * Dismiss the "re-translate after switching engine" notice.
	 */
	public function keep_translations() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'shd-translator' ) );
		}
		check_admin_referer( 'shdt_keep_translations' );
		delete_transient( 'shdt_engine_switch' );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Form button posting to an admin-post action.
	 *
	 * @param string $action Action (also the nonce action).
	 * @param string $label  Button label.
	 * @param string $class  Button classes.
	 * @return string
	 */
	public static function action_button( $action, $label, $class = 'button' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="shdt-inline-form">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. wp_nonce_field( $action, '_wpnonce', true, false )
			. '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Download WordPress core language packs for the active locales.
	 */
	public function install_language_packs() {
		if ( ! current_user_can( 'install_languages' ) ) {
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
