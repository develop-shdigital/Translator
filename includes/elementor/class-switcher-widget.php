<?php
/**
 * Elementor widget: Language Switcher.
 *
 * Style controls write CSS custom properties on the switcher, so the widget
 * stays in sync with the stylesheet and theme button styles cannot leak in.
 *
 * @package SHDT
 */

namespace SHDT\Elementor;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Icons_Manager;
use Elementor\Widget_Base;
use SHDT\Switcher;

defined( 'ABSPATH' ) || exit;

class Switcher_Widget extends Widget_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'shdt-language-switcher';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title() {
		return __( 'Language Switcher', 'shd-translator' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_icon() {
		return 'eicon-globe';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_categories() {
		return array( 'shd-translator', 'general' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_keywords() {
		return array( 'language', 'switcher', 'translate', 'multilingual', 'sprache', 'langue', 'lingua', 'flag' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_style_depends() {
		return array( 'shdt-switcher' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_script_depends() {
		return array( 'shdt-switcher' );
	}

	/**
	 * The output depends on the current URL and language: never let Elementor's
	 * element cache reuse it on another page or in another language.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function register_controls() {
		$this->content_controls();
		$this->toggle_style_controls();
		$this->menu_style_controls();
		$this->item_style_controls();
	}

	/**
	 * Content tab.
	 */
	private function content_controls() {
		$this->start_controls_section(
			'section_switcher',
			array(
				'label' => __( 'Language Switcher', 'shd-translator' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$languages = shdt()->languages();
		if ( ! $languages->has_targets() ) {
			$this->add_control(
				'no_languages',
				array(
					'type'            => Controls_Manager::RAW_HTML,
					'raw'             => sprintf(
						/* translators: %s: settings URL */
						__( 'Add at least one language under <a href="%s" target="_blank">Translator → Languages</a>.', 'shd-translator' ),
						esc_url( admin_url( 'admin.php?page=shd-translator' ) )
					),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
				)
			);
		}

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'shd-translator' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'dropdown',
				'options' => array(
					'dropdown' => __( 'Dropdown', 'shd-translator' ),
					'inline'   => __( 'Inline list', 'shd-translator' ),
				),
			)
		);

		$labels = array(
			'code'      => __( 'Code (EN)', 'shd-translator' ),
			'name'      => __( 'Name (English (UK))', 'shd-translator' ),
			'flag_code' => __( 'Flag + code', 'shd-translator' ),
			'flag_name' => __( 'Flag + name', 'shd-translator' ),
			'flag'      => __( 'Flag only', 'shd-translator' ),
		);

		$this->add_control(
			'display',
			array(
				'label'     => __( 'Button shows', 'shd-translator' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'code',
				'options'   => $labels,
				'condition' => array( 'layout' => 'dropdown' ),
			)
		);

		$this->add_control(
			'item_display',
			array(
				'label'   => __( 'List shows', 'shd-translator' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'name',
				'options' => $labels,
			)
		);

		$this->add_control(
			'show_icon',
			array(
				'label'        => __( 'Globe icon', 'shd-translator' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'layout' => 'dropdown' ),
			)
		);

		$this->add_control(
			'icon',
			array(
				'label'       => __( 'Custom icon', 'shd-translator' ),
				'type'        => Controls_Manager::ICONS,
				'skin'        => 'inline',
				'label_block' => false,
				'description' => __( 'Leave empty for the built-in globe.', 'shd-translator' ),
				'condition'   => array(
					'layout'    => 'dropdown',
					'show_icon' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_arrow',
			array(
				'label'        => __( 'Arrow', 'shd-translator' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'layout' => 'dropdown' ),
			)
		);

		$this->add_control(
			'show_current',
			array(
				'label'        => __( 'Current language in list', 'shd-translator' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'layout' => 'dropdown' ),
			)
		);

		$this->add_control(
			'open_on',
			array(
				'label'     => __( 'Open on', 'shd-translator' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'click',
				'options'   => array(
					'click' => __( 'Click', 'shd-translator' ),
					'hover' => __( 'Hover', 'shd-translator' ),
				),
				'condition' => array( 'layout' => 'dropdown' ),
			)
		);

		$this->add_control(
			'dropdown_align',
			array(
				'label'     => __( 'Dropdown position', 'shd-translator' ),
				'type'      => Controls_Manager::CHOOSE,
				'default'   => 'right',
				'toggle'    => false,
				'options'   => array(
					'left'   => array(
						'title' => __( 'Left', 'shd-translator' ),
						'icon'  => 'eicon-h-align-left',
					),
					'center' => array(
						'title' => __( 'Center', 'shd-translator' ),
						'icon'  => 'eicon-h-align-center',
					),
					'right'  => array(
						'title' => __( 'Right', 'shd-translator' ),
						'icon'  => 'eicon-h-align-right',
					),
				),
				'condition' => array( 'layout' => 'dropdown' ),
			)
		);

		$this->add_control(
			'direction',
			array(
				'label'     => __( 'Open towards', 'shd-translator' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'down',
				'options'   => array(
					'down' => __( 'Down', 'shd-translator' ),
					'up'   => __( 'Up', 'shd-translator' ),
				),
				'condition' => array( 'layout' => 'dropdown' ),
			)
		);

		$this->add_control(
			'separator',
			array(
				'label'     => __( 'Separator', 'shd-translator' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => '',
				'condition' => array( 'layout' => 'inline' ),
			)
		);

		$this->add_responsive_control(
			'align',
			array(
				'label'     => __( 'Alignment', 'shd-translator' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => __( 'Left', 'shd-translator' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center'     => array(
						'title' => __( 'Center', 'shd-translator' ),
						'icon'  => 'eicon-text-align-center',
					),
					'flex-end'   => array(
						'title' => __( 'Right', 'shd-translator' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array(
					'{{WRAPPER}} .shdt-widget' => 'justify-content: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Toggle (button) styles.
	 */
	private function toggle_style_controls() {
		$this->start_controls_section(
			'section_toggle_style',
			array(
				'label'     => __( 'Button', 'shd-translator' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'layout' => 'dropdown' ),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'toggle_typography',
				'selector' => '{{WRAPPER}} .shdt-switcher .shdt-switcher__toggle',
			)
		);

		$this->start_controls_tabs( 'toggle_tabs' );

		$this->start_controls_tab( 'toggle_normal', array( 'label' => __( 'Normal', 'shd-translator' ) ) );
		$this->add_control(
			'toggle_color',
			array(
				'label'     => __( 'Text color', 'shd-translator' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .shdt-switcher' => '--shdt-toggle-color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'toggle_bg',
			array(
				'label'     => __( 'Background', 'shd-translator' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .shdt-switcher' => '--shdt-toggle-bg: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'toggle_border_color',
			array(
				'label'     => __( 'Border color', 'shd-translator' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .shdt-switcher' => '--shdt-toggle-border: {{VALUE}};' ),
			)
		);
		$this->end_controls_tab();

		$this->start_controls_tab( 'toggle_hover', array( 'label' => __( 'Hover', 'shd-translator' ) ) );
		$this->add_control(
			'toggle_hover_color',
			array(
				'label'     => __( 'Text color', 'shd-translator' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .shdt-switcher' => '--shdt-toggle-hover-color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'toggle_hover_bg',
			array(
				'label'     => __( 'Background', 'shd-translator' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .shdt-switcher' => '--shdt-toggle-hover-bg: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'toggle_hover_border_color',
			array(
				'label'     => __( 'Border color', 'shd-translator' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .shdt-switcher' => '--shdt-toggle-hover-border: {{VALUE}};' ),
			)
		);
		$this->end_controls_tab();
		$this->end_controls_tabs();

		$this->add_control(
			'toggle_border_width',
			array(
				'label'      => __( 'Border width', 'shd-translator' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 6,
					),
				),
				'separator'  => 'before',
				'selectors'  => array( '{{WRAPPER}} .shdt-switcher .shdt-switcher__toggle' => 'border-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'toggle_radius',
			array(
				'label'      => __( 'Border radius', 'shd-translator' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .shdt-switcher .shdt-switcher__toggle' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'toggle_padding',
			array(
				'label'      => __( 'Padding', 'shd-translator' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .shdt-switcher .shdt-switcher__toggle' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'toggle_shadow',
				'selector' => '{{WRAPPER}} .shdt-switcher .shdt-switcher__toggle',
			)
		);

		$this->add_responsive_control(
			'toggle_gap',
			array(
				'label'      => __( 'Spacing', 'shd-translator' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 30,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .shdt-switcher .shdt-switcher__toggle' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'icon_size',
			array(
				'label'      => __( 'Icon size', 'shd-translator' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 8,
						'max' => 48,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .shdt-switcher__icon' => 'font-size: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'icon_color',
			array(
				'label'     => __( 'Icon color', 'shd-translator' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .shdt-switcher__icon' => 'color: {{VALUE}};',
					'{{WRAPPER}} .shdt-switcher__icon svg:not(.shdt-switcher__svg)' => 'fill: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'arrow_size',
			array(
				'label'      => __( 'Arrow size', 'shd-translator' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 6,
						'max' => 32,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .shdt-switcher__arrow' => 'font-size: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Dropdown panel styles.
	 */
	private function menu_style_controls() {
		$this->start_controls_section(
			'section_menu_style',
			array(
				'label'     => __( 'Dropdown', 'shd-translator' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'layout' => 'dropdown' ),
			)
		);

		$this->add_control(
			'menu_bg',
			array(
				'label'     => __( 'Background', 'shd-translator' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .shdt-switcher' => '--shdt-menu-bg: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'menu_border',
				'selector' => '{{WRAPPER}} .shdt-switcher .shdt-switcher__menu',
			)
		);

		$this->add_responsive_control(
			'menu_radius',
			array(
				'label'      => __( 'Border radius', 'shd-translator' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .shdt-switcher .shdt-switcher__menu' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'menu_shadow',
				'selector' => '{{WRAPPER}} .shdt-switcher .shdt-switcher__menu',
			)
		);

		$this->add_responsive_control(
			'menu_padding',
			array(
				'label'      => __( 'Padding', 'shd-translator' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .shdt-switcher .shdt-switcher__menu' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'menu_width',
			array(
				'label'      => __( 'Min width', 'shd-translator' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 80,
						'max' => 400,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .shdt-switcher .shdt-switcher__menu' => 'min-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'menu_offset',
			array(
				'label'      => __( 'Distance', 'shd-translator' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .shdt-switcher' => '--shdt-offset: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * List item styles.
	 */
	private function item_style_controls() {
		$this->start_controls_section(
			'section_item_style',
			array(
				'label' => __( 'Languages', 'shd-translator' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'item_typography',
				'selector' => '{{WRAPPER}} .shdt-switcher .shdt-switcher__item',
			)
		);

		$this->add_control(
			'item_color',
			array(
				'label'     => __( 'Text color', 'shd-translator' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .shdt-switcher' => '--shdt-item-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'item_hover_color',
			array(
				'label'     => __( 'Hover text color', 'shd-translator' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .shdt-switcher' => '--shdt-item-hover-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'item_hover_bg',
			array(
				'label'     => __( 'Hover background', 'shd-translator' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .shdt-switcher' => '--shdt-item-hover-bg: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'active_color',
			array(
				'label'     => __( 'Current language color', 'shd-translator' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .shdt-switcher' => '--shdt-active-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'active_weight',
			array(
				'label'     => __( 'Current language weight', 'shd-translator' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => array(
					''    => __( 'Default (bold)', 'shd-translator' ),
					'400' => __( 'Normal', 'shd-translator' ),
					'500' => '500',
					'600' => '600',
					'700' => '700',
					'800' => '800',
				),
				'selectors' => array( '{{WRAPPER}} .shdt-switcher .shdt-switcher__item.is-active' => 'font-weight: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'item_padding',
			array(
				'label'      => __( 'Padding', 'shd-translator' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'separator'  => 'before',
				'selectors'  => array( '{{WRAPPER}} .shdt-switcher .shdt-switcher__item' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'item_radius',
			array(
				'label'      => __( 'Border radius', 'shd-translator' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 30,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .shdt-switcher .shdt-switcher__item' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'flag_width',
			array(
				'label'      => __( 'Flag width', 'shd-translator' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 12,
						'max' => 48,
					),
				),
				'selectors'  => array( '{{WRAPPER}} .shdt-switcher__flag' => 'width: {{SIZE}}{{UNIT}}; height: calc({{SIZE}}{{UNIT}} * .75);' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$icon_html = '';
		if ( ! empty( $settings['icon']['value'] ) && class_exists( Icons_Manager::class ) ) {
			ob_start();
			Icons_Manager::render_icon( $settings['icon'], array( 'aria-hidden' => 'true' ) );
			$icon_html = (string) ob_get_clean();
		}

		$html = Switcher::render(
			array(
				'layout'       => isset( $settings['layout'] ) ? $settings['layout'] : 'dropdown',
				'display'      => isset( $settings['display'] ) ? $settings['display'] : 'code',
				'item_display' => isset( $settings['item_display'] ) ? $settings['item_display'] : 'name',
				'show_icon'    => isset( $settings['show_icon'] ) && 'yes' === $settings['show_icon'],
				'icon_html'    => $icon_html,
				'show_arrow'   => isset( $settings['show_arrow'] ) && 'yes' === $settings['show_arrow'],
				'show_current' => ! isset( $settings['show_current'] ) || 'yes' === $settings['show_current'],
				'open_on'      => isset( $settings['open_on'] ) ? $settings['open_on'] : 'click',
				'align'        => isset( $settings['dropdown_align'] ) ? $settings['dropdown_align'] : 'right',
				'direction'    => isset( $settings['direction'] ) ? $settings['direction'] : 'down',
				'separator'    => isset( $settings['separator'] ) ? $settings['separator'] : '',
			)
		);

		if ( '' === $html && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			$html = '<div class="elementor-alert elementor-alert-info">' . esc_html__( 'Language switcher: add languages under Translator → Languages.', 'shd-translator' ) . '</div>';
		}

		echo '<div class="shdt-widget" style="display:flex">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
