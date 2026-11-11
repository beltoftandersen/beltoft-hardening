<?php
/**
 * The signed, short-lived entry cookie that lets staff reach wp-login.php and wp-admin.
 *
 * Format: "<expiry>.<hmac>". The expiry is a Unix time; the hmac is SHA-256 over a fixed label and that time, keyed
 * with the WordPress "auth" salt. It carries no user: it is not a login, it only opens the door to the login screen,
 * so rotating the salts (which signs everybody out anyway) invalidates every entry cookie too.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * Entry cookie.
 */
final class EntryCookie {

	public const NAME = 'beltoft_hardening_entry';

	/**
	 * Replaces the real setcookie() call. Tests only.
	 *
	 * @var callable|null
	 */
	public static $sink = null;

	/**
	 * How long a cookie is valid, in seconds.
	 */
	public static function lifetime(): int {
		/**
		 * Filters how long the staff entry cookie stays valid (seconds, 60 or more).
		 *
		 * @param int $seconds Default 900.
		 */
		return max( 60, (int) apply_filters( 'beltoft_hardening_entry_lifetime', 15 * MINUTE_IN_SECONDS ) );
	}

	/**
	 * The signature for an expiry time.
	 *
	 * @param int $expiry Unix time.
	 */
	public static function sign( int $expiry ): string {
		return hash_hmac( 'sha256', 'beltoft-hardening-entry|' . $expiry, wp_salt( 'auth' ) );
	}

	/**
	 * Builds a cookie value.
	 *
	 * @param int|null $expiry Unix time; default now plus the lifetime.
	 */
	public static function value( ?int $expiry = null ): string {
		$expiry = $expiry ?? time() + self::lifetime();

		return $expiry . '.' . self::sign( $expiry );
	}

	/**
	 * Whether a value is a genuine, unexpired entry cookie.
	 *
	 * @param mixed $value Raw cookie value.
	 */
	public static function check( $value ): bool {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{9,11})\.([a-f0-9]{64})$/', $value, $m ) ) {
			return false;
		}

		$expiry = (int) $m[1];
		$now    = time();

		// Expired, or an expiry further ahead than this site ever issues (a forger cannot sign one anyway).
		if ( $expiry <= $now || $expiry > $now + self::lifetime() + DAY_IN_SECONDS ) {
			return false;
		}

		return hash_equals( self::sign( $expiry ), $m[2] );
	}

	/**
	 * Whether this request carries a valid entry cookie.
	 */
	public static function present(): bool {
		return isset( $_COOKIE[ self::NAME ] ) && self::check( wp_unslash( $_COOKIE[ self::NAME ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by check().
	}

	/**
	 * Seconds left on the cookie this request carries, 0 when there is none.
	 */
	public static function remaining(): int {
		if ( ! self::present() ) {
			return 0;
		}

		return max( 0, (int) strtok( wp_unslash( $_COOKIE[ self::NAME ] ), '.' ) - time() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	}

	/**
	 * The cookie path: the shorter of the home path and the WordPress path, so it covers wp-login.php and wp-admin.
	 */
	public static function path(): string {
		$home = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
		$site = defined( 'SITECOOKIEPATH' ) ? SITECOOKIEPATH : '/';

		return strlen( $home ) <= strlen( $site ) ? $home : $site;
	}

	/**
	 * Sends a fresh cookie and makes it visible to the rest of this request.
	 */
	public static function issue(): void {
		$expiry = time() + self::lifetime();
		$value  = self::value( $expiry );
		$args   = array(
			'expires'  => $expiry,
			'path'     => self::path(),
			'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);

		$_COOKIE[ self::NAME ] = $value;

		if ( null !== self::$sink ) {
			call_user_func( self::$sink, self::NAME, $value, $args );
			return;
		}

		if ( ! headers_sent() ) {
			setcookie( self::NAME, $value, $args );
		}
	}
}
