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
