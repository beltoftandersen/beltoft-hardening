<?php
/**
 * Two optional controls, both off by default: disable XML-RPC, disable application passwords.
 *
 * Both are password endpoints that bypass the login screen (and therefore the hidden login and any second factor):
 * XML-RPC validates a username and password in `wp.getUsersBlogs` and other methods, and REST accepts HTTP Basic
 * authentication with an application password.
 *
 * @package BeltoftHardening
 */

namespace BeltoftHardening;

defined( 'ABSPATH' ) || exit;

/**
 * Optional extras.
 */
final class Extras {

	/**
	 * Hooks the controls that are switched on.
	 */
	public static function register(): void {
		remove_filter( 'xmlrpc_enabled', '__return_false' );
		remove_filter( 'wp_is_application_passwords_available', '__return_false' );

		if ( Settings::extra_enabled( Settings::DISABLE_XMLRPC ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
		}
		if ( Settings::extra_enabled( Settings::DISABLE_APP_PASSWORDS ) ) {
			add_filter( 'wp_is_application_passwords_available', '__return_false' );
		}
	}
}
