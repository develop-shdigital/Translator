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
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * Upgrades and first-run redirect.
	 */
	public function admin_init() {
		Installer::maybe_install();

		// Safety net: waiting texts but no queue run planned (lost cron event,
		// restored database): plan one. Checked at most once an hour.
		if ( ! wp_doing_ajax() && ! get_transient( 'shdt_queue_check' ) ) {
			set_transient( 'shdt_queue_check', 1, HOUR_IN_SECONDS );
			if ( ! wp_next_scheduled( \SHDT\Queue::HOOK ) && $this->plugin->store()->count_pending() > 0 ) {
				$this->plugin->queue()->schedule( 60 );
			}
		}

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
				'engine'   => $this->plugin->translator()->primary_id(),
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
					'paused'        => 'google' === $this->plugin->translator()->primary_id()
						? __( 'Google Translate paused (it limits busy servers). Try "Translate with my browser" below or wait a few minutes.', 'shd-translator' )
						: __( 'The selected engine is paused – the message at the top of the page says why.', 'shd-translator' ),
					'saveFirst'     => __( 'Save the settings first: the test uses the saved engine and key.', 'shd-translator' ),
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
	 * Say on the plugins screen what deleting the plugin does with the data.
	 *
	 * @param array  $meta Row meta.
	 * @param string $file Plugin file.
	 * @return array
	 */
	public function row_meta( $meta, $file ) {
		if ( SHDT_BASENAME === $file && current_user_can( 'manage_options' ) ) {
			$meta[] = $this->plugin->settings()->on( 'delete_data' )
				? esc_html__( 'Deleting removes all translations and settings.', 'shd-translator' )
				: esc_html__( 'Deleting keeps translations and settings (see "Delete all translations and settings" in the settings).', 'shd-translator' );
		}
		return $meta;
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
		$ours   = $screen && false !== strpos( (string) $screen->id, self::SLUG );
		$status = Engine_Status::get( $this->plugin );

		// Whenever the selected engine is not the one translating, say so: on every
		// screen for engines the site owner set up, on ours for the free Google engine
		// (busy servers get short Google pauses that need no action).
		if ( $ours || 'google' !== $status['id'] ) {
			$this->engine_notice( $status );
		}

		if ( 'google' === $status['id'] && ! get_option( 'shdt_settings_saved' ) && ( $ours || ( $screen && in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) ) ) {
			printf(
				'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'SHD Translator translates your site with the free Google Translate engine. For more natural texts, choose Claude, DeepL or an OpenAI-compatible API and save.', 'shd-translator' ),
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '#shdt-engine' ) ),
				esc_html__( 'Choose the engine', 'shd-translator' )
			);
		}

		if ( ! $ours ) {
			return;
		}

		$dropped = get_transient( 'shdt_key_dropped' );
		if ( is_array( $dropped ) && ! empty( $dropped['host'] ) ) {
			delete_transient( 'shdt_key_dropped' );
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: server name, e.g. api.example.com */
						__( 'The saved API key was removed because the API address changed to %s. Enter the key for that server and save again.', 'shd-translator' ),
						$dropped['host']
					)
				)
			);
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

		// Translations by other engines that nothing will redo on its own: offer it.
		$translator = $this->plugin->translator();
		$engine     = $translator->engine( $status['id'] );
		$count      = $engine && $engine->is_available() ? $this->plugin->store()->count_other_engine( Translator::equivalent_ids( $status['id'] ) ) : 0;
		if ( $count > 0 && get_option( 'shdt_retranslate_dismissed' ) !== $status['id'] ) {
			echo '<div class="notice notice-info shdt-switch-notice"><p><strong>';
			echo esc_html(
				sprintf(
					/* translators: 1: number of texts, 2: engine name */
					_n( '%1$s text on your site was translated by another engine than %2$s.', '%1$s texts on your site were translated by other engines than %2$s.', $count, 'shd-translator' ),
					number_format_i18n( $count ),
					$status['label']
				)
			);
			echo '</strong> ' . esc_html__( 'Stored translations are reused, so they stay as they are unless you re-translate them. The current texts remain online until the new ones are ready; your manual edits are kept.', 'shd-translator' ) . '</p><p>';
			/* translators: %s: engine name */
			echo self::action_button( 'shdt_retranslate', sprintf( __( 'Re-translate them with %s', 'shd-translator' ), $status['label'] ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in action_button().
			echo ' ' . self::action_button( 'shdt_keep_translations', __( 'Keep the current translations', 'shd-translator' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in action_button().
			echo '</p></div>';
		} elseif ( Engine_Status::OK === $status['state'] && $status['counts']['outdated'] > 0 ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: number of texts, 2: engine names, 3: selected engine */
						_n( '%1$s text currently comes from %2$s and is being re-translated with %3$s in the background.', '%1$s texts currently come from %2$s and are being re-translated with %3$s in the background.', $status['counts']['outdated'], 'shd-translator' ),
						number_format_i18n( $status['counts']['outdated'] ),
						$status['fallback'],
						$status['label']
					)
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
	 * The selected engine is not set up, paused or failing: explain what happens
	 * instead (which engine translates, how many texts) and how to fix it.
	 *
	 * @param array $status Engine_Status::get().
	 */
	private function engine_notice( array $status ) {
		if ( ! Engine_Status::needs_attention( $status ) ) {
			return;
		}

		switch ( $status['state'] ) {
			case Engine_Status::MISSING:
				$title  = $status['missing']
					/* translators: 1: engine name, 2: missing settings, e.g. "API key" */
					? sprintf( __( 'SHD Translator: %1$s is selected but not set up yet (%2$s missing).', 'shd-translator' ), $status['label'], implode( ', ', $status['missing'] ) )
					/* translators: %s: engine name */
					: sprintf( __( 'SHD Translator: %s is selected but not set up yet.', 'shd-translator' ), $status['label'] );
				$reason = '';
				break;
			case Engine_Status::PAUSED:
				$title  = sprintf(
					/* translators: 1: engine name, 2: language in brackets or empty, 3: time */
					__( 'SHD Translator: %1$s%2$s is paused for %3$s:', 'shd-translator' ),
					$status['label'],
					null === $status['pause']['lang'] ? '' : ' (' . $status['pause']['lang'] . ')',
					human_time_diff( time(), max( time() + 60, $status['pause']['until'] ) )
				);
				$reason = $status['pause']['message'];
				break;
			default:
				$title  = sprintf(
					/* translators: 1: engine name, 2: time, e.g. "5 minutes" */
					__( 'SHD Translator: %1$s failed %2$s ago:', 'shd-translator' ),
					$status['label'],
					human_time_diff( (int) $status['failure']['time'], time() )
				);
				$reason = $status['failure']['message'];
		}

		$shown = $status['counts']['outdated'] + $status['counts']['stuck'] + $status['counts']['auto'];
		if ( $status['fallback_on'] ) {
			$what = $shown > 0
				? sprintf(
					/* translators: 1: engine names, 2: number of texts, 3: selected engine */
					_n( 'Until it works, %1$s translates your site instead (%2$s text so far); those texts are redone with %3$s automatically afterwards.', 'Until it works, %1$s translates your site instead (%2$s texts so far); those texts are redone with %3$s automatically afterwards.', $shown, 'shd-translator' ),
					$status['fallback'],
					number_format_i18n( $shown ),
					$status['label']
				)
				: sprintf(
					/* translators: 1: engine names, 2: selected engine */
					__( 'Until it works, %1$s translates new texts instead; they are redone with %2$s automatically afterwards.', 'shd-translator' ),
					$status['fallback'],
					$status['label']
				);
		} else {
			$what = __( 'Until it works, new texts stay in the original language ("Fall back to the free engines" is off).', 'shd-translator' );
		}

		$fix = Engine_Status::MISSING === $status['state']
			? __( 'Set it up', 'shd-translator' )
			: __( 'Fix the cause, then click "Test the saved engine"', 'shd-translator' );

		printf(
			'<div class="notice notice-error shdt-engine-notice"><p><strong>%s</strong> %s</p><p>%s <a href="%s">%s</a></p></div>',
			esc_html( $title ),
			esc_html( $reason ),
			esc_html( $what ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG . '#shdt-engine' ) ),
			esc_html( $fix )
		);
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

		// Keep stored API keys when the field is left untouched (they are shown masked),
		// but never send a stored key to a different server than the one it was saved for.
		$servers = array(
			'openai_key' => 'openai_base',
			'libre_key'  => 'libre_url',
		);
		foreach ( array( 'anthropic_key', 'deepl_key', 'openai_key', 'libre_key' ) as $key ) {
			if ( ! isset( $clean[ $key ] ) ) {
				continue;
			}
			$stored        = (string) $settings->get( $key );
			$untouched     = '' !== trim( (string) $clean[ $key ] ) && '' === preg_replace( '/^(?:\x{2022}|\*)+/u', '', trim( (string) $clean[ $key ] ) );
			$clean[ $key ] = self::normalize_key( $clean[ $key ], $stored );
			if ( $untouched && '' !== $stored && isset( $servers[ $key ], $clean[ $servers[ $key ] ] ) && ! self::same_server( $clean[ $servers[ $key ] ], (string) $settings->get( $servers[ $key ] ) ) ) {
				$clean[ $key ] = '';
				set_transient( 'shdt_key_dropped', array( 'host' => (string) wp_parse_url( $clean[ $servers[ $key ] ], PHP_URL_HOST ) ), HOUR_IN_SECONDS );
			}
		}

		$settings->update( $clean );
		update_option( 'shdt_settings_saved', 1, false );
		$this->plugin->languages()->flush();

		$selectors = new \SHDT\Selector( $settings->lines( 'exclude_selectors' ) );
		if ( $selectors->invalid() ) {
			set_transient( 'shdt_invalid_selectors', $selectors->invalid(), HOUR_IN_SECONDS );
		} else {
			delete_transient( 'shdt_invalid_selectors' );
		}
		// New settings: forget pauses and give failed texts another try with them.
		$translator = $this->plugin->translator();
		$translator->resume_all();
		$primary = $translator->engine( $translator->primary_id() );
		if ( $primary && $primary->is_available() ) {
			$translator->recovered();
		}
		delete_transient( \SHDT\Store::FALLBACK_COUNTS );

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
	 * The key to store from what was submitted in a (masked) key field.
	 *
	 * Only mask dots: unchanged. Dots followed by text (typed or pasted after the
	 * mask): the text. Whitespace, line breaks and a leading "Bearer " are removed.
	 * An empty field removes the key.
	 *
	 * @param string $raw    Submitted value.
	 * @param string $stored Stored key.
	 * @return string
	 */
	public static function normalize_key( $raw, $stored ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$rest = preg_replace( '/^(?:\x{2022}|\*)+/u', '', $raw );
		if ( '' === $rest ) {
			return (string) $stored; // Untouched mask.
		}
		$key = preg_replace( '/^Bearer\s+/i', '', trim( (string) $rest ) );
		return (string) preg_replace( '/\s+/u', '', (string) $key );
	}

	/**
	 * Whether two API addresses point to the same server (scheme, host and port;
	 * the path and letter case do not matter).
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 * @return bool
	 */
	public static function same_server( $a, $b ) {
		$origin = function ( $url ) {
			$parts = wp_parse_url( trim( (string) $url ) );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return '';
			}
			$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
			$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'http' === $scheme ? 80 : 443 );
			return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
		};
		return $origin( $a ) === $origin( $b );
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
		$usable = $this->plugin->translator()->engine( $engine );
		$count  = $usable && $usable->is_available() ? $this->plugin->store()->mark_outdated( Translator::equivalent_ids( $engine ) ) : 0;
		if ( $count > 0 ) {
			$this->plugin->queue()->schedule( 5, true );
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
		update_option( 'shdt_retranslate_dismissed', $this->plugin->translator()->primary_id(), false );
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
