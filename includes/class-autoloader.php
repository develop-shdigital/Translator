<?php
/**
 * PSR-4 style autoloader following WordPress file naming conventions.
 *
 * SHDT\Html_Processor        => includes/class-html-processor.php
 * SHDT\Engines\Engine        => includes/engines/interface-engine.php
 * SHDT\Elementor\Switcher_Widget => includes/elementor/class-switcher-widget.php
 *
 * @package SHDT
 */

namespace SHDT;

defined( 'ABSPATH' ) || exit;

class Autoloader {

	/**
	 * Register the autoloader.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load a class file.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function load( $class ) {
		if ( 0 !== strpos( $class, 'SHDT\\' ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class, 5 ) );
		$name  = strtolower( str_replace( '_', '-', array_pop( $parts ) ) );
		$dir   = SHDT_DIR . 'includes/';

		foreach ( $parts as $part ) {
			$dir .= strtolower( str_replace( '_', '-', $part ) ) . '/';
		}

		foreach ( array( 'class-', 'interface-', 'trait-' ) as $prefix ) {
			$file = $dir . $prefix . $name . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
}
