<?php
/**
 * Control: uniform login errors.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * Uniform login errors.
 */
final class LoginErrors {

	/**
	 * Error codes of the last failed sign-in in this request.
	 *
	 * @var string[]
	 */
	private static array $failed_codes = array();

	/**
	 * Hooks the filters.
	 */
	public static function register(): void {
		add_action( 'wp_login_failed', array( __CLASS__, 'record' ), 10, 2 );
		add_filter( 'login_errors', array( __CLASS__, 'login_errors' ) );
		add_filter( 'woocommerce_process_login_errors', array( __CLASS__, 'woocommerce' ) );
	}

	/**
	 * Forgets the recorded failure (used by tests).
	 */
	public static function reset(): void {
		self::$failed_codes = array();
	}

	/**
	 * The error codes that tell an attacker which half of the credentials was wrong.
	 *
	 * `empty_username` and `empty_password` are left alone: they say nothing about any account.
	 * Everything else (an invalid or expired reset link, a registration error, another plugin's
	 * refusal, our own admin refusal) is not a credential problem and must keep its own message.
	 *
	 * @return string[]
	 */
	public static function credential_codes(): array {
		/**
		 * Filters the error codes that are replaced by the generic message.
		 *
		 * @param string[] $codes Error codes.
		 */
		return (array) apply_filters(
			'beltoft_hardening_credential_error_codes',
			array( 'invalid_username', 'incorrect_password', 'invalid_email' )
		);
	}

	/**
	 * Whether an error carries a credential code.
	 *
	 * @param mixed $error A WP_Error.
	 */
	public static function has_credential_code( $error ): bool {
		return $error instanceof \WP_Error && array() !== array_intersect( $error->get_error_codes(), self::credential_codes() );
	}

	/**
	 * Remembers why a sign-in failed. WooCommerce's login form shows the failure through
	 * `login_errors` as a bare string, so the codes are not otherwise available there.
	 *
	 * @param string          $username Submitted username.
	 * @param \WP_Error|mixed $error    The failure (passed since WordPress 5.4).
	 */
	public static function record( $username, $error = null ): void {
		unset( $username );

		if ( $error instanceof \WP_Error ) {
			self::$failed_codes = $error->get_error_codes();
		}
	}

	/**
	 * One message for a failed credential check, whichever form it came from.
	 *
	 * WordPress distinguishes "that user does not exist" from "wrong password", which lets an
	 * attacker confirm a username before spending any effort on passwords. Both wp-login.php and a
	 * WooCommerce login form can fail here, so both are normalised, or a shop front end would still
	 * answer the question.
	 *
	 * Only credential errors are flattened. The `login_errors` filter runs on EVERY message the
	 * login page prints, including "the password reset link is invalid or has expired", and
	 * replacing those would send a customer round in circles. The codes come from the global
	 * `$errors` of wp-login.php, or from the last failed sign-in (the WooCommerce form).
	 *
	 * The trade-off is a genuinely worse message for a customer who mistyped their email: they are
	 * told the pair is wrong rather than which half. That is the conventional choice, and the
	 * reset link sits next to the form.
	 *
	 * @param mixed $message The error text.
	 * @return mixed
	 */
	public static function login_errors( $message ) {
		if ( ! Settings::enabled( Settings::UNIFORM_ERRORS ) ) {
			return $message;
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$page_errors = $GLOBALS['errors'] ?? null;
		$codes       = array_merge( self::$failed_codes, $page_errors instanceof \WP_Error ? $page_errors->get_error_codes() : array() );

		if ( array() === array_intersect( $codes, self::credential_codes() ) ) {
			return $message;
		}

		return esc_html( Settings::message() );
	}

	/**
	 * Replaces a credential failure reported to WooCommerce's login form.
	 *
	 * Other errors (a captcha, another plugin's validation) pass unchanged. WooCommerce raises its
	 * own "Username is required." itself and does not pass it through here.
	 *
	 * @param mixed $errors A WP_Error.
	 * @return mixed
	 */
	public static function woocommerce( $errors ) {
		if ( ! Settings::enabled( Settings::UNIFORM_ERRORS ) || ! self::has_credential_code( $errors ) ) {
			return $errors;
		}

		return new \WP_Error( 'beltoft_hardening_login_failed', esc_html( Settings::message() ) );
	}
}
