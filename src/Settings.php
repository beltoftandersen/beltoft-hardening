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

	/**
	 * The checkbox keys. All default to on.
	 *
	 * @return string[]
	 */
	public static function toggles(): array {
		return array( self::REST_USERS, self::AUTHOR_ARCHIVES, self::UNIFORM_ERRORS, self::ADMIN_LOGIN, self::AUTHOR_DETAILS );
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

		return $clean;
	}
}
