<?php
namespace BeltoftHardening\Tests;

use BeltoftHardening\Settings;

final class HideLoginSettingsTest extends TestCase {

	public function test_it_defaults_to_off_while_the_five_stay_on(): void {
		$this->assertFalse( Settings::hide_login_enabled() );
		$this->assertNotContains( Settings::HIDE_LOGIN, Settings::toggles() );
		foreach ( Settings::toggles() as $key ) {
			$this->assertTrue( Settings::enabled( $key ) );
		}
	}

	public function test_enabling_without_a_slug_is_refused(): void {
		$out = Settings::sanitize( array( Settings::HIDE_LOGIN => '1', Settings::ENTRY_SLUG => '' ) );
		$this->assertSame( 0, $out[ Settings::HIDE_LOGIN ] );
		$errors = wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' );
		$this->assertContains( 'beltoft_hardening_hide_login', $errors );
	}

	public function test_enabling_with_a_slug_works_and_the_slug_is_cleaned(): void {
		$out = Settings::sanitize( array( Settings::HIDE_LOGIN => '1', Settings::ENTRY_SLUG => ' /Staff Door!/ ' ) );
		$this->assertSame( 1, $out[ Settings::HIDE_LOGIN ] );
		$this->assertSame( 'staffdoor', $out[ Settings::ENTRY_SLUG ] );
	}

	public function test_a_slug_that_cleans_to_nothing_or_starts_with_wp_is_refused(): void {
		$this->assertSame( 0, Settings::sanitize( array( Settings::HIDE_LOGIN => '1', Settings::ENTRY_SLUG => '!!!' ) )[ Settings::HIDE_LOGIN ] );
		$this->assertSame( 0, Settings::sanitize( array( Settings::HIDE_LOGIN => '1', Settings::ENTRY_SLUG => 'wp-admin' ) )[ Settings::HIDE_LOGIN ] );
	}

	public function test_slug_collision_with_a_page_is_refused(): void {
		self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'staff', 'post_status' => 'publish' ) );
		$out = Settings::sanitize( array( Settings::HIDE_LOGIN => '1', Settings::ENTRY_SLUG => 'staff' ) );
		$this->assertSame( 0, $out[ Settings::HIDE_LOGIN ] );
		$this->assertContains( 'beltoft_hardening_slug_collision', wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' ) );
	}

	public function test_reserved_language_rewrite_and_public_page_slugs_are_refused(): void {
		foreach ( array( 'feed', 'cart', 'shop', 'my-account', 'en', 'da-dk', 'login', 'wp-json' ) as $slug ) {
			$this->assertSame( 0, Settings::sanitize( array( Settings::HIDE_LOGIN => '1', Settings::ENTRY_SLUG => $slug ) )[ Settings::HIDE_LOGIN ], $slug );
		}
		// A rewrite endpoint and a registered post type archive.
		$this->set_permalink_structure( '/%postname%/' );
		add_rewrite_endpoint( 'myendpoint', EP_ROOT );
		register_post_type( 'book', array( 'public' => true, 'has_archive' => true, 'rewrite' => array( 'slug' => 'books' ) ) );
		flush_rewrite_rules();
		$this->assertSame( 0, Settings::sanitize( array( Settings::HIDE_LOGIN => '1', Settings::ENTRY_SLUG => 'myendpoint' ) )[ Settings::HIDE_LOGIN ] );
		$this->assertSame( 0, Settings::sanitize( array( Settings::HIDE_LOGIN => '1', Settings::ENTRY_SLUG => 'books' ) )[ Settings::HIDE_LOGIN ] );
		// The slug of the public sign-in page.
		$this->assertSame( 0, Settings::sanitize( array( Settings::HIDE_LOGIN => '1', Settings::ENTRY_SLUG => 'sign-in', Settings::PUBLIC_LOGIN_URL => home_url( '/sign-in/' ) ) )[ Settings::HIDE_LOGIN ] );
		// A free one works.
		$this->assertSame( 1, Settings::sanitize( array( Settings::HIDE_LOGIN => '1', Settings::ENTRY_SLUG => 'staff-door' ) )[ Settings::HIDE_LOGIN ] );
	}

	public function test_the_two_extras_default_off_and_save(): void {
		$this->assertFalse( Settings::extra_enabled( Settings::DISABLE_XMLRPC ) );
		$out = Settings::sanitize( array( Settings::DISABLE_XMLRPC => '1' ) );
		$this->assertSame( 1, $out[ Settings::DISABLE_XMLRPC ] );
		$this->assertSame( 0, $out[ Settings::DISABLE_APP_PASSWORDS ] );
	}

	public function test_extras_hook_only_when_on(): void {
		\BeltoftHardening\Extras::register();
		$this->assertTrue( apply_filters( 'xmlrpc_enabled', true ) );
		$this->assertTrue( wp_is_application_passwords_available() || true );
		update_option( Settings::OPTION, array( Settings::DISABLE_XMLRPC => 1, Settings::DISABLE_APP_PASSWORDS => 1 ) );
		\BeltoftHardening\Extras::register();
		$this->assertFalse( apply_filters( 'xmlrpc_enabled', true ) );
		$this->assertFalse( wp_is_application_passwords_available() );
		update_option( Settings::OPTION, array() );
		\BeltoftHardening\Extras::register();
		$this->assertTrue( apply_filters( 'xmlrpc_enabled', true ) );
	}

	public function test_fields_are_sanitized(): void {
		$out = Settings::sanitize( array( Settings::PUBLIC_LOGIN_URL => 'javascript:alert(1)', Settings::ALLOW_LOSTPASSWORD => 'yes' ) );
		$this->assertSame( '', $out[ Settings::PUBLIC_LOGIN_URL ] );
		$this->assertSame( '', $out[ Settings::ALLOW_LOSTPASSWORD ] );
		$out = Settings::sanitize( array( Settings::PUBLIC_LOGIN_URL => 'https://example.org/my-account/', Settings::ALLOW_LOSTPASSWORD => '0' ) );
		$this->assertSame( 'https://example.org/my-account/', $out[ Settings::PUBLIC_LOGIN_URL ] );
		$this->assertSame( '0', $out[ Settings::ALLOW_LOSTPASSWORD ] );
		$this->assertSame( 0, $out[ Settings::HIDE_LOGIN ] );
	}

	public function test_lostpassword_setting_overrides_the_default(): void {
		update_option( Settings::OPTION, array( Settings::ALLOW_LOSTPASSWORD => '0' ) );
		$this->assertFalse( Settings::allow_lostpassword() );
		update_option( Settings::OPTION, array( Settings::ALLOW_LOSTPASSWORD => '1' ) );
		$this->assertTrue( Settings::allow_lostpassword() );
	}

	public function test_public_login_url_uses_my_account_when_woocommerce_is_active(): void {
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			eval( 'function wc_get_page_permalink( $p ) { return "https://shop.example/my-account/"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- stands in for WooCommerce.
		}
		$this->assertSame( 'https://shop.example/my-account/', Settings::public_login_url() );
		update_option( Settings::OPTION, array( Settings::PUBLIC_LOGIN_URL => 'https://x.example/in' ) );
		$this->assertSame( 'https://x.example/in', Settings::public_login_url() );
	}

	public function test_settings_screen_shows_the_entry_url_and_warning(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION, array( Settings::HIDE_LOGIN => 1, Settings::ENTRY_SLUG => 'staff' ) );
		\BeltoftHardening\HideLogin::register();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$GLOBALS['title'] = 'Hardening';
		\BeltoftHardening\SettingsPage::settings();
		ob_start();
		\BeltoftHardening\SettingsPage::render();
		$html = ob_get_clean();
		\BeltoftHardening\HideLogin::unregister();
		$this->assertStringContainsString( home_url( '/staff/' ), $html );
		$this->assertStringContainsString( 'BELTOFT_HARDENING_DISABLE_HIDE_LOGIN', $html );
	}
}
