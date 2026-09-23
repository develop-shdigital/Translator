<?php
/**
 * Language switcher markup (Elementor widget, shortcode, menu, floating).
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Switcher {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Instance counter for unique IDs.
	 *
	 * @var int
	 */
	private static $count = 0;

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
		add_shortcode( 'shd_language_switcher', array( $this, 'shortcode' ) );
	}

	/**
	 * [shd_language_switcher layout="dropdown" display="code" icon="yes" arrow="yes" align="right"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'layout'       => 'dropdown',
				'display'      => $this->plugin->settings()->get( 'switcher_display', 'code' ),
				'item_display' => 'name',
				'icon'         => 'yes',
				'arrow'        => 'yes',
				'current'      => 'yes',
				'open_on'      => 'click',
				'align'        => 'right',
				'direction'    => 'down',
				'class'        => '',
			),
			$atts,
			'shd_language_switcher'
		);

		wp_enqueue_style( 'shdt-switcher' );
		wp_enqueue_script( 'shdt-switcher' );

		return self::render(
			array(
				'layout'       => $atts['layout'],
				'display'      => $atts['display'],
				'item_display' => $atts['item_display'],
				'show_icon'    => 'yes' === $atts['icon'],
				'show_arrow'   => 'yes' === $atts['arrow'],
				'show_current' => 'yes' === $atts['current'],
				'open_on'      => $atts['open_on'],
				'align'        => $atts['align'],
				'direction'    => $atts['direction'],
				'class'        => $atts['class'],
			)
		);
	}

	/**
	 * Globe icon.
	 *
	 * @return string
	 */
	public static function globe_svg() {
		return '<svg class="shdt-switcher__svg" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
	}

	/**
	 * Chevron icon.
	 *
	 * @return string
	 */
	public static function chevron_svg() {
		return '<svg class="shdt-switcher__svg" viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>';
	}

	/**
	 * Render the switcher.
	 *
	 * @param array $args {
	 *     @type string $layout       dropdown|inline.
	 *     @type string $display      Toggle content: code|name|flag|flag_code|flag_name.
	 *     @type string $item_display List items: name|code|flag_name|flag_code|flag.
	 *     @type bool   $show_icon    Globe icon on the toggle.
	 *     @type string $icon_html    Custom icon markup (replaces the globe).
	 *     @type bool   $show_arrow   Chevron on the toggle.
	 *     @type bool   $show_current Show the current language in the list.
	 *     @type string $open_on      click|hover.
	 *     @type string $align        Dropdown alignment: left|right|center.
	 *     @type string $direction    down|up.
	 *     @type string $class        Extra CSS classes.
	 *     @type string $separator    Inline layout separator.
	 * }
	 * @return string
	 */
	public static function render( array $args = array() ) {
		$plugin    = shdt();
		$languages = $plugin->languages();
		$active    = $languages->active();
		if ( count( $active ) < 2 ) {
			return '';
		}

		$args = wp_parse_args(
			$args,
			array(
				'layout'       => 'dropdown',
				'display'      => 'code',
				'item_display' => 'name',
				'show_icon'    => true,
				'icon_html'    => '',
				'show_arrow'   => true,
				'show_current' => true,
				'open_on'      => 'click',
				'align'        => 'right',
				'direction'    => 'down',
				'class'        => '',
				'separator'    => '',
			)
		);

		$current_code = $languages->current();
		$current      = $languages->get( $current_code );
		$id           = 'shdt-switcher-' . ( ++self::$count );
		$layout       = 'inline' === $args['layout'] ? 'inline' : 'dropdown';

		$classes = array(
			'shdt-switcher',
			'shdt-switcher--' . $layout,
			'shdt-switcher--align-' . sanitize_html_class( $args['align'] ),
			'shdt-switcher--' . ( 'up' === $args['direction'] ? 'up' : 'down' ),
			'notranslate',
		);
		if ( 'hover' === $args['open_on'] ) {
			$classes[] = 'shdt-switcher--hover';
		}
		if ( '' !== $args['class'] ) {
			$classes[] = $args['class'];
		}

		$items = '';
		$first = true;
		foreach ( $active as $code => $lang ) {
			$is_current = $code === $current_code;
			if ( $is_current && ! $args['show_current'] && 'dropdown' === $layout ) {
				continue;
			}
			if ( 'inline' === $layout && ! $first && '' !== $args['separator'] ) {
				$items .= '<li class="shdt-switcher__sep" aria-hidden="true">' . esc_html( $args['separator'] ) . '</li>';
			}
			$first  = false;
			$items .= sprintf(
				'<li class="shdt-switcher__li"><a class="shdt-switcher__item%1$s" href="%2$s" hreflang="%3$s" lang="%3$s" data-shdt-lang="%4$s"%5$s>%6$s</a></li>',
				$is_current ? ' is-active' : '',
				esc_url( $plugin->router()->current_url( $code ) ),
				esc_attr( $languages->hreflang( $code ) ),
				esc_attr( $code ),
				$is_current ? ' aria-current="true"' : '',
				self::label( $code, $lang, $args['item_display'] )
			);
		}

		$html = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-shdt-switcher translate="no">';

		if ( 'dropdown' === $layout ) {
			$icon = '';
			if ( $args['show_icon'] ) {
				$icon = '<span class="shdt-switcher__icon">' . ( '' !== $args['icon_html'] ? $args['icon_html'] : self::globe_svg() ) . '</span>';
			}
			$html .= sprintf(
				'<button type="button" class="shdt-switcher__toggle" aria-expanded="false" aria-controls="%1$s" aria-label="%2$s">%3$s<span class="shdt-switcher__current">%4$s</span>%5$s</button>',
				esc_attr( $id ),
				/* translators: %s: current language */
				esc_attr( sprintf( __( 'Language: %s. Choose another language', 'shd-translator' ), $current ? $current['name'] : '' ) ),
				$icon,
				self::label( $current_code, $current, $args['display'] ),
				$args['show_arrow'] ? '<span class="shdt-switcher__arrow">' . self::chevron_svg() . '</span>' : ''
			);
			$html .= '<ul class="shdt-switcher__menu" id="' . esc_attr( $id ) . '">' . $items . '</ul>';
		} else {
			$html .= '<ul class="shdt-switcher__list">' . $items . '</ul>';
		}

		$html .= '</div>';

		return apply_filters( 'shdt_switcher_html', $html, $args );
	}

	/**
	 * Label for a language (flag, code and/or name).
	 *
	 * @param string $code    Code.
	 * @param array  $lang    Language.
	 * @param string $display Display mode.
	 * @return string HTML.
	 */
	public static function label( $code, $lang, $display ) {
		if ( ! $lang ) {
			return '';
		}
		$flag = '';
		if ( false !== strpos( $display, 'flag' ) ) {
			$url = shdt()->languages()->flag_url( $code );
			if ( $url ) {
				$flag = '<img class="shdt-switcher__flag" src="' . esc_url( $url ) . '" alt="" width="20" height="15" loading="lazy" decoding="async">';
			}
		}
		switch ( $display ) {
			case 'flag':
				return $flag . '<span class="screen-reader-text shdt-sr">' . esc_html( $lang['name'] ) . '</span>';
			case 'flag_code':
				return $flag . '<span class="shdt-switcher__text">' . esc_html( $lang['label'] ) . '</span>';
			case 'flag_name':
				return $flag . '<span class="shdt-switcher__text">' . esc_html( $lang['name'] ) . '</span>';
			case 'code':
				return '<span class="shdt-switcher__text">' . esc_html( $lang['label'] ) . '</span>';
			default:
				return '<span class="shdt-switcher__text">' . esc_html( $lang['name'] ) . '</span>';
		}
	}
}
