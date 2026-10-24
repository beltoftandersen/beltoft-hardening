<?php
namespace BeltoftHardening\Tests;

use BeltoftHardening\Settings;

final class RestUsersTest extends TestCase {

	private function routes(): array {
		return array_keys( rest_get_server()->get_routes() );
	}

	public function test_hidden_from_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$routes = $this->routes();
		$this->assertNotContains( '/wp/v2/users', $routes );
		$this->assertNotContains( '/wp/v2/users/(?P<id>[\d]+)', $routes );
	}

	public function test_hidden_from_visitor(): void {
		wp_set_current_user( 0 );
		$this->assertNotContains( '/wp/v2/users', $this->routes() );
	}

	public function test_visible_to_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$routes = $this->routes();
		$this->assertContains( '/wp/v2/users', $routes );
		$this->assertContains( '/wp/v2/users/(?P<id>[\d]+)', $routes );
	}

	public function test_setting_off_keeps_routes(): void {
		$this->turn_off( Settings::REST_USERS );
		wp_set_current_user( 0 );
		$this->assertContains( '/wp/v2/users', $this->routes() );
	}

	public function test_filter_can_add_routes(): void {
		add_filter( 'beltoft_hardening_hidden_rest_routes', static fn( $r ) => array( '/wp/v2/posts' ) );
		wp_set_current_user( 0 );
		$routes = $this->routes();
		$this->assertContains( '/wp/v2/users', $routes );
		$this->assertNotContains( '/wp/v2/posts', $routes );
	}
}
