<?php
namespace BeltoftHardening\Tests;

use BeltoftHardening\Settings;

final class AuthorDetailsTest extends TestCase {

	private int $author_id;
	private int $post_id;
	private int $page_id;

	public function set_up(): void {
		parent::set_up();

		$this->author_id = self::factory()->user->create(
			array(
				'user_login'   => 'secret-login',
				'display_name' => 'Pat Writer',
				'role'         => 'author',
			)
		);
		$this->post_id   = self::factory()->post->create( array( 'post_author' => $this->author_id, 'post_status' => 'publish' ) );
		$this->page_id   = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $this->author_id, 'post_status' => 'publish' ) );
	}

	private function as_role( string $role ): void {
		wp_set_current_user( 'visitor' === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) ) );
	}

	private function oembed(): array {
		return get_oembed_response_data( get_post( $this->post_id ), 600 );
	}

	/**
	 * One REST item as the client receives it.
	 *
	 * @return array{0:\WP_REST_Response,1:array}
	 */
	private function item( string $route, array $params = array() ): array {
		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );
		$response = rest_do_request( $request );

		return array( $response, rest_get_server()->response_to_data( $response, ! empty( $params['_embed'] ) ) );
	}

	// oEmbed.

	public function test_oembed_without_the_setting_leaks_the_login_name(): void {
		$this->turn_off( Settings::AUTHOR_DETAILS );
		$this->as_role( 'visitor' );
		$data = $this->oembed();

		$this->assertSame( 'Pat Writer', $data['author_name'] );
		$this->assertStringContainsString( 'secret-login', $data['author_url'] );
	}

	public function test_oembed_visitor_loses_author_and_keeps_the_site_name(): void {
		$this->as_role( 'visitor' );
		$data = $this->oembed();

		$this->assertArrayNotHasKey( 'author_name', $data );
		$this->assertArrayNotHasKey( 'author_url', $data );
		$this->assertSame( get_bloginfo( 'name' ), $data['provider_name'] );
		$this->assertArrayHasKey( 'title', $data );
	}

	public function test_oembed_customer_loses_author(): void {
		$this->as_role( 'subscriber' );
		$this->assertArrayNotHasKey( 'author_name', $this->oembed() );
	}

	public function test_oembed_editor_keeps_author(): void {
		$this->as_role( 'editor' );
		$data = $this->oembed();

		$this->assertSame( 'Pat Writer', $data['author_name'] );
		$this->assertArrayHasKey( 'author_url', $data );
	}

	public function test_oembed_filter_ignores_a_non_array(): void {
		$this->assertSame( 'x', \BeltoftHardening\AuthorDetails::oembed( 'x' ) );
	}

	// REST.

	public function test_rest_post_visitor_loses_author_field_and_link(): void {
		$this->as_role( 'visitor' );
		list( $response, $data ) = $this->item( '/wp/v2/posts/' . $this->post_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'author', $data );
		$this->assertArrayNotHasKey( 'author', $response->get_links() );
		$this->assertArrayNotHasKey( 'author', $data['_links'] ?? array() );
		$this->assertSame( $this->post_id, $data['id'] );
		$this->assertArrayHasKey( 'title', $data );
		$this->assertArrayHasKey( 'link', $data );
	}

	public function test_rest_page_visitor_loses_author(): void {
		$this->as_role( 'visitor' );
		list( , $data ) = $this->item( '/wp/v2/pages/' . $this->page_id );

		$this->assertArrayNotHasKey( 'author', $data );
		$this->assertArrayNotHasKey( 'author', $data['_links'] ?? array() );
	}

	public function test_rest_collection_visitor_loses_author(): void {
		$this->as_role( 'visitor' );
		list( , $data ) = $this->item( '/wp/v2/posts' );

		$this->assertNotEmpty( $data );
		foreach ( $data as $item ) {
			$this->assertArrayNotHasKey( 'author', $item );
		}
	}

	public function test_rest_embed_has_no_author_to_embed(): void {
		$this->as_role( 'visitor' );
		list( , $data ) = $this->item( '/wp/v2/posts/' . $this->post_id, array( '_embed' => 1 ) );

		$this->assertArrayNotHasKey( 'author', $data['_embedded'] ?? array() );
	}

	public function test_rest_customer_loses_author(): void {
		$this->as_role( 'subscriber' );
		list( , $data ) = $this->item( '/wp/v2/posts/' . $this->post_id );

		$this->assertArrayNotHasKey( 'author', $data );
	}

	public function test_rest_editor_keeps_author_in_view_and_edit_context(): void {
		$this->as_role( 'editor' );

		list( , $view ) = $this->item( '/wp/v2/posts/' . $this->post_id );
		$this->assertSame( $this->author_id, $view['author'] );
		$this->assertArrayHasKey( 'author', $view['_links'] );

		list( , $edit ) = $this->item( '/wp/v2/posts/' . $this->post_id, array( 'context' => 'edit' ) );
		$this->assertSame( $this->author_id, $edit['author'] );
	}

	public function test_rest_setting_off_keeps_author_for_visitors(): void {
		$this->turn_off( Settings::AUTHOR_DETAILS );
		$this->as_role( 'visitor' );
		list( , $data ) = $this->item( '/wp/v2/posts/' . $this->post_id );

		$this->assertSame( $this->author_id, $data['author'] );
	}

	public function test_rest_other_public_types_with_an_author_are_covered(): void {
		register_post_type(
			'bhk_note',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'author' ),
			)
		);
		$note = self::factory()->post->create( array( 'post_type' => 'bhk_note', 'post_author' => $this->author_id, 'post_status' => 'publish' ) );

		$this->as_role( 'visitor' );
		list( $response, $data ) = $this->item( '/wp/v2/bhk_note/' . $note );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'author', $data );
	}

	public function test_a_type_without_author_support_is_left_alone(): void {
		register_post_type(
			'bhk_plain',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title' ),
			)
		);
		$plain = self::factory()->post->create( array( 'post_type' => 'bhk_plain', 'post_status' => 'publish' ) );

		$this->as_role( 'visitor' );
		list( $response, $data ) = $this->item( '/wp/v2/bhk_plain/' . $plain );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $plain, $data['id'] );
		$this->assertFalse( has_filter( 'rest_prepare_bhk_plain', array( \BeltoftHardening\AuthorDetails::class, 'rest_item' ) ) );
	}

	public function test_the_capability_can_be_changed(): void {
		add_filter( 'beltoft_hardening_author_details_capability', static fn() => 'manage_options' );

		$this->as_role( 'editor' );
		$this->assertArrayNotHasKey( 'author_name', $this->oembed() );

		$this->as_role( 'administrator' );
		$this->assertArrayHasKey( 'author_name', $this->oembed() );
	}

	public function test_rest_item_ignores_something_that_is_not_a_response(): void {
		$this->assertSame( 'x', \BeltoftHardening\AuthorDetails::rest_item( 'x' ) );
	}
}
