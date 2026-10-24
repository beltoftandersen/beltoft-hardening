<?php
/**
 * Plugin Name: Beltoft Hardening
 * Description: Closes the user-enumeration routes an attacker uses to learn the administrator's login name, and makes login failures uniform. Complements the nginx-level hardening in the vhost.
 * Version:     1.0.0
 * Author:      Tapas til Døren
 * Requires PHP: 8.0
 *
 * WHY THIS IS A PLUGIN AND NOT CHILD-THEME CODE
 *
 * Same reasoning as beltoft-webp and beltoft-cache-support: a control that only matters when
 * someone is attacking the site must not disappear because the theme changed. If this lived in
 * the child theme, switching themes would silently re-open enumeration with nothing visibly
 * broken - the worst kind of regression, because nobody would notice until it mattered.
 *
 * WHAT THIS FIXES, AND WHY IT IS WORTH FIXING
 *
 * The site had exactly one account - an administrator - and two ways to learn its login name
 * without any credentials:
 *
 *   GET /wp-json/wp/v2/users   ->  [{"id":1,...,"slug":"admin-login-name",...}]
 *   GET /?author=1             ->  301 to /author/admin-login-name/
 *
 * user_nicename is derived from user_login, so the slug IS effectively the username. That is
 * half of a credential pair handed to an attacker. The usual entry routes for a compromised
 * WordPress site are an unpatched plugin or a guessed administrator password, so the login is
 * the half worth defending.
 *
 * Deliberately NOT done here: hiding wp-login.php behind a secret path. It breaks password
 * resets and integrations, and anyone who can read a Set-Cookie path or a redirect finds it
 * anyway - it buys obscurity, not security. Rate limiting (nginx `limit_req zone=wp_login`) plus
 * fail2ban plus a second factor are the controls that actually hold.
 *
 * @package beltoft-hardening
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remove the REST user routes for anyone not entitled to list users.
 *
 * Keyed on the capability rather than merely on being logged in, so a customer account (this is a
 * shop - most accounts are customers) cannot enumerate staff either. Administrators keep the
 * endpoint, because wp-admin, the Bricks builder and the WooCommerce admin screens use it.
 */
add_filter(
	'rest_endpoints',
	static function ( $endpoints ) {
		if ( current_user_can( 'list_users' ) ) {
			return $endpoints;
		}

		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

		return $endpoints;
	}
);

/**
 * Turn every author request into a 404, before WordPress can redirect and reveal the slug.
 *
 * Done at `parse_request` rather than `template_redirect` on purpose. `/?author=1` is an author
 * query, and WordPress' canonical redirect answers it with `301 Location: /author/admin-login-name/` -
 * so a handler that runs later has already lost: the username is in the response headers whether
 * or not the destination then 404s. Rewriting the query vars this early means the redirect never
 * happens and the request simply becomes a 404.
 *
 * This site has no blog and no bylines, so author archives have nothing to show; there is no
 * legitimate use to break. Left reachable in wp-admin, where the author dropdowns rely on it.
 *
 * @param WP $wp Current WordPress environment.
 * @return void
 */
add_action(
	'parse_request',
	static function ( $wp ) {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! $wp instanceof WP ) {
			return;
		}

		$is_author_request = ! empty( $wp->query_vars['author'] )
			|| ! empty( $wp->query_vars['author_name'] )
			|| isset( $_GET['author'] ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $is_author_request ) {
			return;
		}

		$wp->query_vars = array( 'error' => '404' );
	}
);


/**
 * Was this login refused because an administrator used the wrong form?
 *
 * Shared state, because the generic-message filter below would otherwise overwrite the
 * explanation. WooCommerce funnels its login failures through `login_errors` too
 * (WC_Form_Handler::process_login catches and re-filters), so a blanket replacement there
 * silently swallowed the "use WordPress-login" message and left the admin staring at
 * "wrong username or password" - technically false, and unhelpful.
 *
 * @param bool|null $set Pass true to record it; omit to read.
 * @return bool
 */
function beltoft_admin_login_blocked( $set = null ) {
	static $blocked = false;

	if ( true === $set ) {
		$blocked = true;
	}

	return $blocked;
}

/**
 * One message for every failed login, whichever form it came from.
 *
 * WordPress distinguishes "that user does not exist" from "wrong password", which lets an
 * attacker confirm a username before spending any effort on passwords. Two forms can fail here -
 * wp-login.php and the WooCommerce login on /log-ind/ and in the header dropdown - so both are
 * normalised, or the shop front-end would still answer the question.
 *
 * The trade-off is a genuinely worse message for a customer who mistyped their email: they are
 * told the pair is wrong rather than which half. That is the conventional choice, and the reset
 * link sits next to the form.
 */
add_filter(
	'login_errors',
	static function ( $message ) {
		// Our own refusal is not a credential problem, so it must not be flattened into one.
		if ( beltoft_admin_login_blocked() ) {
			return $message;
		}

		return esc_html__( 'Forkert brugernavn eller adgangskode.', 'bricks' );
	}
);

add_filter(
	'woocommerce_process_login_errors',
	static function ( $errors ) {
		if ( is_wp_error( $errors ) && $errors->get_error_code() ) {
			$replacement = new WP_Error(
				'ttd_login_failed',
				esc_html__( 'Forkert brugernavn eller adgangskode.', 'bricks' )
			);

			return $replacement;
		}

		return $errors;
	}
);

/**
 * Is this request actually wp-login.php?
 *
 * @return bool
 */
function beltoft_is_wp_login_request() {
	if ( ! empty( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
		return true;
	}

	$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';

	return 'wp-login.php' === $script;
}

/**
 * Administrators may only sign in at wp-login.php.
 *
 * This exists to make two-factor authentication meaningful. The `two-factor` plugin builds its
 * second-factor step around wp-login.php, but this site also presents a WooCommerce login form on
 * /log-ind/ AND in the header dropdown of every single page. A front-end login that authenticates
 * an administrator without the second factor would make the whole control decorative, so the
 * front-end form is closed to accounts that can manage the site. Customers are unaffected - they
 * are who that form is for.
 *
 * WHY THE GUARDS ARE WHAT THEY ARE, in order:
 *
 * - `$user instanceof WP_User`: earlier filters return a WP_Error for bad credentials, and null
 *   when they have no opinion. Only a successful authentication is ours to veto. This is also why
 *   the message below can be explicit without enabling enumeration: it is only ever shown to
 *   somebody who already supplied the correct password.
 * - empty `$password`: `wp_authenticate_cookie()` also runs on this filter and resolves a user
 *   from a cookie with no password. Without this guard, every ordinary page view by a logged-in
 *   administrator would be rejected - an instant self-lockout.
 * - `REST_REQUEST`: application passwords authenticate here, and the Bricks MCP integration uses
 *   one. Exempting them does not weaken the account password: an application password is a
 *   separate, individually revocable credential that cannot be used to sign in interactively, so
 *   the password this rule protects still cannot be used anywhere but wp-login.php.
 * - `WP_CLI`: wp-cli is not a web login.
 *
 * @param null|WP_User|WP_Error $user     Authentication result so far.
 * @param string                $username Submitted username.
 * @param string                $password Submitted password.
 * @return null|WP_User|WP_Error
 */
add_filter(
	'authenticate',
	static function ( $user, $username, $password ) {
		if ( ! $user instanceof WP_User ) {
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

		if ( beltoft_is_wp_login_request() ) {
			return $user;
		}

		if ( ! user_can( $user, 'manage_options' ) ) {
			return $user;
		}

		beltoft_admin_login_blocked( true );

		return new WP_Error(
			'ttd_admin_login_location',
			wp_kses_post(
				sprintf(
					/* translators: %s: link to the WordPress login page. */
					__( 'Administratorer skal logge ind via <a href="%s">WordPress-login</a>.', 'bricks' ),
					esc_url( wp_login_url() )
				)
			)
		);
	},
	40,
	3
);
