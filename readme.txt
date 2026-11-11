=== Beltoft Hardening ===
Contributors: beltoft
Tags: security, hardening, user enumeration, login, rest api, hide login
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hides administrator login names from user routes, oEmbed and REST, makes login errors uniform, keeps administrators on wp-login.php, and can hide the login screen and admin area from visitors.

== Description ==

Six small controls, each a checkbox under Settings > Hardening. The first five are on by default; the sixth is off until you switch it on:

* **REST user routes**: `/wp/v2/users` is hidden from everyone who cannot list users.
* **Author archives**: `/?author=1` and author archives answer 404 on the front end.
* **Login errors**: one message for a wrong username, email or password.
* **Administrator sign-in**: accounts that can manage the site sign in at wp-login.php only.
* **Author details**: the author name and URL are dropped from oEmbed replies, and the author field and link from public REST posts and pages, for everyone who cannot edit posts.

* **Hide the login** (off by default): a visitor who is not signed in gets a 404 for wp-login.php and wp-admin. Staff sign in through a staff sign-in address you choose.

An optional text field replaces the default message "Incorrect username or password.".

It complements rate limiting, fail2ban and two-factor authentication. Hiding the login is not a secret: the staff sign-in address is guessable, so it is not a substitute for any of those. What it gives is no login form and no password endpoint for the public, and less noise in the logs.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it.
2. Review Settings > Hardening. Nothing else is required.

== Frequently Asked Questions ==

= How does hiding the login work? =

Switch it on under Settings > Hardening after choosing a staff sign-in address, for example `staff`. A visitor who is not signed in gets the theme's normal 404 page for wp-login.php and for wp-admin. Opening `https://example.com/staff/` sets a 15-minute cookie and sends you to the normal login; with the cookie everything works as WordPress does it. While you are signed in and using the admin the cookie is renewed, so the login window that appears when your session expires keeps working. "Log in" links that WordPress prints for visitors point to the public sign-in address instead (default: the My Account page when WooCommerce is active, otherwise the home page).

= What stays reachable for visitors, and why? =

* `admin-ajax.php` and `admin-post.php`: public front-end endpoints used by themes and plugins.
* On wp-login.php: logout, password-protected posts (`postpass`), password-reset links (`rp`, `resetpass`, and the page shown after a reset), privacy confirmations (`confirmaction`), recovery-mode links, and the lost-password screen when the setting allows it (default: on without WooCommerce, off with it, because WooCommerce has its own lost-password screen and email).
* The second step of known two-factor plugins (WP 2FA: `validate_2fa`, `backup_2fa`, `grace_2fa`), so a customer who began signing in on a shop form can finish. Each needs the plugin's own login markers in the request. Add other plugins with the `beltoft_hardening_two_factor_actions` filter, or any action with `beltoft_hardening_public_login_actions`.
* WP-CLI, cron, REST, XML-RPC and everything for signed-in users.
* `wp-admin/install.php`, `wp-admin/upgrade.php`, `load-styles.php` and `load-scripts.php` cannot be reached by a plugin (WordPress loads no plugins there, or does not load WordPress at all), so they behave as before; `install.php` and `upgrade.php` refuse by themselves on an installed, up-to-date site.

The plain login form and the password POST are never public, whatever action name is sent with them.

= Does it work with single sign-on plugins? =

Yes. With the cookie, a GET of wp-login.php reaches the SSO plugin as usual and the return through admin-ajax.php is untouched. Without the cookie the request is answered with the 404 before the plugin can redirect to the identity provider: the control runs first on `init`, and any redirect another plugin attempts while a 404 is pending becomes the 404. In that one case the 404 page is sent before later `init` callbacks have run, so a theme that depends on them may look plainer. SSO "button" mode does not have this.

= I am locked out of the admin. =

Add `define( 'BELTOFT_HARDENING_DISABLE_HIDE_LOGIN', true );` to wp-config.php (above the line "That's all, stop editing"). The control is then off whatever the setting says. Sign in at wp-login.php as usual, fix the setting, then remove the line. Alternatively open the staff sign-in address, or run `wp option patch update beltoft_hardening_settings hide_login 0` with WP-CLI, which is never affected.

= Multisite? =

Not supported. On a multisite network the control stays off and the settings screen says so.

= Why hide the REST user routes? =

`/wp-json/wp/v2/users` lists each user's slug, which is derived from the login name. That is half of a credential pair. Users with the `list_users` capability (administrators) keep the routes, because wp-admin and page builders use them.

= Why 404 the author archives? =

`/?author=1` is answered by WordPress with a redirect to `/author/login-name/`, so the name is in the response headers. The plugin turns the request into a 404 before the redirect can happen. wp-admin and REST requests are not affected. A site that shows author pages should switch this off.

= Why one login error message? =

WordPress says whether the user exists or only the password is wrong, which lets an attacker confirm a username first. Only credential errors are replaced (wrong username, wrong password, unknown email). An invalid or expired password reset link, a registration error or another plugin's message keep their own text. The WooCommerce login form is covered too.

= Why can administrators only sign in at wp-login.php? =

Two-factor plugins build their second step around wp-login.php. A front-end login form, such as the WooCommerce My Account form, would let an administrator in without it. Customers are not affected. Cookie sessions, application passwords over REST and WP-CLI are not affected either.

= Why hide author details from oEmbed and the REST API? =

The oEmbed reply includes the author's name and the URL of the author archive, which is built from the login name. Public posts and pages in the REST API include the author's user ID. Editors still see both, because the block editor needs them. The site name stays in the oEmbed reply.

= Can I change which accounts count as administrators? =

Yes, with the `beltoft_hardening_admin_capability` filter (default `manage_options`). Other filters: `beltoft_hardening_author_details_capability`, `beltoft_hardening_hidden_rest_routes`, `beltoft_hardening_block_author_request`, `beltoft_hardening_credential_error_codes`, `beltoft_hardening_generic_message`, `beltoft_hardening_admin_login_allowed`, and for the hidden login `beltoft_hardening_public_login_actions`, `beltoft_hardening_two_factor_actions`, `beltoft_hardening_public_login_url`, `beltoft_hardening_public_admin_pages` and `beltoft_hardening_entry_lifetime`.

== Changelog ==

= 1.3.0 =
* New optional control: hide the login. A visitor who is not signed in gets the theme's 404 for wp-login.php and wp-admin. Staff sign in through a staff sign-in address that sets a signed 15-minute cookie. Password resets, logout, password-protected posts, privacy confirmations, the known two-factor second steps (WP 2FA), admin-ajax.php and admin-post.php stay reachable. Off by default; cannot be switched on without an address; off whatever the setting when `BELTOFT_HARDENING_DISABLE_HIDE_LOGIN` is defined; not supported on multisite.
* New settings: staff sign-in address, public sign-in address, lost-password screen. New filters listed above.

= 1.2.0 =
* New control: hide author details from oEmbed and the public REST API. The oEmbed author name and URL and the REST author field and link are removed for anyone who cannot edit posts. On by default. Filter `beltoft_hardening_author_details_capability`.

= 1.1.0 =
* Now a standalone plugin with a settings screen (Settings > Hardening), every control a checkbox, all on by default, and an optional custom login message.
* Fixed: an invalid or expired password reset link showed "Incorrect username or password". Only credential errors are replaced now, on wp-login.php and in the WooCommerce login form.
* Strings are English and translatable. Filters added.

= 1.0.0 =
* First release.
