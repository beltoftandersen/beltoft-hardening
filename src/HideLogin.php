<?php
/**
 * Control: to a visitor who is not signed in, the login screen and the admin area answer 404.
 *
 * Staff get in through an entry address (a path slug) that sets a short-lived signed cookie; with that cookie
 * wp-login.php and wp-admin behave exactly as WordPress does them. Without it the request is answered by the theme's
 * 404 page, except for the requests the public needs (see LoginActions) and the public front-end endpoints of
 * wp-admin (admin-ajax.php, admin-post.php).
 *
 * WHEN IT RUNS, AND WHY
 *
 * - `init` priority -1000: before every other `init` callback. A single sign-on plugin redirects a GET of
 *   wp-login.php to its identity provider from `init` (priority 10), and anything that redirected first would leak
 *   where the login lives. This is early enough by priority alone.
 * - The 404 itself is sent at `wp_loaded` priority 0, once every plugin and the theme have registered what a normal
 *   page needs (custom post types, shortcodes, builder elements), so the page is the real 404. wp-login.php sets its
 *   test cookie only after `wp_loaded`, so the answer carries no WordPress cookie.
 * - Between the two a `wp_redirect` filter turns any redirect another plugin attempts into the 404 as well (the
 *   plugin then calls exit): in that one case the page is sent before later `init` callbacks ran.
 *
 * Not touched: WP-CLI, cron, REST, XML-RPC, admin-ajax.php, admin-post.php, signed-in users. Not reachable by any
 * plugin: wp-admin/install.php and upgrade.php (WordPress loads no plugins there) and load-styles.php /
 * load-scripts.php (they do not load WordPress).
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * Hide login.
 */
final class HideLogin {

	/**
	 * Whether this request is to be answered with the 404 at wp_loaded.
	 *
	 * @var bool
	 */
	private static bool $pending = false;

	/**
	 * Whether the hooks are in place.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Whether the control is in force: switched on, with a valid address, not disabled by the constant, not multisite.
	 */
	public static function active(): bool {
		if ( defined( 'BELTOFT_HARDENING_DISABLE_HIDE_LOGIN' ) && BELTOFT_HARDENING_DISABLE_HIDE_LOGIN ) {
			return false;
		}

		if ( is_multisite() || ! Settings::hide_login_enabled() ) {
			return false;
		}

		return '' === Settings::slug_problem( Settings::entry_slug() );
	}

	/**
	 * Why the control is not in force although it is switched on in the settings, for the settings screen.
	 */
	public static function disabled_reason(): string {
		if ( defined( 'BELTOFT_HARDENING_DISABLE_HIDE_LOGIN' ) && BELTOFT_HARDENING_DISABLE_HIDE_LOGIN ) {
			return __( 'Switched off by the BELTOFT_HARDENING_DISABLE_HIDE_LOGIN constant in wp-config.php.', 'beltoft-hardening' );
		}
		if ( is_multisite() ) {
			return __( 'Not supported on a multisite network, so it stays off.', 'beltoft-hardening' );
		}

		return '';
	}

	/**
	 * Hooks the control when it is in force. With the control off nothing is registered.
	 */
	public static function register(): void {
		self::unregister();

		if ( ! self::active() ) {
			return;
		}

		self::$registered = true;
		add_action( 'init', array( __CLASS__, 'guard' ), -1000 );
		add_action( 'wp_loaded', array( __CLASS__, 'send_pending' ), 0 );
		add_filter( 'login_url', array( __CLASS__, 'login_url' ), 1000, 3 );
		add_filter( 'register_url', array( __CLASS__, 'public_url_filter' ), 1000 );
		add_filter( 'lostpassword_url', array( __CLASS__, 'lostpassword_url' ), 1000 );
	}

	/**
	 * Removes every hook again (the settings can change within a test, and a request can never need this).
	 */
	public static function unregister(): void {
		remove_action( 'init', array( __CLASS__, 'guard' ), -1000 );
		remove_action( 'wp_loaded', array( __CLASS__, 'send_pending' ), 0 );
		remove_filter( 'login_url', array( __CLASS__, 'login_url' ), 1000 );
		remove_filter( 'register_url', array( __CLASS__, 'public_url_filter' ), 1000 );
		remove_filter( 'lostpassword_url', array( __CLASS__, 'lostpassword_url' ), 1000 );
		remove_filter( 'wp_redirect', array( __CLASS__, 'redirect_to_404' ), -1000 );
		self::$pending    = false;
		self::$registered = false;
	}

	/**
	 * Whether this is a request the control must leave alone.
	 */
	private static function exempt(): bool {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * The current page script (wp-login.php, index.php, admin-post.php ...).
	 */
	private static function pagenow(): string {
		return isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
	}

	/**
	 * Runs first on `init`.
	 */
	public static function guard(): void {
		if ( ! self::$registered || self::exempt() ) {
			return;
		}

		if ( is_user_logged_in() ) {
			if ( self::is_entry_request() ) {
				nocache_headers();
				wp_safe_redirect( admin_url() );
				NotFound::finish();
				return;
			}

			self::roll_cookie();
			return;
		}

		// A visitor from here on.
		self::leave_login_aliases_alone();

		if ( self::is_entry_request() ) {
			self::enter();
			return;
		}

		if ( EntryCookie::present() ) {
			return;
		}

		if ( self::blocked_request() ) {
			self::$pending = true;
			add_filter( 'wp_redirect', array( __CLASS__, 'redirect_to_404' ), -1000 );
		}
	}

	/**
	 * `/login`, `/admin` and `/dashboard` on the front end are redirected by WordPress to the login: not for visitors.
	 */
	private static function leave_login_aliases_alone(): void {
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
	}

	/**
	 * Whether this visitor's request is for something to hide.
	 */
	private static function blocked_request(): bool {
		$pagenow = self::pagenow();

		if ( 'wp-login.php' === $pagenow || AdminLogin::is_wp_login_request() ) {
			// phpcs:disable WordPress.Security.NonceVerification
			return ! LoginActions::is_public( wp_unslash( $_GET ), wp_unslash( $_POST ) );
			// phpcs:enable
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			/**
			 * Filters the wp-admin scripts that answer visitors who are not signed in (public front-end endpoints).
			 *
			 * @param string[] $pages Script names.
			 */
			$public = (array) apply_filters( 'beltoft_hardening_public_admin_pages', array( 'admin-ajax.php', 'admin-post.php' ) );

			return ! in_array( $pagenow, $public, true );
		}

		return false;
	}

	/**
	 * Sends the 404 once WordPress is fully loaded.
	 */
	public static function send_pending(): void {
		if ( self::$pending ) {
			self::$pending = false;
			remove_filter( 'wp_redirect', array( __CLASS__, 'redirect_to_404' ), -1000 );
			NotFound::send();
		}
	}

	/**
	 * A redirect attempted by anyone else while a 404 is pending becomes the 404.
	 *
	 * @param mixed $location Redirect target, ignored.
	 * @return mixed
	 */
	public static function redirect_to_404( $location ) {
		if ( self::$pending ) {
			self::$pending = false;
			NotFound::send();
		}

		return $location;
	}

	/**
	 * Whether the request is for the staff entry address.
	 */
	public static function is_entry_request(): bool {
		if ( 'index.php' !== self::pagenow() || is_admin() ) {
			return false;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$base = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$uri  = rawurldecode( $uri );

		if ( '' !== $base && '/' !== $base ) {
			if ( 0 !== strpos( trailingslashit( $uri ), trailingslashit( $base ) ) ) {
				return false;
			}
			$uri = substr( $uri, strlen( rtrim( $base, '/' ) ) );
		}

		return strtolower( trim( $uri, '/' ) ) === Settings::entry_slug();
	}

	/**
	 * The entry address: set the cookie and go to the login screen.
	 */
	private static function enter(): void {
		EntryCookie::issue();
		nocache_headers();

		$target = site_url( 'wp-login.php', 'login' );
		$back   = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $back ) {
			$target = add_query_arg( 'redirect_to', rawurlencode( $back ), $target );
		}

		wp_redirect( $target, 302, 'Beltoft Hardening' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- built from site_url().
		NotFound::finish();
	}

	/**
	 * A signed-in user in the admin keeps the entry cookie fresh, so the interim login (the modal that appears when the
	 * session expires) still works for a signed-out staff member.
	 */
	private static function roll_cookie(): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( EntryCookie::remaining() * 2 < EntryCookie::lifetime() ) {
			EntryCookie::issue();
		}
	}

	/**
	 * Filters wp_login_url(): visitors without the cookie are sent to the public sign-in address.
	 *
	 * @param mixed $url      Login URL.
	 * @param mixed $redirect Redirect after login.
	 * @param mixed $reauth   Force reauth.
	 * @return mixed
	 */
	public static function login_url( $url, $redirect = '', $reauth = false ) {
		unset( $reauth );

		if ( self::staff_context() ) {
			return $url;
		}

		$public = Settings::public_login_url();

		if ( is_string( $redirect ) && '' !== $redirect ) {
			$public = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $public );
		}

		return $public;
	}

	/**
	 * Filters wp_registration_url().
	 *
	 * @param mixed $url Registration URL.
	 * @return mixed
	 */
	public static function public_url_filter( $url ) {
		return self::staff_context() || isset( LoginActions::allowed()['register'] ) ? $url : Settings::public_login_url();
	}

	/**
	 * Filters wp_lostpassword_url(): kept when the lost-password screen is public.
	 *
	 * @param mixed $url Lost-password URL.
	 * @return mixed
	 */
	public static function lostpassword_url( $url ) {
		return self::staff_context() || isset( LoginActions::allowed()['lostpassword'] ) ? $url : Settings::public_login_url();
	}

	/**
	 * Whether links to wp-login.php are usable for this request: a valid entry cookie, or staff in the admin.
	 */
	private static function staff_context(): bool {
		return EntryCookie::present() || ( is_admin() && is_user_logged_in() );
	}
}
