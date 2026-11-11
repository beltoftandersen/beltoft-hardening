<?php
/**
 * Which wp-login.php requests stay reachable for the public.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * The allowlist, and the action a wp-login.php request is for.
 */
final class LoginActions {

	/**
	 * Pseudo-actions for the two screens WordPress shows without an action parameter.
	 */
	public const CHECKEMAIL     = 'checkemail';
	public const PASSWORD_RESET = 'password-reset';

	/**
	 * The action WordPress will run for this request, the way wp-login.php works it out, plus the two screens
	 * (checkemail, password-reset) that carry no action. An empty string means the plain login form.
	 *
	 * @param array<string,mixed> $get  Query string.
	 * @param array<string,mixed> $post Request body.
	 */
	public static function requested( array $get, array $post ): string {
		$raw = $post['action'] ?? ( $get['action'] ?? '' );

		if ( isset( $get['key'] ) ) {
			$raw = 'resetpass';
		}
		if ( isset( $get['checkemail'] ) ) {
			$raw = self::CHECKEMAIL;
		}
		if ( ! is_string( $raw ) ) {
			return '?';
		}

		$action = sanitize_key( wp_unslash( $raw ) );

		if ( '' === $action && isset( $get['password-reset'] ) && 'true' === $get['password-reset'] ) {
			return self::PASSWORD_RESET;
		}
		if ( 'retrievepassword' === $action ) {
			return 'lostpassword';
		}
		if ( 'resetpass' === $action ) {
			return 'rp';
		}

		return $action;
	}

	/**
	 * Actions of known two-factor plugins that post back to wp-login.php, with the request keys each one must carry.
	 * The keys are only a shape check (they keep a bare "?action=validate_2fa" from showing the login form); the plugin
	 * itself verifies its login transaction.
	 *
	 * @return array<string,string[]>
	 */
	public static function two_factor(): array {
		$list = array(
			// WP 2FA (wp-2fa): code step, backup-method screen, grace-period screen.
			'validate_2fa' => array( 'wp-auth-id', 'wp-auth-nonce' ),
			'backup_2fa'   => array( 'wp-auth-id', 'wp-auth-nonce' ),
			'grace_2fa'    => array( 'wp-auth-id', 'wp-auth-nonce' ),
		);

		/**
		 * Filters the second-step actions of two-factor plugins that must keep working for customers who began signing
		 * in on a shop form, without the staff entry cookie. Map action => request keys that must be present.
		 *
		 * @param array<string,string[]> $list Actions.
		 */
		$list = apply_filters( 'beltoft_hardening_two_factor_actions', $list );

		return self::normalize( is_array( $list ) ? $list : array() );
	}

	/**
	 * The full allowlist: action => request keys that must be present.
	 *
	 * @return array<string,string[]>
	 */
	public static function allowed(): array {
		$list = array(
			'logout'              => array(),
			'postpass'            => array(),
			'rp'                  => array(),
			'confirmaction'       => array(),
			'enter_recovery_mode' => array(),
			self::PASSWORD_RESET  => array(),
		);

		if ( Settings::allow_lostpassword() ) {
			$list['lostpassword']   = array();
			$list[ self::CHECKEMAIL ] = array();
		}

		$list = array_merge( $list, self::two_factor() );

		/**
		 * Filters the wp-login.php actions open to visitors without the staff entry cookie. Either a list of action
		 * names, or a map of action => request keys that must be present.
		 *
		 * Default: logout, postpass, rp (and resetpass), confirmaction, enter_recovery_mode, the "password reset done"
		 * screen, lostpassword and its "check your email" screen when the setting allows it, and the known two-factor
		 * second steps. The plain login form and the password POST are never in the list.
		 *
		 * @param array $list Actions.
		 */
		$filtered = apply_filters( 'beltoft_hardening_public_login_actions', $list );

		return self::normalize( is_array( $filtered ) ? $filtered : $list );
	}

	/**
	 * Accepts both a list of names and a map of name => keys.
	 *
	 * @param array<int|string,mixed> $list Raw list.
	 * @return array<string,string[]>
	 */
	private static function normalize( array $list ): array {
		$out = array();

		foreach ( $list as $key => $value ) {
			if ( is_int( $key ) ) {
				if ( is_string( $value ) && '' !== sanitize_key( $value ) ) {
					$out[ sanitize_key( $value ) ] = array();
				}
				continue;
			}

			$out[ sanitize_key( $key ) ] = is_array( $value ) ? array_values( array_filter( array_map( 'strval', $value ) ) ) : array();
		}

		return $out;
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

		// An expired or invalid reset link sends the visitor to the lost-password screen with an error: let that land.
		if ( 'lostpassword' === $action && ! isset( $list['lostpassword'] ) && isset( $list['rp'] ) && isset( $get['error'] ) && in_array( $get['error'], array( 'invalidkey', 'expiredkey' ), true ) && empty( $post ) ) {
			return true;
		}

		if ( '' === $action || ! isset( $list[ $action ] ) ) {
			return false;
		}

		$request = array_merge( $get, $post );
		foreach ( $list[ $action ] as $needed ) {
			if ( ! isset( $request[ $needed ] ) || ! is_scalar( $request[ $needed ] ) || '' === (string) $request[ $needed ] ) {
				return false;
			}
		}

		return true;
	}
}
