<?php
/**
 * Shared helpers.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening\Tests;

abstract class TestCase extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( \BeltoftHardening\Settings::OPTION );
		\BeltoftHardening\LoginErrors::reset();
		unset( $GLOBALS['errors'] );
		$GLOBALS['wp_rest_server'] = null;
		$GLOBALS['pagenow']        = 'index.php';
	}

	public function tear_down(): void {
		unset( $GLOBALS['errors'] );
		$GLOBALS['wp_rest_server'] = null;
		$_GET                      = array();
		parent::tear_down();
	}

	/**
	 * Turns one control off.
	 *
	 * @param string $key Settings key.
	 */
	protected function turn_off( string $key ): void {
		update_option( \BeltoftHardening\Settings::OPTION, \BeltoftHardening\Settings::sanitize( array_fill_keys( array_diff( \BeltoftHardening\Settings::toggles(), array( $key ) ), 1 ) ) );
	}
}
