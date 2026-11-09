<?php
/**
 * The translations loader is hooked.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening\Tests;

/**
 * Bundled translations are loaded on init, front end and admin.
 */
class TextdomainTest extends \WP_UnitTestCase {

	public function test_loader_is_hooked_on_init() {
		$this->assertSame( 0, has_action( 'init', 'beltoft_hardening_load_textdomain' ) );
	}
}
