<?php
/**
 * PHPUnit bootstrap. Run with: /root/plugin-tests/run.sh <path-to-this-plugin>
 *
 * @package BeltoftHardening
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$beltoft_hardening_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/tmp/wordpress-tests-lib';

require_once $beltoft_hardening_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/beltoft-hardening.php';
	}
);

require $beltoft_hardening_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
