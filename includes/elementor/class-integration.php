<?php
/**
 * Elementor integration: registers the language switcher widget.
 *
 * @package SHDT
 */

namespace SHDT\Elementor;

defined( 'ABSPATH' ) || exit;

class Integration {

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'elementor/widgets/register', array( $this, 'register' ) );
		add_action( 'elementor/widgets/widgets_registered', array( $this, 'register_legacy' ) );
		add_action( 'elementor/elements/categories_registered', array( $this, 'category' ) );
		add_action( 'elementor/preview/enqueue_styles', array( $this, 'preview_assets' ) );
	}

	/**
	 * Register the widget (Elementor 3.5+).
	 *
	 * @param \Elementor\Widgets_Manager $manager Widgets manager.
	 */
	public function register( $manager ) {
		$manager->register( new Switcher_Widget() );
	}

	/**
	 * Register the widget on Elementor versions before 3.5.
	 *
	 * @param \Elementor\Widgets_Manager $manager Widgets manager.
	 */
	public function register_legacy( $manager ) {
		if ( did_action( 'elementor/widgets/register' ) || ! method_exists( $manager, 'register_widget_type' ) ) {
			return;
		}
		$manager->register_widget_type( new Switcher_Widget() );
	}

	/**
	 * Widget category.
	 *
	 * @param \Elementor\Elements_Manager $manager Elements manager.
	 */
	public function category( $manager ) {
		$manager->add_category(
			'shd-translator',
			array(
				'title' => __( 'Translator', 'shd-translator' ),
				'icon'  => 'eicon-globe',
			)
		);
	}

	/**
	 * Make sure the switcher looks right in the editor preview.
	 */
	public function preview_assets() {
		wp_enqueue_style( 'shdt-switcher', SHDT_URL . 'assets/css/switcher.css', array(), SHDT_VERSION );
		wp_enqueue_script( 'shdt-switcher', SHDT_URL . 'assets/js/switcher.js', array(), SHDT_VERSION, true );
	}
}
