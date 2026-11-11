<?php
namespace BeltoftHardening\Tests;

use BeltoftHardening\EntryCookie;
use BeltoftHardening\Settings;

final class HideLoginRequestsTest extends HideLoginTestCase {

	public function test_off_registers_nothing_and_changes_nothing(): void {
		\BeltoftHardening\HideLogin::register();
		$this->assertFalse( has_action( 'init', array( \BeltoftHardening\HideLogin::class, 'guard' ) ) );
		$this->assertFalse( has_action( 'wp_loaded', array( \BeltoftHardening\HideLogin::class, 'send_pending' ) ) );
		$this->assertFalse( has_filter( 'login_url', array( \BeltoftHardening\HideLogin::class, 'login_url' ) ) );
		$this->assertFalse( has_filter( 'wp_redirect', array( \BeltoftHardening\HideLogin::class, 'redirect_to_404' ) ) );
		$this->assertSame( site_url( 'wp-login.php', 'login' ), wp_login_url() );
		$this->assertSame( 0, Settings::hide_login_enabled() ? 1 : 0 );
	}

	public function test_on_registers_early_hooks(): void {
		$this->enable();
		$this->assertSame( -1000, has_action( 'init', array( \BeltoftHardening\HideLogin::class, 'guard' ) ) );
		$this->assertSame( 0, has_action( 'wp_loaded', array( \BeltoftHardening\HideLogin::class, 'send_pending' ) ) );
	}

	public function test_constant_name_is_documented_in_code(): void {
		// The constant itself cannot be undefined; ZHideLoginConstantTest defines it and checks the effect.
		$this->assertStringContainsString( 'BELTOFT_HARDENING_DISABLE_HIDE_LOGIN', file_get_contents( dirname( __DIR__ ) . '/src/HideLogin.php' ) );
	}

	public function test_without_a_slug_the_control_is_not_in_force(): void {
		update_option( Settings::OPTION, array( Settings::HIDE_LOGIN => 1, Settings::ENTRY_SLUG => '' ) );
		\BeltoftHardening\HideLogin::register();
		$this->assertFalse( \BeltoftHardening\HideLogin::active() );
		$this->assertSame( 'pass', $this->request( 'wp-login.php' ) );
	}

	public function test_login_form_get_is_404_for_a_visitor(): void {
		$this->enable();
		$this->assertSame( '404', $this->request( 'wp-login.php' ) );
		$this->assertSame( 404, $this->seen['status'] );
		$this->assertNull( $this->seen['location'] );
		$this->assertArrayHasKey( 'Cache-Control', $this->seen['nocache'] );
		$this->assertSame( array(), $this->seen['cookies'] );
	}

	public function test_password_post_is_404_for_a_visitor(): void {
		$this->enable();
		$this->assertSame( '404', $this->request( 'wp-login.php', array(), array( 'log' => 'a', 'pwd' => 'b', 'wp-submit' => 'Log In' ), 'POST' ) );
	}

	public function test_head_request_is_404_without_a_body(): void {
		$this->enable();
		$this->assertSame( '404', $this->request( 'wp-login.php', array(), array(), 'HEAD' ) );
		$this->assertSame( '', $this->seen['body'] );
	}

	public function test_the_404_renders_the_theme_template_for_get(): void {
		$this->enable();
		$this->request( 'wp-login.php' );
		$this->assertTrue( is_404() );
		$this->assertNotSame( '', $this->seen['body'] );
		$this->assertStringContainsString( 'Page not found', $this->seen['body'] );
	}

	public function test_forged_expired_and_tampered_cookies_are_not_valid(): void {
		$this->enable();
		$good = EntryCookie::value();
		list( $exp, $sig ) = explode( '.', $good );

		foreach ( array(
			'garbage',
			( (int) $exp + 60 ) . '.' . $sig,                        // changed expiry.
			$exp . '.' . str_repeat( 'a', 64 ),                      // wrong signature.
			EntryCookie::value( time() - 5 ),                        // expired.
			EntryCookie::value( time() + 10 * DAY_IN_SECONDS ),      // signed, but further ahead than ever issued.
			$exp . '.' . $sig . 'x',
		) as $bad ) {
			$_COOKIE[ EntryCookie::NAME ] = $bad;
			$this->assertSame( '404', $this->request( 'wp-login.php' ), $bad );
		}

		$_COOKIE[ EntryCookie::NAME ] = $good;
		$this->assertSame( 'pass', $this->request( 'wp-login.php' ) );
	}

	public function test_salt_rotation_invalidates_the_cookie(): void {
		$this->enable();
		$value = EntryCookie::value();
		$this->assertTrue( EntryCookie::check( $value ) );
		add_filter( 'salt', static fn( $salt, $scheme ) => 'auth' === $scheme ? 'rotated-' . $salt : $salt, 10, 2 );
		$this->assertFalse( EntryCookie::check( $value ) );
	}

	public function test_valid_cookie_lets_everything_through(): void {
		$this->enable();
		$this->with_cookie();
		$this->assertSame( 'pass', $this->request( 'wp-login.php' ) );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'interim-login' => '1', 'reauth' => '1', 'redirect_to' => 'http://x/' ) ) );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array(), array( 'log' => 'a', 'pwd' => 'b' ), 'POST' ) );
		$this->assertSame( 'pass', $this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true ) );
	}

	public function test_interim_login_and_reauth_without_cookie_are_404(): void {
		$this->enable();
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'interim-login' => '1' ) ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'reauth' => '1', 'redirect_to' => 'http://example.org/wp-admin/' ) ) );
	}

	public function test_public_actions_pass_without_cookie(): void {
		$this->enable( array( Settings::ALLOW_LOSTPASSWORD => '0' ) );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'logout', '_wpnonce' => wp_create_nonce( 'log-out' ) ) ) );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'postpass' ), array( 'post_password' => 'x' ), 'POST' ) );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'rp', 'key' => 'k', 'login' => 'u' ) ) );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'resetpass' ), array( 'pass1' => 'x', 'rp_key' => 'k' ), 'POST' ) );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'confirmaction', 'request_id' => '1', 'confirm_key' => 'k' ) ) );
	}

	public function test_lostpassword_follows_the_setting(): void {
		$this->enable( array( Settings::ALLOW_LOSTPASSWORD => '0' ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'lostpassword' ) ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'checkemail' => 'confirm' ) ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'retrievepassword' ), array( 'user_login' => 'a' ), 'POST' ) );

		$this->enable( array( Settings::ALLOW_LOSTPASSWORD => '1' ) );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'lostpassword' ) ) );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'checkemail' => 'confirm' ) ) );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'lostpassword' ), array( 'user_login' => 'a' ), 'POST' ) );
	}

	public function test_lostpassword_default_without_woocommerce_is_on(): void {
		$this->assertFalse( class_exists( 'WooCommerce' ) );
		update_option( Settings::OPTION, array( Settings::HIDE_LOGIN => 1, Settings::ENTRY_SLUG => 'staff' ) );
		$this->assertTrue( Settings::allow_lostpassword() );
	}

	public function test_expired_reset_link_is_sent_to_the_working_lostpassword_page(): void {
		$this->enable( array( Settings::ALLOW_LOSTPASSWORD => '0' ) );
		$this->assertSame( 'redirect', $this->request( 'wp-login.php', array( 'action' => 'lostpassword', 'error' => 'invalidkey' ) ) );
		$this->assertSame( wp_lostpassword_url(), $this->seen['location'] );
		$this->assertSame( 'redirect', $this->request( 'wp-login.php', array( 'action' => 'lostpassword', 'error' => 'expiredkey' ) ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'lostpassword', 'error' => 'invalidkey' ), array( 'user_login' => 'a' ), 'POST' ), 'a POST is no landing' );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'lostpassword', 'error' => 'other' ) ) );
	}

	public function test_register_and_unknown_actions_are_404(): void {
		$this->enable();
		foreach ( array( 'register', 'login', 'confirm_admin_email', 'nonsense', '' ) as $action ) {
			$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => $action ) ), $action );
		}
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => array( 'logout' ) ) ) );
	}

	private function with_2fa_handlers(): void {
		add_action( 'login_form_validate_2fa', '__return_null' );
		add_action( 'login_form_backup_2fa', '__return_null' );
	}

	public function test_two_factor_defaults_pass_with_their_markers_only(): void {
		$this->enable();
		$this->with_2fa_handlers();
		$marks = array( 'wp-auth-id' => '5', 'wp-auth-nonce' => 'abc' );

		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'validate_2fa' ), $marks, 'POST' ) );
		// The markers are read from the body for validate_2fa: in the query string they do not count.
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'validate_2fa' ) + $marks ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'validate_2fa' ) ) );
		// One marker, an empty one or an array one is not enough.
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'validate_2fa' ), array( 'wp-auth-id' => '5' ), 'POST' ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'validate_2fa' ), array( 'wp-auth-nonce' => 'abc' ), 'POST' ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'validate_2fa' ), array( 'wp-auth-id' => '5', 'wp-auth-nonce' => '' ), 'POST' ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'validate_2fa' ), array( 'wp-auth-id' => '5', 'wp-auth-nonce' => array( 'x' ) ), 'POST' ) );

		// backup_2fa is a GET with a provider.
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'backup_2fa', 'provider' => 'totp' ) + $marks ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'backup_2fa' ) + $marks ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'backup_2fa', 'wp-auth-id' => '5', 'provider' => 'totp' ) ) );
	}

	public function test_two_factor_actions_need_a_registered_handler(): void {
		$this->enable();
		$marks = array( 'wp-auth-id' => '5', 'wp-auth-nonce' => 'abc' );
		// WP 2FA not active: nothing handles the action, WordPress would draw the login form.
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'validate_2fa' ), $marks, 'POST' ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'backup_2fa', 'provider' => 'totp' ) + $marks ) );
		// grace_2fa has no wp-login.php handler in WP 2FA.
		$this->with_2fa_handlers();
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'grace_2fa' ), $marks, 'POST' ) );
	}

	public function test_a_two_factor_action_is_no_back_door_for_the_password(): void {
		$this->enable();
		add_action( 'login_form_validate_2fa', '__return_null' );
		$post = array( 'wp-auth-id' => '5', 'wp-auth-nonce' => 'abc', 'log' => 'admin', 'pwd' => 'guess' );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'validate_2fa' ), $post, 'POST' ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'rp', 'key' => 'k' ), array( 'log' => 'a', 'pwd' => 'b' ), 'POST' ) );
	}

	public function test_two_factor_list_and_allowlist_are_filterable(): void {
		$this->enable();
		add_action( 'login_form_other_2fa', '__return_null' );
		add_filter( 'beltoft_hardening_two_factor_actions', static fn( $l ) => $l + array( 'other_2fa' => array( 'token' ) ) );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'other_2fa', 'token' => 'x' ) ) );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'other_2fa' ) ) );

		add_filter( 'beltoft_hardening_public_login_actions', static fn( $l ) => array_merge( $l, array( 'custom' ) ) );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'custom' ) ) );
		add_filter( 'beltoft_hardening_public_login_actions', static function ( $l ) {
			unset( $l['logout'] );
			return $l;
		} );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'logout' ) ) );
	}

	public function test_signed_in_users_are_never_affected(): void {
		$this->enable();
		foreach ( array( 'subscriber', 'administrator' ) as $role ) {
			$this->login( $role );
			$this->assertSame( 'pass', $this->request( 'wp-login.php' ), $role );
			$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'register' ) ), $role );
			$this->assertSame( 'pass', $this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true ), $role );
		}
	}

	public function test_admin_area_is_404_for_a_visitor_except_public_endpoints(): void {
		$this->enable();
		$this->assertSame( '404', $this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true ) );
		$this->assertSame( '404', $this->request( 'edit.php', array(), array(), 'GET', '/wp-admin/edit.php', true ) );
		$this->assertSame( '404', $this->request( 'options.php', array(), array( 'x' => 1 ), 'POST', '/wp-admin/options.php', true ) );
		$this->assertSame( '404', $this->request( 'install.php', array(), array(), 'GET', '/wp-admin/install.php', true ) );
		$this->assertSame( '404', $this->request( 'upgrade.php', array(), array(), 'GET', '/wp-admin/upgrade.php', true ) );
		$this->assertSame( 'pass', $this->request( 'admin-post.php', array( 'action' => 'x' ), array(), 'POST', '/wp-admin/admin-post.php', true ) );

		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertSame( 'pass', $this->request( 'admin-ajax.php', array(), array( 'action' => 'nopriv' ), 'POST', '/wp-admin/admin-ajax.php', true ) );
	}

	public function test_an_admin_request_is_answered_as_the_front_end(): void {
		$this->enable();
		$this->assertSame( '404', $this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true ) );
		$this->assertFalse( is_admin(), 'themes and plugins must not see is_admin() while the 404 is built' );
		$this->assertTrue( is_404() );
	}

	public function test_admin_with_cookie_goes_on_to_wordpress(): void {
		$this->enable();
		$this->with_cookie();
		$this->assertSame( 'pass', $this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true ) );
		$this->assertNull( $this->seen['location'] );
	}

	public function test_front_end_rest_cron_and_xmlrpc_are_untouched(): void {
		$this->enable();
		$this->assertSame( 'pass', $this->request( 'index.php', array(), array(), 'GET', '/some-page/' ) );
		$this->assertSame( 'pass', $this->request( 'index.php', array( 'rest_route' => '/wp/v2/posts' ), array(), 'GET', '/' ) );
		$this->assertSame( 'pass', $this->request( 'xmlrpc.php', array(), array(), 'POST', '/xmlrpc.php' ) );
		$this->assertSame( 'pass', $this->request( 'wp-cron.php', array(), array(), 'GET', '/wp-cron.php' ) );
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertSame( 'pass', $this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true ) );
	}

	public function test_login_aliases_do_not_redirect_a_visitor(): void {
		$this->assertNotFalse( has_action( 'template_redirect', 'wp_redirect_admin_locations' ) );
		$this->enable();
		$this->request( 'index.php', array(), array(), 'GET', '/some-page/' );
		$this->assertFalse( has_action( 'template_redirect', 'wp_redirect_admin_locations' ) );
	}

	public function test_another_plugins_early_redirect_becomes_the_404(): void {
		$this->enable();
		$GLOBALS['pagenow'] = 'wp-login.php';
		$_GET = $_POST = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$level = ob_get_level();
		ob_start();
		try {
			\BeltoftHardening\HideLogin::guard();
			// What an SSO plugin does at init: redirect, then exit.
			wp_redirect( 'https://idp.example/auth' );
			$result = 'not stopped';
		} catch ( Halted $e ) {
			$result = 'halted';
		}
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		$this->assertSame( 'halted', $result );
		$this->assertSame( 404, $this->seen['status'] );
		$this->assertNull( $this->seen['location'] );
	}

	public function test_a_redirect_by_a_public_request_is_left_alone(): void {
		$this->enable();
		$this->request( 'wp-login.php', array( 'action' => 'logout' ) );
		$this->assertFalse( has_filter( 'wp_redirect', array( \BeltoftHardening\HideLogin::class, 'redirect_to_404' ) ) );
	}
}
