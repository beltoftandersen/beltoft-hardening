<?php
/**
 * The single option, read and sanitized.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * Settings store.
 */
final class Settings {

	public const OPTION = 'beltoft_hardening_settings';

	public const REST_USERS      = 'rest_users';
	public const AUTHOR_ARCHIVES = 'author_archives';
	public const UNIFORM_ERRORS  = 'uniform_errors';
	public const ADMIN_LOGIN     = 'admin_login';
	public const AUTHOR_DETAILS  = 'author_details';
	public const MESSAGE         = 'message';

	// Sixth control: hide the login screen and the admin area from visitors who are not signed in. OFF by default.
	public const HIDE_LOGIN        = 'hide_login';
	public const ENTRY_SLUG        = 'entry_slug';
	public const PUBLIC_LOGIN_URL  = 'public_login_url';
	public const ALLOW_LOSTPASSWORD = 'allow_lostpassword';

	/**
	 * The checkbox keys. All default to on.
	 *
	 * @return string[]
	 */
	public static function toggles(): array {
		return array( self::REST_USERS, self::AUTHOR_ARCHIVES, self::UNIFORM_ERRORS, self::ADMIN_LOGIN, self::AUTHOR_DETAILS );
	}

	/**
	 * Whether the hide-login control is switched on in the settings. It defaults to OFF, unlike the five toggles.
	 * Whether it is actually in force also depends on the constant, multisite and a valid address: see HideLogin::active().
	 */
	public static function hide_login_enabled(): bool {
		$saved = get_option( self::OPTION, array() );

		return is_array( $saved ) && ! empty( $saved[ self::HIDE_LOGIN ] );
	}

	/**
	 * The staff sign-in address (a path slug), or an empty string.
	 */
	public static function entry_slug(): string {
		$saved = get_option( self::OPTION, array() );

		return is_array( $saved ) && isset( $saved[ self::ENTRY_SLUG ] ) ? self::clean_slug( (string) $saved[ self::ENTRY_SLUG ] ) : '';
	}

	/**
	 * The address visitors who are not staff are sent to for signing in. Empty setting: the My Account page when
	 * WooCommerce is active, else the home page.
	 */
	public static function public_login_url(): string {
		$saved = get_option( self::OPTION, array() );
		$url   = is_array( $saved ) && isset( $saved[ self::PUBLIC_LOGIN_URL ] ) ? trim( (string) $saved[ self::PUBLIC_LOGIN_URL ] ) : '';

		if ( '' === $url && function_exists( 'wc_get_page_permalink' ) ) {
			$url = (string) wc_get_page_permalink( 'myaccount' );
		}
		if ( '' === $url ) {
			$url = home_url( '/' );
		}

		/**
		 * Filters the address that replaces wp-login.php in links for visitors without the staff entry cookie.
		 *
		 * @param string $url The address.
		 */
		return (string) apply_filters( 'beltoft_hardening_public_login_url', $url );
	}

	/**
	 * Whether wp-login.php?action=lostpassword stays reachable for the public. Setting: '' (automatic), '1' or '0'.
	 * Automatic means on when WooCommerce is not active, because WooCommerce has its own lost-password screen on My
	 * Account, links to it through the `lostpassword_url` filter and sends its own reset email.
	 */
	public static function allow_lostpassword(): bool {
		$saved = get_option( self::OPTION, array() );
		$value = is_array( $saved ) && isset( $saved[ self::ALLOW_LOSTPASSWORD ] ) ? (string) $saved[ self::ALLOW_LOSTPASSWORD ] : '';

		if ( '1' === $value ) {
			return true;
		}
		if ( '0' === $value ) {
			return false;
		}

		return ! class_exists( 'WooCommerce' );
	}

	/**
	 * Letters, digits and hyphens only, lower case, no leading or trailing hyphen.
	 *
	 * @param string $slug Raw slug.
	 */
	public static function clean_slug( string $slug ): string {
		$slug = strtolower( trim( $slug, " \t\n\r\0\x0B/" ) );
		$slug = (string) preg_replace( '/[^a-z0-9-]/', '', $slug );

		return substr( trim( $slug, '-' ), 0, 60 );
	}

	/**
	 * Whether a control is on. A key that was never saved counts as on.
	 *
	 * @param string $key One of the toggle constants.
	 */
	public static function enabled( string $key ): bool {
		$saved = get_option( self::OPTION, array() );

		if ( ! is_array( $saved ) || ! array_key_exists( $key, $saved ) ) {
			return true;
		}

		return ! empty( $saved[ $key ] );
	}

	/**
	 * The text shown for a failed login.
	 */
	public static function message(): string {
		$saved  = get_option( self::OPTION, array() );
		$custom = is_array( $saved ) && isset( $saved[ self::MESSAGE ] ) ? trim( (string) $saved[ self::MESSAGE ] ) : '';
		$text   = '' !== $custom ? $custom : __( 'Incorrect username or password.', 'beltoft-hardening' );

		/**
		 * Filters the generic text shown instead of a specific credential error.
		 *
		 * @param string $text The message, plain text.
		 */
		return (string) apply_filters( 'beltoft_hardening_generic_message', $text );
	}

	/**
	 * Sanitizes what the settings form sends. An unchecked checkbox is absent from the request,
	 * which is why a missing toggle is saved as off here (and not as the default of on).
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = array();

		foreach ( self::toggles() as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$clean[ self::MESSAGE ] = isset( $input[ self::MESSAGE ] ) && is_scalar( $input[ self::MESSAGE ] )
			? sanitize_text_field( wp_unslash( (string) $input[ self::MESSAGE ] ) )
			: '';

		$clean = array_merge( $clean, self::sanitize_hide_login( $input ) );

		return $clean;
	}

	/**
	 * The fields of the hide-login control. Refuses to switch it on when it would lock the owner out.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	private static function sanitize_hide_login( array $input ): array {
		$slug = isset( $input[ self::ENTRY_SLUG ] ) && is_scalar( $input[ self::ENTRY_SLUG ] ) ? self::clean_slug( wp_unslash( (string) $input[ self::ENTRY_SLUG ] ) ) : '';
		$url  = isset( $input[ self::PUBLIC_LOGIN_URL ] ) && is_scalar( $input[ self::PUBLIC_LOGIN_URL ] ) ? esc_url_raw( trim( wp_unslash( (string) $input[ self::PUBLIC_LOGIN_URL ] ) ), array( 'http', 'https' ) ) : '';
		$lost = isset( $input[ self::ALLOW_LOSTPASSWORD ] ) && is_scalar( $input[ self::ALLOW_LOSTPASSWORD ] ) ? (string) $input[ self::ALLOW_LOSTPASSWORD ] : '';
		$lost = in_array( $lost, array( '1', '0' ), true ) ? $lost : '';
		$on   = ! empty( $input[ self::HIDE_LOGIN ] );

		if ( $on ) {
			$problem = self::slug_problem( $slug );
			if ( '' === $problem && is_multisite() ) {
				$problem = __( 'Hiding the login screen is not supported on a multisite network.', 'beltoft-hardening' );
			}
			if ( '' !== $problem ) {
				$on = false;
				self::notice( 'beltoft_hardening_hide_login', $problem . ' ' . __( 'The control was left off.', 'beltoft-hardening' ), 'error' );
			} elseif ( self::slug_collides( $slug ) ) {
				self::notice( 'beltoft_hardening_slug_collision', __( 'A page or post already uses this address. The staff sign-in address takes precedence, so that page can no longer be reached. Choose another address if you need it.', 'beltoft-hardening' ), 'warning' );
			}
		}

		return array(
			self::HIDE_LOGIN         => $on ? 1 : 0,
			self::ENTRY_SLUG         => $slug,
			self::PUBLIC_LOGIN_URL   => $url,
			self::ALLOW_LOSTPASSWORD => $lost,
		);
	}

	/**
	 * Why a slug cannot be used, or an empty string.
	 *
	 * @param string $slug Cleaned slug.
	 */
	public static function slug_problem( string $slug ): string {
		if ( '' === $slug ) {
			return __( 'Enter a staff sign-in address first, otherwise nobody could sign in.', 'beltoft-hardening' );
		}
		if ( 0 === strpos( $slug, 'wp-' ) ) {
			return __( 'The staff sign-in address cannot start with "wp-".', 'beltoft-hardening' );
		}

		return '';
	}

	/**
	 * Whether a published page, post or other public content already lives at this path.
	 *
	 * @param string $slug Cleaned slug.
	 */
	public static function slug_collides( string $slug ): bool {
		return null !== get_page_by_path( $slug, OBJECT, array_values( get_post_types( array( 'public' => true ) ) ) );
	}

	/**
	 * Adds a settings notice, loading the admin template functions when the request is not in the admin.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param string $type    error|warning|success|info.
	 */
	private static function notice( string $code, string $message, string $type ): void {
		if ( ! function_exists( 'add_settings_error' ) && file_exists( ABSPATH . 'wp-admin/includes/template.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, $code, $message, $type );
		}
	}
}
