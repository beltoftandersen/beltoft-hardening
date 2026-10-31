<?php
/**
 * Plugin Name:       Beltoft Hardening
 * Plugin URI:        https://beltoft.net/
 * Description:       Closes the user-enumeration routes an attacker uses to learn an administrator's login name, makes login failures uniform, keeps administrators on wp-login.php, and hides author details from oEmbed and the public REST API.
 * Version:           1.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            beltoft.net
 * Author URI:        https://beltoft.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beltoft-hardening
 * Domain Path:       /languages
 *
 * WHY THIS IS A PLUGIN AND NOT THEME CODE
 *
 * A control that only matters when someone is attacking the site must not disappear because the
 * theme changed. If this lived in a theme, switching themes would silently re-open enumeration
 * with nothing visibly broken - the worst kind of regression, because nobody would notice until it
 * mattered.
 *
 * WHAT THIS FIXES, AND WHY IT IS WORTH FIXING
 *
 * A default WordPress site gives two ways to learn an administrator's login name without any
 * credentials:
 *
 *   GET /wp-json/wp/v2/users   ->  [{"id":1,...,"slug":"admin-login-name",...}]
 *   GET /?author=1             ->  301 to /author/admin-login-name/
 *
 * user_nicename is derived from user_login, so the slug IS effectively the username. That is half
 * of a credential pair handed to an attacker. The usual entry routes for a compromised WordPress
 * site are an unpatched plugin or a guessed administrator password, so the login is the half worth
 * defending.
 *
 * Two more places repeat it once those are closed: the oEmbed reply carries author_name and
 * author_url (/author/login-name/), and every public post and page in the REST API carries an
 * author ID and a link to /wp/v2/users/ID. AuthorDetails removes both for anyone who cannot edit
 * posts.
 *
 * Deliberately NOT done here: hiding wp-login.php behind a secret path. It breaks password resets
 * and integrations, and anyone who can read a Set-Cookie path or a redirect finds it anyway - it
 * buys obscurity, not security. Rate limiting (for example nginx `limit_req`) plus fail2ban plus a
 * second factor are the controls that actually hold. This plugin complements them.
 *
 * Every control can be switched off under Settings > Hardening.
 *
 * @package BeltoftHardening
 */

defined( 'ABSPATH' ) || exit;

define( 'BELTOFT_HARDENING_FILE', __FILE__ );
define( 'BELTOFT_HARDENING_PATH', plugin_dir_path( __FILE__ ) );
define( 'BELTOFT_HARDENING_VERSION', '1.2.0' );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'BeltoftHardening\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file     = BELTOFT_HARDENING_PATH . 'src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action( 'plugins_loaded', array( \BeltoftHardening\Plugin::class, 'boot' ) );
