<?php
namespace BeltoftHardening\Tests;

use BeltoftHardening\Settings;

final class AuthorArchivesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->user = self::factory()->user->create( array( 'user_login' => 'someone', 'role' => 'editor' ) );
	}

	/** @var int */
	private $user;

	private function assert_no_redirect( string $url ): void {
		$this->assertNull( redirect_canonical( home_url( $url ), false ) );
	}

	public function test_author_id_is_404_without_redirect(): void {
		$this->go_to( '/?author=' . $this->user );
		$this->assertTrue( is_404() );
		$this->assertFalse( is_author() );
		$this->assert_no_redirect( '/?author=' . $this->user );
	}

	public function test_author_name_is_404(): void {
		$this->go_to( '/?author_name=someone' );
		$this->assertTrue( is_404() );
	}

	public function test_pretty_author_url_is_404(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$this->go_to( '/author/someone/' );
		$this->assertTrue( is_404() );
	}

	public function test_normal_page_is_untouched(): void {
		$this->go_to( '/' );
		$this->assertFalse( is_404() );
	}

	public function test_admin_is_untouched(): void {
		set_current_screen( 'edit.php' );
		$wp = new \WP();
		$wp->query_vars = array( 'author' => '1' );
		\BeltoftHardening\AuthorArchives::block( $wp );
		$this->assertSame( array( 'author' => '1' ), $wp->query_vars );
		set_current_screen( 'front' );
	}

	public function test_setting_off_keeps_author_archive(): void {
		$this->turn_off( Settings::AUTHOR_ARCHIVES );
		$this->go_to( '/?author=' . $this->user );
		$this->assertTrue( is_author() );
	}

	public function test_filter_can_allow(): void {
		add_filter( 'beltoft_hardening_block_author_request', '__return_false' );
		$this->go_to( '/?author=' . $this->user );
		$this->assertTrue( is_author() );
	}
}
