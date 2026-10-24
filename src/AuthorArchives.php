<?php
/**
 * Control: 404 for author archives.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * Author archives.
 */
final class AuthorArchives {

	/**
	 * Hooks the action.
	 */
	public static function register(): void {
		add_action( 'parse_request', array( __CLASS__, 'block' ) );
	}

	/**
	 * Turns every author request into a 404, before WordPress can redirect and reveal the slug.
	 *
	 * Done at `parse_request` rather than `template_redirect` on purpose. `/?author=1` is an author
	 * query, and WordPress' canonical redirect answers it with `301 Location: /author/admin-login-name/`,
	 * so a handler that runs later has already lost: the username is in the response headers whether
	 * or not the destination then 404s. Rewriting the query vars this early means the redirect never
	 * happens and the request simply becomes a 404.
	 *
	 * Left reachable in wp-admin and REST requests, where author dropdowns rely on it. A site that
	 * shows bylines can switch this control off or allow some requests with the
	 * `beltoft_hardening_block_author_request` filter.
	 *
	 * @param mixed $wp Current WordPress environment.
	 */
	public static function block( $wp ): void {
		if ( ! Settings::enabled( Settings::AUTHOR_ARCHIVES ) ) {
			return;
		}

		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! $wp instanceof \WP ) {
			return;
		}

		$is_author_request = ! empty( $wp->query_vars['author'] )
			|| ! empty( $wp->query_vars['author_name'] )
			|| isset( $_GET['author'] ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $is_author_request ) {
			return;
		}

		/**
		 * Filters whether an author request is turned into a 404.
		 *
		 * @param bool $block Default true.
		 * @param \WP  $wp    The environment, with its query vars.
		 */
		if ( ! apply_filters( 'beltoft_hardening_block_author_request', true, $wp ) ) {
			return;
		}

		$wp->query_vars = array( 'error' => '404' );
	}
}
