<?php
/**
 * Run all unit tests:  php tests/run.php
 *
 * @package SHDT
 */

require __DIR__ . '/bootstrap.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require $file;
}

$results = $GLOBALS['shdt_tests'];
printf( "\n%d passed, %d failed\n", $results['pass'], $results['fail'] );
exit( $results['fail'] ? 1 : 0 );
