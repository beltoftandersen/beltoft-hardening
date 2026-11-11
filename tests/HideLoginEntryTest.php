<?php
namespace BeltoftHardening\Tests;

use BeltoftHardening\EntryCookie;
use BeltoftHardening\Settings;

final class HideLoginEntryTest extends HideLoginTestCase {

	public function test_entry_address_sets_the_cookie_and_redirects_to_the_login(): void {
		$this->enable();
		foreach ( array( '/staff', '/staff/' ) as $path ) {
			$this->seen['cookies'] = array();
			$this->assertSame( 'redirect', $this->request( 'index.php', array(), array(), 'GET', $path ), $path );
			$this->assertSame( site_url( 'wp-login.php', 'login' ), $this->seen['location'] );
			$this->assertSame( 302, $this->seen['redirect_status'] );
			$cookie = $this->seen['cookies'][ EntryCookie::NAME ];
			$this->assertTrue( EntryCookie::check( $cookie['value'] ) );
			$this->assertTrue( $cookie['args']['httponly'] );
			$this->assertSame( 'Lax', $cookie['args']['samesite'] );
			$this->assertFalse( $cookie['args']['secure'] );
			$this->assertSame( '/', $cookie['args']['path'] );
			$this->assertEqualsWithDelta( time() + 900, $cookie['args']['expires'], 5 );
		}
	}

	public function test_entry_cookie_is_secure_on_https(): void {
		$this->enable();
		$_SERVER['HTTPS'] = 'on';
		$this->request( 'index.php', array(), array(), 'GET', '/staff' );
		unset( $_SERVER['HTTPS'] );
		$this->assertTrue( $this->seen['cookies'][ EntryCookie::NAME ]['args']['secure'] );
	}

	public function test_entry_keeps_a_same_site_redirect_to_and_drops_a_foreign_one(): void {
		$this->enable();
		$this->request( 'index.php', array( 'redirect_to' => home_url( '/wp-admin/plugins.php' ) ), array(), 'GET', '/staff' );
		$this->assertStringContainsString( 'redirect_to=', $this->seen['location'] );
		$this->assertStringContainsString( rawurlencode( home_url( '/wp-admin/plugins.php' ) ), $this->seen['location'] );

		$this->request( 'index.php', array( 'redirect_to' => 'https://evil.example/x' ), array(), 'GET', '/staff' );
		$this->assertSame( site_url( 'wp-login.php', 'login' ), $this->seen['location'] );
	}

	public function test_signed_in_visitor_at_the_entry_address_goes_to_the_admin(): void {
		$this->enable();
		$this->login( 'administrator' );
		$this->assertSame( 'redirect', $this->request( 'index.php', array(), array(), 'GET', '/staff' ) );
		$this->assertSame( admin_url(), $this->seen['location'] );
		$this->assertSame( array(), $this->seen['cookies'] );
	}

	public function test_other_paths_are_not_the_entry(): void {
		$this->enable();
		foreach ( array( '/staffing', '/staff/x', '/x/staff', '/' ) as $path ) {
			$this->assertSame( 'pass', $this->request( 'index.php', array(), array(), 'GET', $path ), $path );
		}
		$this->assertSame( array(), $this->seen['cookies'] );
	}

	public function test_site_in_a_subdirectory(): void {
		$this->enable();
		update_option( 'home', 'http://example.org/blog' );
		$this->assertSame( 'redirect', $this->request( 'index.php', array(), array(), 'GET', '/blog/staff/' ) );
		$this->assertSame( 'pass', $this->request( 'index.php', array(), array(), 'GET', '/staff/' ) );
		$this->assertSame( 'pass', $this->request( 'index.php', array(), array(), 'GET', '/blog/other' ) );
	}

	public function test_rolling_cookie_for_signed_in_admin_pages(): void {
		$this->enable();
		$this->login( 'administrator' );

		$this->assertSame( 'pass', $this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true ) );
		$this->assertArrayHasKey( EntryCookie::NAME, $this->seen['cookies'] );
		$this->assertTrue( EntryCookie::present(), 'visible to this request, so the interim login URL stays wp-login.php' );

		$this->seen['cookies'] = array();
		$this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true );
		$this->assertSame( array(), $this->seen['cookies'], 'a fresh cookie is not re-sent every request' );

		$_COOKIE[ EntryCookie::NAME ] = EntryCookie::value( time() + 100 );
		$this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true );
		$this->assertArrayHasKey( EntryCookie::NAME, $this->seen['cookies'], 'renewed when under half the lifetime is left' );
	}

	public function test_no_cookie_is_issued_on_the_front_end_or_to_visitors(): void {
		$this->enable();
		$this->login( 'administrator' );
		$this->request( 'index.php', array(), array(), 'GET', '/shop/' );
		$this->assertSame( array(), $this->seen['cookies'] );
	}

	public function test_login_url_for_visitors_and_staff(): void {
		$this->enable( array( Settings::PUBLIC_LOGIN_URL => 'https://example.org/my-account/' ) );
		$this->assertSame( 'https://example.org/my-account/', wp_login_url() );
		$this->assertSame( 'https://example.org/my-account/?redirect_to=' . rawurlencode( 'https://example.org/x/' ), wp_login_url( 'https://example.org/x/' ) );

		$this->with_cookie();
		$this->assertSame( site_url( 'wp-login.php', 'login' ), wp_login_url() );
		$_COOKIE = array();

		$this->login( 'administrator' );
		set_current_screen( 'dashboard' );
		$this->assertSame( site_url( 'wp-login.php', 'login' ), wp_login_url(), 'staff in the admin' );
	}

	public function test_login_url_defaults_to_home_and_is_filterable(): void {
		$this->enable();
		$this->assertSame( home_url( '/' ), wp_login_url() );
		add_filter( 'beltoft_hardening_public_login_url', static fn() => 'https://x.example/in' );
		$this->assertSame( 'https://x.example/in', wp_login_url() );
	}

	public function test_lostpassword_and_register_urls(): void {
		$this->enable( array( Settings::ALLOW_LOSTPASSWORD => '0', Settings::PUBLIC_LOGIN_URL => 'https://example.org/a/' ) );
		$this->assertSame( 'https://example.org/a/', wp_lostpassword_url() );
		$this->assertSame( 'https://example.org/a/', wp_registration_url() );
		$this->enable( array( Settings::ALLOW_LOSTPASSWORD => '1', Settings::PUBLIC_LOGIN_URL => 'https://example.org/a/' ) );
		$this->assertStringContainsString( 'wp-login.php?action=lostpassword', wp_lostpassword_url() );
	}

	public function test_admin_login_refusal_has_no_link_when_the_login_is_hidden(): void {
		$this->enable();
		$login = 'u' . wp_generate_password( 6, false );
		self::factory()->user->create( array( 'user_login' => $login, 'user_pass' => 'pw-12345', 'role' => 'administrator' ) );
		$result = wp_authenticate( $login, 'pw-12345' );
		$this->assertWPError( $result );
		$this->assertStringNotContainsString( '<a ', $result->get_error_message() );
	}
}
