<?php
/**
 * Control: hide author details from oEmbed and the public REST API.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * Author details in oEmbed and REST content.
 *
 * Two places still give the login name away after the user routes and author archives are closed:
 *
 *   GET /wp-json/oembed/1.0/embed?url=...   ->  "author_name":"Display Name","author_url":"/author/login-name/"
 *   GET /wp-json/wp/v2/posts                ->  "author":1, and a link to /wp/v2/users/1
 *
 * The author URL is built from user_nicename, which WordPress derives from user_login: the slug is
 * effectively the username. The REST author field is only a number, but it is a user ID, and it
 * names the account the other routes were closed to protect.
 */
final class AuthorDetails {

	/**
	 * Hooks the filters.
	 */
	public static function register(): void {
		add_filter( 'oembed_response_data', array( __CLASS__, 'oembed' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_hooks' ) );
	}

	/**
	 * Whether the current request may see author details.
	 *
	 * Keyed on a capability and not merely on being logged in: a shop has mostly customers, who
	 * have no reason to see which accounts wrote the content. Editors keep the details because the
	 * block editor and the WooCommerce admin read the author field.
	 */
	public static function may_see(): bool {
		if ( ! Settings::enabled( Settings::AUTHOR_DETAILS ) ) {
			return true;
		}

		/**
		 * Filters the capability that keeps author details visible in oEmbed and REST content.
		 *
		 * @param string $capability Capability name, default edit_posts.
		 */
		$capability = (string) apply_filters( 'beltoft_hardening_author_details_capability', 'edit_posts' );

		return current_user_can( $capability );
	}

	/**
	 * Drops the author name and URL from an oEmbed reply. The site name stays.
	 *
	 * @param mixed $data oEmbed response data.
	 * @return mixed
	 */
	public static function oembed( $data ) {
		if ( ! is_array( $data ) || self::may_see() ) {
			return $data;
		}

		unset( $data['author_name'], $data['author_url'] );

		return $data;
	}

	/**
	 * Registers the REST filter for every public content type that has an author.
	 *
	 * The filter that core fires for a type is rest_prepare_{type}, so the types are looked up once
	 * the REST server starts, when every custom post type is registered.
	 */
	public static function rest_hooks(): void {
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $type ) {
			if ( post_type_supports( $type, 'author' ) ) {
				add_filter( 'rest_prepare_' . $type, array( __CLASS__, 'rest_item' ) );
			}
		}
	}

	/**
	 * Removes the author field and the author link from one REST item.
	 *
	 * With the link gone, ?_embed has nothing to embed either.
	 *
	 * @param mixed $response The item.
	 * @return mixed
	 */
	public static function rest_item( $response ) {
		if ( ! $response instanceof \WP_REST_Response || self::may_see() ) {
			return $response;
		}

		$data = $response->get_data();

		if ( is_array( $data ) && array_key_exists( 'author', $data ) ) {
			unset( $data['author'] );
			$response->set_data( $data );
		}

		$response->remove_link( 'author' );

		return $response;
	}
}
