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
 * - For wp-admin requests from an anonymous browser (no WordPress login cookie, no entry cookie) the decision is made
 *   when this plugin's main file loads (early()), before plugins that look at is_admin() while they load.
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
		self::remove_hooks();

		if ( ! self::active() ) {
			return;
		}

		self::$registered = true;
		add_action( 'init', array( __CLASS__, 'guard' ), -1000 );
		add_action( 'wp_loaded', array( __CLASS__, 'send_pending' ), 0 );
		add_filter( 'wp_login_errors', array( __CLASS__, 'backstop' ), 0 );
		add_filter( 'login_url', array( __CLASS__, 'login_url' ), 1000, 3 );
		add_filter( 'register_url', array( __CLASS__, 'public_url_filter' ), 1000 );
		add_filter( 'lostpassword_url', array( __CLASS__, 'lostpassword_url' ), 1000 );
	}

	/**
	 * Removes every hook again and forgets the decision for this request (tests; a request never needs this).
	 */
	public static function unregister(): void {
		self::remove_hooks();
		remove_filter( 'wp_redirect', array( __CLASS__, 'redirect_to_404' ), -1000 );
		self::$pending = false;
	}

	/**
	 * Removes the registered hooks, keeping a decision already taken for this request.
	 */
	private static function remove_hooks(): void {
		remove_action( 'init', array( __CLASS__, 'guard' ), -1000 );
		remove_action( 'wp_loaded', array( __CLASS__, 'send_pending' ), 0 );
		remove_filter( 'wp_login_errors', array( __CLASS__, 'backstop' ), 0 );
		remove_filter( 'login_url', array( __CLASS__, 'login_url' ), 1000 );
		remove_filter( 'register_url', array( __CLASS__, 'public_url_filter' ), 1000 );
		remove_filter( 'lostpassword_url', array( __CLASS__, 'lostpassword_url' ), 1000 );
		self::$registered = false;
	}

	/**
	 * The capability that makes a signed-in user "staff": only they get the entry cookie.
	 */
	public static function staff_capability(): string {
		/**
		 * Filters the capability of the users who get the staff entry cookie while signed in.
		 *
		 * @param string $capability Default edit_posts (customers and subscribers do not have it).
		 */
		return (string) apply_filters( 'beltoft_hardening_staff_capability', 'edit_posts' );
	}

	/**
	 * Whether this is a request to a script inside wp-admin. is_admin() is not enough: async-upload.php loads WordPress
	 * before it defines WP_ADMIN, and the 404 itself switches the current screen to the front end.
	 */
	private static function is_admin_script(): bool {
		if ( ( defined( 'WP_ADMIN' ) && WP_ADMIN ) || is_admin() ) {
			return true;
		}

		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';

		return (bool) preg_match( '#/wp-admin/(?:[^/]+/)*[^/]+\.php$#', $script );
	}

	/**
	 * Whether the wp-admin script is a public front-end endpoint.
	 */
	private static function is_public_admin_script(): bool {
		/**
		 * Filters the wp-admin scripts that answer visitors who are not signed in (public front-end endpoints).
		 *
		 * @param string[] $pages Script names.
		 */
		$public = (array) apply_filters( 'beltoft_hardening_public_admin_pages', array( 'admin-ajax.php', 'admin-post.php' ) );

		return wp_doing_ajax() || in_array( self::pagenow(), $public, true );
	}

	/**
	 * Called while the plugin's main file loads, before the plugins that sort after it: an anonymous request to wp-admin
	 * is marked here and made to look like a front-end request (current screen 'front', pagenow index.php), so plugins
	 * that decide by is_admin() while loading (WooCommerce) load what a normal 404 loads. It only decides when the
	 * browser sends neither a WordPress login cookie nor an entry cookie, so no cookie has to be verified (that needs
	 * functions that load later).
	 */
	public static function early(): void {
		if ( ! self::active() || self::exempt() ) {
			return;
		}
		if ( ( defined( 'LOGGED_IN_COOKIE' ) && isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) || isset( $_COOKIE[ EntryCookie::NAME ] ) ) {
			return;
		}
		if ( ! self::is_admin_script() || self::is_public_admin_script() ) {
			return;
		}

		self::mark_pending();
	}

	/**
	 * Marks the request for the 404 and makes it look like a front-end request to everything that runs before it.
	 */
	private static function mark_pending(): void {
		self::$pending = true;
		add_filter( 'wp_redirect', array( __CLASS__, 'redirect_to_404' ), -1000 );

		// Plugins that load assets or redirect by $pagenow behave as on the front end (an SSO plugin does not redirect).
		$GLOBALS['pagenow'] = 'index.php';
		NotFound::front_screen();
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
		if ( ! self::$registered || self::exempt() || self::$pending ) {
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

		// A visitor from here on. The WordPress /login, /admin and /dashboard redirects would name the login.
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );

		if ( self::is_entry_request() ) {
			self::enter();
			return;
		}

		if ( EntryCookie::present() ) {
			return;
		}

		$page = self::pagenow();
		if ( 'wp-login.php' === $page || AdminLogin::is_wp_login_request() ) {
			// phpcs:disable WordPress.Security.NonceVerification
			$get  = wp_unslash( $_GET );
			$post = wp_unslash( $_POST );
			// phpcs:enable

			// Recovery mode (the link in the fatal-error email) proves admin access: let that visitor on to the login.
			if ( isset( $get['action'] ) && 'entered_recovery_mode' === $get['action'] && apply_filters( 'beltoft_hardening_recovery_mode_active', function_exists( 'wp_is_recovery_mode' ) && wp_is_recovery_mode() ) ) {
				EntryCookie::issue();
				return;
			}

			if ( LoginActions::is_public( $get, $post ) ) {
				return;
			}

			self::send_away( $get, $post );
			self::mark_pending();
			return;
		}

		if ( self::is_admin_script() && ! self::is_public_admin_script() ) {
			self::mark_pending();
		}
	}

	/**
	 * The two blocked wp-login.php requests that have a better answer than the 404: the screen after a logout (a
	 * customer is sent to the public sign-in address, as other logout redirects do) and the landing for an expired reset
	 * link (sent to the lost-password page that works: WooCommerce's, or the public address).
	 *
	 * @param array<string,mixed> $get  Query string.
	 * @param array<string,mixed> $post Request body.
	 */
	private static function send_away( array $get, array $post ): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$target = '';

		if ( empty( $post ) && in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			if ( isset( $get['loggedout'] ) && 'true' === $get['loggedout'] && 'login' === LoginActions::effective( $get, $post ) ) {
				$target = Settings::public_login_url();
			} elseif ( LoginActions::is_reset_error_landing( $get, $post ) ) {
				$target = wp_lostpassword_url();
			}
		}

		if ( '' !== $target ) {
			nocache_headers();
			wp_redirect( $target, 302, 'Beltoft Hardening' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- a site setting.
			NotFound::finish();
		}
	}

	/**
	 * Backstop on the one filter wp-login.php runs right before it draws the login form: a visitor without the cookie
	 * never gets the form, whatever action got them here.
	 *
	 * @param mixed $errors Login errors, passed through.
	 * @return mixed
	 */
	public static function backstop( $errors ) {
		if ( ! self::$registered || self::exempt() || is_user_logged_in() || EntryCookie::present() || 'wp-login.php' !== self::pagenow() ) {
			return $errors;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		if ( 'login' !== LoginActions::effective( wp_unslash( $_GET ), wp_unslash( $_POST ) ) ) {
			return $errors;
		}
		// phpcs:enable

		if ( ! headers_sent() ) {
			header_remove( 'Set-Cookie' );
		}
		NotFound::send();

		return $errors;
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

		// The login modal that appears when a session expires opens the entry address with these (see login_url()).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( array( 'interim-login', 'reauth' ) as $flag ) {
			if ( isset( $_GET[ $flag ] ) && '1' === $_GET[ $flag ] ) {
				$target = add_query_arg( $flag, '1', $target );
			}
		}
		if ( isset( $_GET['wp_lang'] ) && is_string( $_GET['wp_lang'] ) && preg_match( '/^[A-Za-z_]{2,10}$/', wp_unslash( $_GET['wp_lang'] ) ) ) {
			$target = add_query_arg( 'wp_lang', wp_unslash( $_GET['wp_lang'] ), $target );
		}
		// phpcs:enable

		wp_redirect( $target, 302, 'Beltoft Hardening' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- built from site_url().
		NotFound::finish();
	}

	/**
	 * A signed-in user in the admin keeps the entry cookie fresh, so the interim login (the modal that appears when the
	 * session expires) still works for a signed-out staff member.
	 */
	private static function roll_cookie(): void {
		if ( ! is_admin() || ! current_user_can( self::staff_capability() ) ) {
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

		if ( self::is_staff_in_admin() ) {
			// The login modal (session expired) is drawn in the admin footer: point it at the entry address, which
			// renews the cookie and carries on to the login, so it works even when the cookie has expired meanwhile.
			if ( doing_action( 'admin_print_footer_scripts' ) || doing_action( 'admin_footer' ) || doing_action( 'wp_print_footer_scripts' ) || doing_action( 'customize_controls_print_footer_scripts' ) ) {
				return home_url( '/' . Settings::entry_slug() . '/' );
			}

			return $url;
		}

		if ( EntryCookie::present() ) {
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
		return self::keeps_url( $url ) || isset( LoginActions::allowed()['register'] ) ? $url : Settings::public_login_url();
	}

	/**
	 * Filters wp_lostpassword_url(): kept when the lost-password screen is public.
	 *
	 * @param mixed $url Lost-password URL.
	 * @return mixed
	 */
	public static function lostpassword_url( $url ) {
		return self::keeps_url( $url ) || isset( LoginActions::allowed()['lostpassword'] ) ? $url : Settings::public_login_url();
	}

	/**
	 * Whether a URL is left alone: staff context, or a URL another plugin already moved away from wp-login.php (the
	 * WooCommerce lost-password page).
	 *
	 * @param mixed $url URL.
	 */
	private static function keeps_url( $url ): bool {
		return self::staff_context() || ( is_string( $url ) && false === strpos( $url, 'wp-login.php' ) );
	}

	/**
	 * Staff in the admin: signed in with the staff capability.
	 */
	private static function is_staff_in_admin(): bool {
		return is_admin() && is_user_logged_in() && current_user_can( self::staff_capability() );
	}

	/**
	 * Whether links to wp-login.php are usable for this request: a valid entry cookie, or staff in the admin.
	 */
	private static function staff_context(): bool {
		return EntryCookie::present() || self::is_staff_in_admin();
	}
}
