=== Beltoft Hardening ===
Contributors: beltoft
Tags: security, hardening, user enumeration, login, rest api
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Closes the routes that reveal an administrator's login name, makes login failures uniform, and keeps administrators on wp-login.php.

== Description ==

Four small controls, each a checkbox under Settings > Hardening and all on by default:

* **REST user routes**: `/wp/v2/users` is hidden from everyone who cannot list users.
* **Author archives**: `/?author=1` and author archives answer 404 on the front end.
* **Login errors**: one message for a wrong username, email or password.
* **Administrator sign-in**: accounts that can manage the site sign in at wp-login.php only.

An optional text field replaces the default message "Incorrect username or password.".

It complements rate limiting, fail2ban and two-factor authentication. It does not hide wp-login.php behind a secret path, which only buys obscurity.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it.
2. Review Settings > Hardening. Nothing else is required.

== Frequently Asked Questions ==

= Why hide the REST user routes? =

`/wp-json/wp/v2/users` lists each user's slug, which is derived from the login name. That is half of a credential pair. Users with the `list_users` capability (administrators) keep the routes, because wp-admin and page builders use them.

= Why 404 the author archives? =

`/?author=1` is answered by WordPress with a redirect to `/author/login-name/`, so the name is in the response headers. The plugin turns the request into a 404 before the redirect can happen. wp-admin and REST requests are not affected. A site that shows author pages should switch this off.

= Why one login error message? =

WordPress says whether the user exists or only the password is wrong, which lets an attacker confirm a username first. Only credential errors are replaced (wrong username, wrong password, unknown email). An invalid or expired password reset link, a registration error or another plugin's message keep their own text. The WooCommerce login form is covered too.

= Why can administrators only sign in at wp-login.php? =

Two-factor plugins build their second step around wp-login.php. A front-end login form, such as the WooCommerce My Account form, would let an administrator in without it. Customers are not affected. Cookie sessions, application passwords over REST and WP-CLI are not affected either.

= Can I change which accounts count as administrators? =

Yes, with the `beltoft_hardening_admin_capability` filter (default `manage_options`). Other filters: `beltoft_hardening_hidden_rest_routes`, `beltoft_hardening_block_author_request`, `beltoft_hardening_credential_error_codes`, `beltoft_hardening_generic_message` and `beltoft_hardening_admin_login_allowed`.

== Changelog ==

= 1.1.0 =
* Now a standalone plugin with a settings screen (Settings > Hardening), every control a checkbox, all on by default, and an optional custom login message.
* Fixed: an invalid or expired password reset link showed "Incorrect username or password". Only credential errors are replaced now, on wp-login.php and in the WooCommerce login form.
* Strings are English and translatable. Filters added.

= 1.0.0 =
* First release.
