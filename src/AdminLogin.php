<?php
/**
 * Control: administrators sign in at wp-login.php only.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * Admin sign-in location.
 */
final class AdminLogin {

	/**
	 * Hooks the filter.
	 */
	public static function register(): void {
		add_filter( 'authenticate', array( __CLASS__, 'authenticate' ), 40, 3 );
	}

	/**
	 * The capability that marks an account as an administrator.
	 */
	public static function capability(): string {
		/**
		 * Filters the capability that makes an account sign in at wp-login.php only.
		 *
		 * @param string $capability Default manage_options.
		 */
		return (string) apply_filters( 'beltoft_hardening_admin_capability', 'manage_options' );
	}

	/**
	 * Is this request actually wp-login.php?
	 */
	public static function is_wp_login_request(): bool {
		if ( ! empty( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return true;
		}

		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';

		return 'wp-login.php' === $script;
	}

	/**
	 * Administrators may only sign in at wp-login.php.
	 *
	 * This exists to make two-factor authentication meaningful. Two-factor plugins build their
	 * second-factor step around wp-login.php, but a shop also presents a WooCommerce login form on
	 * My Account and often in the header of every page. A front-end login that authenticates an
	 * administrator without the second factor would make the whole control decorative, so the
	 * front-end form is closed to accounts that can manage the site. Customers are unaffected.
	 *
	 * WHY THE GUARDS ARE WHAT THEY ARE, in order:
	 *
	 * - `$user instanceof WP_User`: earlier filters return a WP_Error for bad credentials, and null
	 *   when they have no opinion. Only a successful authentication is ours to veto. This is also
	 *   why the message can be explicit without enabling enumeration: it is only ever shown to
	 *   somebody who already supplied the correct password.
	 * - empty `$password`: `wp_authenticate_cookie()` also runs on this filter and resolves a user
	 *   from a cookie with no password. Without this guard, every ordinary page view by a logged-in
	 *   administrator would be rejected - an instant self-lockout.
	 * - `REST_REQUEST`: application passwords authenticate here. Exempting them does not weaken the
	 *   account password: an application password is a separate, individually revocable credential
	 *   that cannot be used to sign in interactively, so the password this rule protects still
	 *   cannot be used anywhere but wp-login.php.
	 * - `WP_CLI`: wp-cli is not a web login.
	 *
	 * The refusal has its own error code, which the uniform-error control leaves untouched: it is
	 * not a credential problem, and flattening it would tell the administrator their correct
	 * password is wrong.
	 *
	 * @param null|\WP_User|\WP_Error $user     Authentication result so far.
	 * @param string                  $username Submitted username.
	 * @param string                  $password Submitted password.
	 * @return null|\WP_User|\WP_Error
	 */
	public static function authenticate( $user, $username, $password ) {
		unset( $username );

		if ( ! Settings::enabled( Settings::ADMIN_LOGIN ) || ! $user instanceof \WP_User ) {
			return $user;
		}

		if ( '' === (string) $password ) {
			return $user;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $user;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $user;
		}

		if ( self::is_wp_login_request() ) {
			return $user;
		}

		if ( ! user_can( $user, self::capability() ) ) {
			return $user;
		}

		/**
		 * Filters whether this administrator sign-in is allowed outside wp-login.php.
		 *
		 * @param bool     $allowed Default false.
		 * @param \WP_User $user    The authenticated user.
		 */
		if ( apply_filters( 'beltoft_hardening_admin_login_allowed', false, $user ) ) {
			return $user;
		}

		return new \WP_Error(
			'beltoft_hardening_admin_login_location',
			wp_kses(
				sprintf(
					/* translators: %s: link to the WordPress login page. */
					__( 'Administrators sign in at the <a href="%s">WordPress login</a>.', 'beltoft-hardening' ),
					esc_url( wp_login_url() )
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
	}
}
