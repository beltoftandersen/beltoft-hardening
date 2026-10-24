<?php
namespace BeltoftHardening\Tests;

/**
 * Defines constants that cannot be undefined, so this class is named to run last.
 * Declaration order matters: WP_CLI first, then REST_REQUEST.
 */
final class ZContextTest extends TestCase {

	private function admin(): array {
		$login = 'z' . wp_generate_password( 6, false );
		self::factory()->user->create( array( 'user_login' => $login, 'user_pass' => 'pw-12345', 'role' => 'administrator' ) );
		return array( $login, 'pw-12345' );
	}

	public function test_a_refused_before_constants(): void {
		list( $l, $p ) = $this->admin();
		$this->assertWPError( wp_authenticate( $l, $p ) );
	}

	public function test_wp_cli_passes(): void {
		$this->assertFalse( defined( 'REST_REQUEST' ) );
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}
		list( $l, $p ) = $this->admin();
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( $l, $p ) );
	}

	public function test_rest_application_password_passes(): void {
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}
		list( $l, $p ) = $this->admin();
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( $l, $p ) );
	}
}
