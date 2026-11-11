<?php
/**
 * Answers a request with the theme's normal 404 page.
 *
 * It sets up the main query exactly as a request for a path that does not exist, then hands over to WordPress's own
 * template loader, so the status, headers, template and hooks are the ones a random missing URL gets.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * 404 responder.
 */
final class NotFound {

	/**
	 * Replaces `exit`. Tests only.
	 *
	 * @var callable|null
	 */
	public static $terminator = null;

	/**
	 * Ends the request.
	 */
	public static function finish(): void {
		if ( null !== self::$terminator ) {
			call_user_func( self::$terminator );
			return;
		}

		exit;
	}

	/**
	 * Sends the 404 and ends the request.
	 */
	public static function send(): void {
		global $wp, $wp_query;

		// A wp-admin request is answered as the front end would answer it: the theme and its plugins must not see
		// is_admin() (WP_ADMIN is a constant, but the current screen overrides it, as set_current_screen( 'front' ) does).
		if ( is_admin() ) {
			if ( ! class_exists( 'WP_Screen' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			}
			$GLOBALS['current_screen'] = \WP_Screen::get( 'front' );
		}

		if ( $wp instanceof \WP && $wp_query instanceof \WP_Query ) {
			$wp->query_vars     = array( 'error' => '404' );
			$wp->request        = '';
			$wp->matched_rule   = '';
			$wp->matched_query  = '';
			$wp->did_permalink  = false;
			$wp->query_posts();
			$wp_query->set_404();
			$wp->register_globals();
		}

		status_header( 404 );
		nocache_headers();

		if ( $wp instanceof \WP ) {
			$wp->send_headers();
			do_action_ref_array( 'wp', array( &$wp ) );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'HEAD' === $method ) {
			self::finish();
			return;
		}

		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', true );
		}

		// The same loader a front-end request ends in: template_redirect, then the 404 template (block or classic).
		require ABSPATH . WPINC . '/template-loader.php';

		self::finish();
	}
}
