<?php
namespace BeltoftHardening\Tests;

use BeltoftHardening\Settings;

/**
 * Defines a constant that cannot be undefined, so this class runs last (the Z prefix).
 */
final class ZHideLoginConstantTest extends HideLoginTestCase {

	public function test_wp_cli_and_rest_requests_pass_while_the_control_is_on(): void {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}
		$this->enable();
		$this->assertSame( 'pass', $this->request( 'wp-login.php' ) );
		$this->assertSame( 'pass', $this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true ) );
	}

	public function test_the_constant_switches_the_control_off_whatever_the_setting(): void {
		if ( ! defined( 'BELTOFT_HARDENING_DISABLE_HIDE_LOGIN' ) ) {
			define( 'BELTOFT_HARDENING_DISABLE_HIDE_LOGIN', true );
		}
		$this->enable();
		$this->assertTrue( Settings::hide_login_enabled() );
		$this->assertFalse( \BeltoftHardening\HideLogin::active() );
		$this->assertFalse( has_action( 'init', array( \BeltoftHardening\HideLogin::class, 'guard' ) ) );
		$this->assertSame( 'pass', $this->request( 'wp-login.php' ) );
		$this->assertSame( site_url( 'wp-login.php', 'login' ), wp_login_url() );
		$this->assertStringContainsString( 'constant', \BeltoftHardening\HideLogin::disabled_reason() );
	}
}
