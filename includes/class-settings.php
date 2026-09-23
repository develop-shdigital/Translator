<?php
/**
 * Plugin settings storage, defaults and sanitisation.
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'shdt_settings';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private $data = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'default_language'    => '',
			'languages'           => array(),
			'url_mode'            => 'directory',
			'engine'              => 'google',
			'fallback_free'       => 1,
			'anthropic_key'       => '',
			'anthropic_model'     => 'claude-opus-5',
			'deepl_key'           => '',
			'deepl_formality'     => 'default',
			'openai_key'          => '',
			'openai_model'        => 'gpt-4o-mini',
			'openai_base'         => 'https://api.openai.com/v1',
			'libre_url'           => '',
			'libre_key'           => '',
			'mymemory_email'      => '',
			'ai_context'          => '',
			'glossary'            => '',
			'auto_translate'      => 1,
			'translate_meta'      => 1,
			'translate_attributes' => 1,
			'dynamic'             => 1,
			'search_translate'    => 1,
			'switch_locale'       => 1,
			'hreflang'            => 1,
			'browser_redirect'    => 0,
			'swiss_ss'            => 1,
			'exclude_selectors'   => '',
			'exclude_paths'       => '',
			'time_budget'         => 20,
			'floating'            => 0,
			'floating_position'   => 'bottom-right',
			'menu_location'       => '',
			'switcher_display'    => 'code',
			'delete_data'         => 0,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->data ) {
			$stored     = get_option( self::OPTION, array() );
			$this->data = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return $this->data;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Whether a boolean setting is on.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function on( $key ) {
		return ! empty( $this->all()[ $key ] );
	}

	/**
	 * Persist settings (already sanitised).
	 *
	 * @param array $values Values to merge into current settings.
	 */
	public function update( array $values ) {
		$this->data = wp_parse_args( $values, $this->all() );
		update_option( self::OPTION, $this->data, true );
	}

	/**
	 * Sanitise raw settings coming from the admin form.
	 *
	 * @param array $raw Raw POST data.
	 * @return array
	 */
	public function sanitize( array $raw ) {
		$defaults = self::defaults();
		$out      = array();
		$catalog  = Languages::catalog();

		// Languages.
		$languages = array();
		$seen      = array();
		$slugs     = array();
		if ( ! empty( $raw['languages'] ) && is_array( $raw['languages'] ) ) {
			foreach ( $raw['languages'] as $row ) {
				$code = isset( $row['code'] ) ? sanitize_text_field( wp_unslash( $row['code'] ) ) : '';
				if ( ! isset( $catalog[ $code ] ) || isset( $seen[ $code ] ) ) {
					continue;
				}
				$seen[ $code ] = true;
				$base          = $catalog[ $code ];
				$slug          = isset( $row['slug'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', wp_unslash( $row['slug'] ) ) : '';
				if ( '' === $slug ) {
					$slug = strtolower( $code );
				}
				// Slugs must stay unique.
				while ( isset( $slugs[ strtolower( $slug ) ] ) ) {
					$slug .= '-' . strtolower( $code );
				}
				$slugs[ strtolower( $slug ) ] = true;

				$locale = isset( $row['locale'] ) ? preg_replace( '/[^A-Za-z_]/', '', wp_unslash( $row['locale'] ) ) : '';
				$flag   = isset( $row['flag'] ) ? preg_replace( '/[^a-z\-]/', '', strtolower( wp_unslash( $row['flag'] ) ) ) : '';
				$name   = isset( $row['name'] ) ? sanitize_text_field( wp_unslash( $row['name'] ) ) : '';
				$label  = isset( $row['label'] ) ? sanitize_text_field( wp_unslash( $row['label'] ) ) : '';

				$languages[] = array(
					'code'   => $code,
					'locale' => '' !== $locale ? $locale : $base['locale'],
					'name'   => '' !== $name ? $name : $base['native'],
					'label'  => '' !== $label ? $label : strtoupper( substr( $code, 0, 2 ) ),
					'slug'   => $slug,
					'flag'   => '' !== $flag ? $flag : $base['flag'],
				);
			}
		}
		$out['languages'] = $languages;

		$default = isset( $raw['default_language'] ) ? sanitize_text_field( wp_unslash( $raw['default_language'] ) ) : '';
		if ( ! isset( $seen[ $default ] ) ) {
			if ( isset( $catalog[ $default ] ) ) {
				// The original language must always be part of the list.
				array_unshift( $languages, Languages::make_entry( $default ) );
			} else {
				$default = $languages ? $languages[0]['code'] : $this->get( 'default_language' );
			}
		}
		$out['languages']        = $languages;
		$out['default_language'] = $default;

		$choices = array(
			'url_mode'          => array( 'directory', 'query' ),
			'engine'            => array_keys( Translator::engine_classes() ),
			'deepl_formality'   => array( 'default', 'more', 'less', 'prefer_more', 'prefer_less' ),
			'floating_position' => array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' ),
			'switcher_display'  => array( 'code', 'name', 'flag', 'flag_code', 'flag_name' ),
		);
		foreach ( $choices as $key => $allowed ) {
			$value       = isset( $raw[ $key ] ) ? sanitize_key( wp_unslash( $raw[ $key ] ) ) : $defaults[ $key ];
			$out[ $key ] = in_array( $value, $allowed, true ) ? $value : $defaults[ $key ];
		}

		$booleans = array(
			'fallback_free',
			'auto_translate',
			'translate_meta',
			'translate_attributes',
			'dynamic',
			'search_translate',
			'switch_locale',
			'hreflang',
			'browser_redirect',
			'swiss_ss',
			'floating',
			'delete_data',
		);
		foreach ( $booleans as $key ) {
			$out[ $key ] = empty( $raw[ $key ] ) ? 0 : 1;
		}

		$texts = array( 'anthropic_key', 'anthropic_model', 'deepl_key', 'openai_key', 'openai_model', 'libre_key', 'menu_location' );
		foreach ( $texts as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $raw[ $key ] ) ) ) : $defaults[ $key ];
		}
		if ( '' === $out['anthropic_model'] ) {
			$out['anthropic_model'] = $defaults['anthropic_model'];
		}

		$out['openai_base']    = isset( $raw['openai_base'] ) ? untrailingslashit( esc_url_raw( trim( wp_unslash( $raw['openai_base'] ) ) ) ) : $defaults['openai_base'];
		// A pasted endpoint ("…/v1/chat/completions") instead of the base URL.
		$out['openai_base'] = untrailingslashit( (string) preg_replace( '~/(?:chat/)?completions/?$~i', '', $out['openai_base'] ) );
		if ( '' === $out['openai_base'] ) {
			$out['openai_base'] = $defaults['openai_base'];
		}
		$out['libre_url']      = isset( $raw['libre_url'] ) ? untrailingslashit( esc_url_raw( trim( wp_unslash( $raw['libre_url'] ) ) ) ) : '';
		$out['mymemory_email'] = isset( $raw['mymemory_email'] ) ? sanitize_email( wp_unslash( $raw['mymemory_email'] ) ) : '';

		$textareas = array( 'ai_context', 'glossary', 'exclude_selectors', 'exclude_paths' );
		foreach ( $textareas as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) ? sanitize_textarea_field( wp_unslash( $raw[ $key ] ) ) : '';
		}

		$budget             = isset( $raw['time_budget'] ) ? absint( $raw['time_budget'] ) : $defaults['time_budget'];
		$out['time_budget'] = max( 0, min( 120, $budget ) );

		return $out;
	}

	/**
	 * Split a textarea setting into clean lines.
	 *
	 * @param string $key          Setting key.
	 * @param bool   $split_commas Also split on commas (CSS selector lists).
	 * @return string[]
	 */
	public function lines( $key, $split_commas = false ) {
		$value = (string) $this->get( $key, '' );
		$lines = preg_split( $split_commas ? '/[\r\n,]+/' : '/[\r\n]+/', $value );
		$lines = array_map( 'trim', (array) $lines );
		return array_values( array_filter( $lines, 'strlen' ) );
	}
}
