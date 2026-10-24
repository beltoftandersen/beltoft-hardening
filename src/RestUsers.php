<?php
/**
 * Control: hide the REST user routes.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * REST user routes.
 */
final class RestUsers {

	/**
	 * Hooks the filter.
	 */
	public static function register(): void {
		add_filter( 'rest_endpoints', array( __CLASS__, 'filter' ) );
	}

	/**
	 * Removes the REST user routes for anyone not entitled to list users.
	 *
	 * Keyed on the capability rather than merely on being logged in, so a customer account (a shop
	 * has mostly customers) cannot enumerate staff either. Administrators keep the endpoint,
	 * because wp-admin, page builders and the WooCommerce admin screens use it.
	 *
	 * @param mixed $endpoints REST routes.
	 * @return mixed
	 */
	public static function filter( $endpoints ) {
		if ( ! is_array( $endpoints ) || ! Settings::enabled( Settings::REST_USERS ) || current_user_can( 'list_users' ) ) {
			return $endpoints;
		}

		/**
		 * Filters the REST routes hidden from users who cannot list users.
		 *
		 * @param string[] $routes Route patterns, as keys of the rest_endpoints array.
		 */
		$routes = (array) apply_filters(
			'beltoft_hardening_hidden_rest_routes',
			array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' )
		);

		foreach ( $routes as $route ) {
			unset( $endpoints[ $route ] );
		}

		return $endpoints;
	}
}
