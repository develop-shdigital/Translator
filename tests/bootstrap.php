<?php
/**
 * Minimal bootstrap for unit tests that do not need WordPress.
 *
 * @package SHDT
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'SHDT_DIR', dirname( __DIR__ ) . '/' );
define( 'SHDT_URL', 'https://example.test/wp-content/plugins/shd-translator/' );

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

// Minimal WordPress stand-ins for the classes under test.
$GLOBALS['shdt_test_options'] = array();
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number ) {
		return 1 === (int) $number ? $single : $plural;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['shdt_test_options'] ) ? $GLOBALS['shdt_test_options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		$GLOBALS['shdt_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['shdt_test_options'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( (array) $defaults, (array) $args );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

require_once SHDT_DIR . 'includes/class-autoloader.php';
SHDT\Autoloader::register();

$GLOBALS['shdt_tests'] = array( 'pass' => 0, 'fail' => 0 );

function shdt_assert( $condition, $label, $detail = '' ) {
	if ( $condition ) {
		$GLOBALS['shdt_tests']['pass']++;
		return;
	}
	$GLOBALS['shdt_tests']['fail']++;
	echo "FAIL: {$label}\n";
	if ( '' !== $detail ) {
		echo '      ' . str_replace( "\n", "\n      ", $detail ) . "\n";
	}
}

function shdt_assert_contains( $haystack, $needle, $label ) {
	shdt_assert( false !== strpos( $haystack, $needle ), $label, "missing: {$needle}" );
}

function shdt_assert_not_contains( $haystack, $needle, $label ) {
	shdt_assert( false === strpos( $haystack, $needle ), $label, "unexpected: {$needle}" );
}
