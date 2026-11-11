<?php
/**
 * Which wp-login.php requests stay reachable for the public.
 *
 * Actions are compared exactly as wp-login.php compares them (case-sensitive, nothing normalised): an action that
 * WordPress would not recognise is, for WordPress, the login form.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * The allowlist, and the action a wp-login.php request is for.
 */
final class LoginActions {

	public const CHECKEMAIL = 'checkemail';

	/**
	 * The actions with a `case` in wp-login.php's switch. Every other action (a plugin's, recovery mode's) is handled by
	 * a `login_form_<action>` hook that exits, or else falls through to `default`, which is the login form.
	 *
	 * @var string[]
	 */
	private const CORE = array( 'confirm_admin_email', 'postpass', 'logout', 'lostpassword', 'retrievepassword', 'resetpass', 'rp', 'register', 'checkemail', 'confirmaction', 'login' );

	/**
	 * The raw action of the request, the way wp-login.php works it out (POST wins over GET, then key and checkemail).
	 * 'retrievepassword' and 'resetpass' are WordPress aliases and are folded into their main action.
	 *
	 * @param array<string,mixed> $get  Query string.
	 * @param array<string,mixed> $post Request body.
	 */
	public static function requested( array $get, array $post ): string {
		$raw = $post['action'] ?? ( $get['action'] ?? 'login' );

		if ( isset( $get['key'] ) ) {
			$raw = 'resetpass';
		}
		if ( isset( $get['checkemail'] ) ) {
			$raw = self::CHECKEMAIL;
		}
		if ( ! is_string( $raw ) ) {
			return 'login';
		}

		$raw = wp_unslash( $raw );

		if ( 'retrievepassword' === $raw ) {
			return 'lostpassword';
		}
		if ( 'resetpass' === $raw ) {
			return 'rp';
		}

		return $raw;
	}

	/**
	 * The branch of wp-login.php that will really run. Anything without a case in its switch, handled or not, ends in
	 * the login form (a handler that exits has already answered before that).
	 *
	 * @param array<string,mixed> $get  Query string.
	 * @param array<string,mixed> $post Request body.
	 */
	public static function effective( array $get, array $post ): string {
		$action = self::requested( $get, $post );

		if ( in_array( $action, self::CORE, true ) ) {
			return $action;
		}

		return 'login';
	}

	/**
	 * Actions of known two-factor plugins that post back to wp-login.php, with the request keys each one must carry
	 * (array of keys, or array( 'get' => keys, 'post' => keys )). They only count when the plugin has registered
	 * `login_form_<action>`. The keys are a shape check; the plugin verifies its own login transaction.
	 *
	 * @return array<string,array>
	 */
	public static function two_factor(): array {
		$list = array(
			// WP 2FA: the code step is posted; the backup-method screen is a GET; the grace screen has no wp-login.php handler.
			'validate_2fa' => array( 'post' => array( 'wp-auth-id', 'wp-auth-nonce' ) ),
			'backup_2fa'   => array( 'get' => array( 'wp-auth-id', 'wp-auth-nonce', 'provider' ) ),
			'grace_2fa'    => array( 'post' => array( 'wp-auth-id', 'wp-auth-nonce' ) ),
		);

		/**
		 * Filters the second-step actions of two-factor plugins that must keep working for customers who began signing
		 * in on a shop form, without the staff entry cookie. Map action => keys that must be present (or
		 * array( 'get' => keys, 'post' => keys )). Only used when `login_form_<action>` has a handler.
		 *
		 * @param array<string,array> $list Actions.
		 */
		$list = apply_filters( 'beltoft_hardening_two_factor_actions', $list );

		$out = self::normalize( is_array( $list ) ? $list : array() );
		foreach ( $out as $action => $needs ) {
			$out[ $action ]['handler'] = true;
		}

		return $out;
	}

	/**
	 * The full allowlist: action => requirements ( 'any' keys, 'get' keys, 'post' keys, 'handler' bool ).
	 *
	 * @return array<string,array>
	 */
	public static function allowed(): array {
		$list = array(
			'logout'              => array(),
			'postpass'            => array(),
			'rp'                  => array(),
			'confirmaction'       => array(),
			'enter_recovery_mode' => array( 'get' => array( 'rm_token', 'rm_key' ) ),
		);

		if ( Settings::allow_lostpassword() ) {
			$list['lostpassword']     = array();
			$list[ self::CHECKEMAIL ] = array();
		}

		$list = array_merge( $list, self::two_factor() );

		/**
		 * Filters the wp-login.php actions open to visitors without the staff entry cookie. Either a list of action
		 * names, or a map of action => request keys that must be present.
		 *
		 * Default: logout (with a valid nonce), postpass, rp (and resetpass), confirmaction, enter_recovery_mode (with
		 * its token and key), lostpassword and its "check your email" screen when the setting allows it, and the known
		 * two-factor second steps. The plain login form and the password POST are never in the list.
		 *
		 * @param array $list Actions.
		 */
		$filtered = apply_filters( 'beltoft_hardening_public_login_actions', $list );

		return self::normalize( is_array( $filtered ) ? $filtered : $list );
	}

	/**
	 * Accepts a list of names, a map of name => keys, and a map of name => array( 'get'|'post' => keys ).
	 *
	 * @param array<int|string,mixed> $list Raw list.
	 * @return array<string,array>
	 */
	private static function normalize( array $list ): array {
		$out = array();

		foreach ( $list as $key => $value ) {
			if ( is_int( $key ) ) {
				if ( is_string( $value ) && '' !== $value ) {
					$out[ $value ] = array();
				}
				continue;
			}

			$needs = array();
			if ( is_array( $value ) ) {
				if ( isset( $value['get'] ) || isset( $value['post'] ) || isset( $value['handler'] ) || isset( $value['any'] ) ) {
					if ( ! empty( $value['any'] ) ) {
						$needs['any'] = array_values( array_map( 'strval', (array) $value['any'] ) );
					}
					foreach ( array( 'get', 'post' ) as $src ) {
						if ( ! empty( $value[ $src ] ) ) {
							$needs[ $src ] = array_values( array_map( 'strval', (array) $value[ $src ] ) );
						}
					}
					if ( ! empty( $value['handler'] ) ) {
						$needs['handler'] = true;
					}
				} else {
					$needs['any'] = array_values( array_map( 'strval', $value ) );
				}
			}
			$out[ (string) $key ] = $needs;
		}

		return $out;
	}

	/**
	 * Whether every key is a non-empty scalar in the given source.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param string[]            $keys   Keys.
	 */
	private static function has_all( array $source, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) || '' === (string) $source[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The landing WordPress sends an expired or invalid reset link to.
	 *
	 * @param array<string,mixed> $get  Query string.
	 * @param array<string,mixed> $post Request body.
	 */
	public static function is_reset_error_landing( array $get, array $post ): bool {
		return empty( $post ) && 'lostpassword' === self::requested( $get, $post ) && isset( $get['error'] ) && in_array( $get['error'], array( 'invalidkey', 'expiredkey' ), true );
	}

	/**
	 * Whether a request may reach wp-login.php without the entry cookie.
	 *
	 * @param array<string,mixed> $get  Query string.
	 * @param array<string,mixed> $post Request body.
	 */
	public static function is_public( array $get, array $post ): bool {
		// Credentials never reach the password handler for the public, whatever the action says: WordPress runs an
		// unknown action through the login form code too, so an allowlisted plugin action must not be a back door.
		if ( isset( $post['log'] ) || isset( $post['pwd'] ) || isset( $get['log'] ) || isset( $get['pwd'] ) ) {
			return false;
		}

		$action = self::requested( $get, $post );
		$list   = self::allowed();

		if ( ! isset( $list[ $action ] ) ) {
			return false;
		}

		$needs = $list[ $action ];

		if ( ! empty( $needs['handler'] ) && false === has_filter( 'login_form_' . $action ) ) {
			return false;
		}
		if ( isset( $needs['any'] ) && ! self::has_all( array_merge( $get, $post ), $needs['any'] ) ) {
			return false;
		}
		if ( isset( $needs['get'] ) && ! self::has_all( $get, $needs['get'] ) ) {
			return false;
		}
		if ( isset( $needs['post'] ) && ! self::has_all( $post, $needs['post'] ) ) {
			return false;
		}

		// A logout needs the nonce WordPress makes for it. Without one WordPress answers 403 "Are you sure?".
		if ( 'logout' === $action && ( ! isset( $get['_wpnonce'] ) || ! is_string( $get['_wpnonce'] ) || ! wp_verify_nonce( $get['_wpnonce'], 'log-out' ) ) ) {
			return false;
		}

		return true;
	}
}
