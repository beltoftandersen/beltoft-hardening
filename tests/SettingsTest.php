<?php
namespace BeltoftHardening\Tests;

use BeltoftHardening\Settings;

final class SettingsTest extends TestCase {

	public function test_everything_defaults_on(): void {
		foreach ( Settings::toggles() as $key ) {
			$this->assertTrue( Settings::enabled( $key ) );
		}
		$this->assertSame( 'Incorrect username or password.', Settings::message() );
	}

	public function test_sanitize_unchecked_is_off(): void {
		$out = Settings::sanitize( array( Settings::REST_USERS => '1' ) );
		$this->assertSame( 1, $out[ Settings::REST_USERS ] );
		$this->assertSame( 0, $out[ Settings::AUTHOR_ARCHIVES ] );
		$this->assertSame( 0, $out[ Settings::UNIFORM_ERRORS ] );
		$this->assertSame( 0, $out[ Settings::ADMIN_LOGIN ] );
		$this->assertSame( '', $out[ Settings::MESSAGE ] );
	}

	public function test_sanitize_message_and_garbage(): void {
		$out = Settings::sanitize( array( Settings::MESSAGE => "  <script>x</script>Bad\n login " ) );
		$this->assertStringNotContainsString( '<', $out[ Settings::MESSAGE ] );
		$this->assertSame( array(), array_diff( array_keys( Settings::sanitize( 'junk' ) ), array_merge( Settings::toggles(), array( Settings::MESSAGE ) ) ) );
		$this->assertSame( 0, Settings::sanitize( array( 'evil' => 1 ) )[ Settings::REST_USERS ] );
		$this->assertArrayNotHasKey( 'evil', Settings::sanitize( array( 'evil' => 1 ) ) );
	}

	public function test_saved_off_is_off(): void {
		update_option( Settings::OPTION, Settings::sanitize( array() ) );
		$this->assertFalse( Settings::enabled( Settings::REST_USERS ) );
	}

	public function test_message_filter(): void {
		add_filter( 'beltoft_hardening_generic_message', static fn() => 'Custom' );
		$this->assertSame( 'Custom', Settings::message() );
	}

	public function test_settings_page_registered(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		\BeltoftHardening\SettingsPage::settings();
		$this->assertArrayHasKey( Settings::OPTION, get_registered_settings() );
	}
}
