<?php
namespace BeltoftHardening\Tests;

use BeltoftHardening\EntryCookie;
use BeltoftHardening\HideLogin;
use BeltoftHardening\Settings;

/**
 * Probes from the security review: the login form must not be drawn without the cookie, customers get no entry cookie,
 * and the mutants that survived the first round.
 */
final class HideLoginFixRoundTest extends HideLoginTestCase {

	/**
	 * @return array<string,array{0:array<string,string>,1:array<string,string>,2:string}>
	 */
	public function form_probes(): array {
		$m = array( 'wp-auth-id' => '1', 'wp-auth-nonce' => 'x' );
		return array(
			'LOGOUT'                 => array( array( 'action' => 'LOGOUT' ), array(), 'GET' ),
			'Logout'                 => array( array( 'action' => 'Logout' ), array(), 'GET' ),
			'log out'                => array( array( 'action' => 'log out' ), array(), 'GET' ),
			'rp!'                    => array( array( 'action' => 'rp!' ), array(), 'GET' ),
			'POSTPASS'               => array( array( 'action' => 'POSTPASS' ), array(), 'GET' ),
			'password-reset'         => array( array( 'password-reset' => 'true' ), array(), 'GET' ),
			'recovery no token'      => array( array( 'action' => 'enter_recovery_mode' ), array(), 'GET' ),
			'recovery token only'    => array( array( 'action' => 'enter_recovery_mode', 'rm_token' => 'a' ), array(), 'GET' ),
			'validate_2fa GET'       => array( array( 'action' => 'validate_2fa' ) + $m, array(), 'GET' ),
			'backup_2fa GET bare'    => array( array( 'action' => 'backup_2fa' ) + $m, array(), 'GET' ),
			'grace_2fa GET'          => array( array( 'action' => 'grace_2fa' ) + $m, array(), 'GET' ),
			'logout without nonce'   => array( array( 'action' => 'logout' ), array(), 'GET' ),
			'logout wrong nonce'     => array( array( 'action' => 'logout', '_wpnonce' => 'abcdef1234' ), array(), 'GET' ),
			'logout with pwd in GET' => array( array( 'action' => 'logout', 'pwd' => 'x' ), array(), 'GET' ),
			'logout with log in POST' => array( array( 'action' => 'logout' ), array( 'log' => 'a' ), 'POST' ),
			'logout with pwd in POST' => array( array( 'action' => 'logout' ), array( 'pwd' => 'a' ), 'POST' ),
		);
	}

	/**
	 * @dataProvider form_probes
	 */
	public function test_probes_do_not_get_the_login_form( array $get, array $post, string $method ): void {
		$this->enable();
		add_action( 'login_form_validate_2fa', '__return_null' );
		add_action( 'login_form_backup_2fa', '__return_null' );
		$this->assertSame( '404', $this->request( 'wp-login.php', $get, $post, $method ) );
	}

	public function test_a_post_validate_2fa_with_markers_passes_the_gate_but_the_backstop_closes_the_form(): void {
		$this->enable();
		add_action( 'login_form_validate_2fa', '__return_null' ); // The plugin returns for an unknown user, WordPress draws the form.
		$post = array( 'wp-auth-id' => '999999', 'wp-auth-nonce' => 'x' );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'validate_2fa' ), $post, 'POST' ) );
		// wp-login.php now reaches the filter that runs right before the form is drawn.
		$level = ob_get_level();
		ob_start();
		try {
			apply_filters( 'wp_login_errors', new \WP_Error(), '' );
			$result = 'form drawn';
		} catch ( Halted $e ) {
			$result = '404';
		}
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		$this->assertSame( '404', $result );
		$this->assertSame( 404, $this->seen['status'] );
	}

	/**
	 * Runs the filter wp-login.php applies before drawing the login form, for the current request state.
	 */
	private function draw(): string {
		$level = ob_get_level();
		ob_start();
		try {
			apply_filters( 'wp_login_errors', new \WP_Error(), '' );
			$result = 'form drawn';
		} catch ( Halted $e ) {
			$result = '404';
		}
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		return $result;
	}

	public function test_backstop_leaves_staff_screens_and_allowed_pages_alone(): void {
		$this->enable( array( Settings::ALLOW_LOSTPASSWORD => '1' ) );

		$this->with_cookie();
		$this->request( 'wp-login.php' );
		$this->assertSame( 'form drawn', $this->draw(), 'cookie' );

		$_COOKIE = array();
		$this->request( 'wp-login.php', array( 'checkemail' => 'confirm' ) );
		$this->assertSame( 'form drawn', $this->draw(), 'check your email screen is not a login form' );

		$this->login( 'administrator' );
		$this->request( 'wp-login.php' );
		$this->assertSame( 'form drawn', $this->draw(), 'signed in' );
	}

	public function test_backstop_closes_the_plain_form_for_a_visitor_and_removes_cookies(): void {
		$this->enable();
		$GLOBALS['pagenow'] = 'wp-login.php';
		$_GET = $_POST = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertSame( '404', $this->draw() );
	}

	public function test_entered_recovery_mode_gets_the_entry_cookie_when_recovery_is_active(): void {
		$this->enable();
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'action' => 'entered_recovery_mode' ) ) );
		add_filter( 'beltoft_hardening_recovery_mode_active', '__return_true' );
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'entered_recovery_mode' ) ) );
		$this->assertArrayHasKey( EntryCookie::NAME, $this->seen['cookies'] );
	}

	public function test_recovery_mode_link_with_token_and_key_is_public(): void {
		$this->enable();
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'enter_recovery_mode', 'rm_token' => 'a', 'rm_key' => 'b' ) ) );
	}

	public function test_key_alone_means_rp(): void {
		$this->enable();
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'key' => 'abc', 'login' => 'u' ) ) );
	}

	public function test_loggedout_goes_to_the_public_sign_in_address(): void {
		$this->enable( array( Settings::PUBLIC_LOGIN_URL => 'https://example.org/my-account/' ) );
		$this->assertSame( 'redirect', $this->request( 'wp-login.php', array( 'loggedout' => 'true', 'wp_lang' => 'en_US' ) ) );
		$this->assertSame( 'https://example.org/my-account/', $this->seen['location'] );
		$this->assertSame( '404', $this->request( 'wp-login.php', array( 'loggedout' => 'true' ), array( 'x' => '1' ), 'POST' ) );
	}

	public function test_logout_with_a_valid_nonce_is_public(): void {
		$this->enable();
		$this->assertSame( 'pass', $this->request( 'wp-login.php', array( 'action' => 'logout', '_wpnonce' => wp_create_nonce( 'log-out' ) ) ) );
	}

	public function test_customers_get_no_entry_cookie_staff_do(): void {
		$this->enable();
		$this->login( 'subscriber' );
		$this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->request( 'admin-ajax.php', array( 'action' => 'heartbeat' ), array(), 'POST', '/wp-admin/admin-ajax.php', true );
		$this->login( 'customer' ) ;
		$this->assertSame( array(), $this->seen['cookies'] );

		$this->login( 'editor' );
		$this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true );
		$this->assertArrayHasKey( EntryCookie::NAME, $this->seen['cookies'] );
	}

	public function test_the_staff_capability_is_filterable(): void {
		$this->enable();
		add_filter( 'beltoft_hardening_staff_capability', static fn() => 'manage_options' );
		$this->login( 'editor' );
		$this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true );
		$this->assertSame( array(), $this->seen['cookies'] );
		$this->login( 'administrator' );
		$this->request( 'index.php', array(), array(), 'GET', '/wp-admin/', true );
		$this->assertArrayHasKey( EntryCookie::NAME, $this->seen['cookies'] );
	}

	public function test_pending_makes_the_request_look_like_the_front_end(): void {
		$this->enable();
		$this->request( 'wp-login.php' );
		$this->assertSame( 'index.php', $GLOBALS['pagenow'] );
	}

	public function test_an_admin_script_that_loads_wordpress_before_defining_wp_admin_is_404(): void {
		$this->enable();
		$_SERVER['SCRIPT_NAME'] = '/wp-admin/async-upload.php';
		$this->assertSame( '404', $this->request( 'async-upload.php', array(), array(), 'POST', '/wp-admin/async-upload.php' ) );
		$this->assertSame( '404', $this->request( 'customize.php', array(), array(), 'GET', '/wp-admin/customize.php' ) );
		unset( $_SERVER['SCRIPT_NAME'] );
	}

	public function test_early_marks_an_anonymous_admin_request_and_looks_like_the_front_end(): void {
		$this->enable();
		$GLOBALS['pagenow'] = 'plugins.php';
		set_current_screen( 'plugins' );
		$this->assertTrue( is_admin() );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		HideLogin::early();
		$this->assertFalse( is_admin() );
		$this->assertSame( 'index.php', $GLOBALS['pagenow'] );
		$level = ob_get_level();
		ob_start();
		try {
			HideLogin::send_pending();
			$result = 'sent nothing';
		} catch ( Halted $e ) {
			$result = '404';
		}
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		$this->assertSame( '404', $result );
	}

	public function test_early_leaves_alone_browsers_with_a_login_or_entry_cookie_and_public_scripts(): void {
		$this->enable();
		$GLOBALS['pagenow'] = 'plugins.php';
		set_current_screen( 'plugins' );
		$_COOKIE[ LOGGED_IN_COOKIE ] = 'whatever';
		HideLogin::early();
		$this->assertTrue( is_admin() );
		$_COOKIE = array( EntryCookie::NAME => 'x' );
		HideLogin::early();
		$this->assertTrue( is_admin() );
		$_COOKIE = array();
		$GLOBALS['pagenow'] = 'admin-post.php';
		HideLogin::early();
		$this->assertTrue( is_admin() );
		$GLOBALS['pagenow'] = 'index.php';
		unset( $GLOBALS['current_screen'] );
		$GLOBALS['current_screen'] = null;
		HideLogin::early();
		$this->assertSame( 'index.php', $GLOBALS['pagenow'] );
	}

	public function test_cookie_with_a_trailing_newline_or_for_another_site_is_not_valid(): void {
		$this->enable();
		$good = EntryCookie::value();
		$this->assertTrue( EntryCookie::check( $good ) );
		$this->assertFalse( EntryCookie::check( $good . "\n" ) );
		update_option( 'home', 'http://other.example' );
		$this->assertFalse( EntryCookie::check( $good ), 'bound to the site address' );
	}

	public function test_the_expired_session_modal_points_at_the_entry_address_for_staff(): void {
		$this->enable();
		$this->login( 'editor' );
		set_current_screen( 'dashboard' );
		$seen = array();
		add_action(
			'admin_print_footer_scripts',
			static function () use ( &$seen ) {
				$seen[] = wp_login_url();
			}
		);
		do_action( 'admin_print_footer_scripts' );
		$this->assertSame( array( home_url( '/staff/' ) ), $seen );
		$this->assertSame( site_url( 'wp-login.php', 'login' ), wp_login_url(), 'other links keep wp-login.php' );
	}

	public function test_the_entry_address_carries_the_interim_login_flags(): void {
		$this->enable();
		$this->request( 'index.php', array( 'interim-login' => '1', 'wp_lang' => 'en_US', 'evil' => '1' ), array(), 'GET', '/staff/' );
		$this->assertStringContainsString( 'interim-login=1', $this->seen['location'] );
		$this->assertStringContainsString( 'wp_lang=en_US', $this->seen['location'] );
		$this->assertStringNotContainsString( 'evil', $this->seen['location'] );
	}

	public function test_a_lostpassword_url_another_plugin_moved_is_kept(): void {
		$this->enable( array( Settings::ALLOW_LOSTPASSWORD => '0', Settings::PUBLIC_LOGIN_URL => 'https://example.org/a/' ) );
		add_filter( 'lostpassword_url', static fn() => 'https://example.org/my-account/lost-password/', 10 );
		$this->assertSame( 'https://example.org/my-account/lost-password/', wp_lostpassword_url() );
	}
}
