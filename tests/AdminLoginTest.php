<?php
namespace BeltoftHardening\Tests;

use BeltoftHardening\Settings;

final class AdminLoginTest extends TestCase {

	private function user( string $role ): string {
		$login = 'u' . wp_generate_password( 6, false );
		self::factory()->user->create( array( 'user_login' => $login, 'user_pass' => 'pw-12345', 'role' => $role ) );
		return $login;
	}

	public function test_admin_password_login_outside_wp_login_is_refused(): void {
		$result = wp_authenticate( $this->user( 'administrator' ), 'pw-12345' );
		$this->assertWPError( $result );
		$this->assertSame( 'beltoft_hardening_admin_login_location', $result->get_error_code() );
		$this->assertStringContainsString( '<a href="', $result->get_error_message() );
		$this->assertStringContainsString( 'Administrators sign in at the', $result->get_error_message() );
	}

	public function test_refusal_is_not_flattened(): void {
		$result = wp_authenticate( $this->user( 'administrator' ), 'pw-12345' );
		do_action( 'wp_login_failed', 'x', $result );
		$GLOBALS['errors'] = $result;
		$this->assertSame( 'KEEP', apply_filters( 'login_errors', 'KEEP' ) );
	}

	public function test_admin_at_wp_login_passes(): void {
		$GLOBALS['pagenow'] = 'wp-login.php';
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( $this->user( 'administrator' ), 'pw-12345' ) );
	}

	public function test_customer_passes(): void {
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( $this->user( 'subscriber' ), 'pw-12345' ) );
	}

	public function test_cookie_auth_empty_password_passes(): void {
		$user = get_user_by( 'id', self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( $user, \BeltoftHardening\AdminLogin::authenticate( $user, '', '' ) );
	}

	public function test_wrong_password_is_not_ours(): void {
		$result = wp_authenticate( $this->user( 'administrator' ), 'nope' );
		$this->assertSame( 'incorrect_password', $result->get_error_code() );
	}

	public function test_setting_off(): void {
		$this->turn_off( Settings::ADMIN_LOGIN );
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( $this->user( 'administrator' ), 'pw-12345' ) );
	}

	public function test_capability_filter(): void {
		add_filter( 'beltoft_hardening_admin_capability', static fn() => 'edit_posts' );
		$this->assertWPError( wp_authenticate( $this->user( 'editor' ), 'pw-12345' ) );
	}

	public function test_allowed_filter(): void {
		add_filter( 'beltoft_hardening_admin_login_allowed', '__return_true' );
		$this->assertInstanceOf( \WP_User::class, wp_authenticate( $this->user( 'administrator' ), 'pw-12345' ) );
	}
}
