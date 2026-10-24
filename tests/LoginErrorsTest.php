<?php
namespace BeltoftHardening\Tests;

use BeltoftHardening\Settings;

final class LoginErrorsTest extends TestCase {

	private const GENERIC = 'Incorrect username or password.';

	private function shown( string $code, string $text = 'Specific text' ): string {
		$GLOBALS['errors'] = new \WP_Error( $code, $text );
		return apply_filters( 'login_errors', $text );
	}

	/** @dataProvider credential_codes */
	public function test_credential_errors_are_flattened( string $code ): void {
		$this->assertSame( self::GENERIC, $this->shown( $code ) );
	}

	public static function credential_codes(): array {
		return array( array( 'invalid_username' ), array( 'incorrect_password' ), array( 'invalid_email' ) );
	}

	/** @dataProvider other_codes */
	public function test_other_errors_are_kept( string $code ): void {
		$this->assertSame( 'Specific text', $this->shown( $code ) );
	}

	public static function other_codes(): array {
		return array(
			array( 'invalidkey' ),
			array( 'expiredkey' ),
			array( 'registerfail' ),
			array( 'empty_username' ),
			array( 'empty_password' ),
			array( 'beltoft_hardening_admin_login_location' ),
		);
	}

	public function test_reset_link_page_is_not_flattened(): void {
		$GLOBALS['errors'] = new \WP_Error( 'invalidkey', 'Your password reset link appears to be invalid.' );
		$this->assertSame( 'Your password reset link appears to be invalid.', apply_filters( 'login_errors', 'Your password reset link appears to be invalid.' ) );
	}

	public function test_failed_signin_codes_flatten_a_bare_message(): void {
		do_action( 'wp_login_failed', 'x', new \WP_Error( 'incorrect_password', 'raw' ) );
		$this->assertSame( self::GENERIC, apply_filters( 'login_errors', 'raw' ) );
	}

	public function test_real_wrong_login_is_flattened(): void {
		$user = self::factory()->user->create( array( 'user_login' => 'cust', 'user_pass' => 'right-pass' ) );
		$this->assertNotEmpty( $user );
		$result = wp_authenticate( 'cust', 'wrong' );
		$this->assertWPError( $result );
		do_action( 'wp_login_failed', 'cust', $result );
		$this->assertSame( self::GENERIC, apply_filters( 'login_errors', $result->get_error_message() ) );
	}

	public function test_custom_message(): void {
		update_option( Settings::OPTION, Settings::sanitize( array( Settings::UNIFORM_ERRORS => 1, Settings::MESSAGE => 'Nope <b>x</b>' ) ) );
		$this->assertSame( 'Nope x', $this->shown( 'invalid_username' ) );
	}

	public function test_filter_changes_codes(): void {
		add_filter( 'beltoft_hardening_credential_error_codes', static fn( $c ) => array_merge( $c, array( 'invalidcombo' ) ) );
		$this->assertSame( self::GENERIC, $this->shown( 'invalidcombo' ) );
	}

	public function test_setting_off(): void {
		$this->turn_off( Settings::UNIFORM_ERRORS );
		$this->assertSame( 'Specific text', $this->shown( 'invalid_username' ) );
		$this->assertInstanceOf( \WP_Error::class, apply_filters( 'woocommerce_process_login_errors', new \WP_Error( 'incorrect_password', 'x' ) ) );
		$this->assertSame( 'incorrect_password', apply_filters( 'woocommerce_process_login_errors', new \WP_Error( 'incorrect_password', 'x' ) )->get_error_code() );
	}

	public function test_woocommerce_credential_errors_are_mapped(): void {
		$out = apply_filters( 'woocommerce_process_login_errors', new \WP_Error( 'incorrect_password', 'raw' ) );
		$this->assertSame( 'beltoft_hardening_login_failed', $out->get_error_code() );
		$this->assertSame( self::GENERIC, $out->get_error_message() );
	}

	public function test_woocommerce_other_errors_are_kept(): void {
		$in  = new \WP_Error( 'captcha', 'Captcha failed.' );
		$out = apply_filters( 'woocommerce_process_login_errors', $in );
		$this->assertSame( 'Captcha failed.', $out->get_error_message() );
	}

	public function test_woocommerce_no_error_passes(): void {
		$in = new \WP_Error();
		$this->assertSame( $in, apply_filters( 'woocommerce_process_login_errors', $in ) );
	}
}
