# Beltoft Hardening

Closes the routes that reveal an administrator's login name, makes login failures uniform, keeps administrators on wp-login.php, hides author details from oEmbed and the public REST API, and can hide the login screen and admin area from visitors. Requires WordPress 6.5 and PHP 8.0. Works with or without WooCommerce.

## Controls

Settings → Hardening. Every control is a checkbox, all on by default.

- **REST user routes.** `/wp/v2/users` exposes each user's slug, which is derived from the login name: half of a credential pair. The routes are hidden from anyone without `list_users`, so a shop's customers cannot enumerate staff either. Administrators keep them (wp-admin and page builders use them).
- **Author archives.** `/?author=1` is answered by WordPress with a 301 to `/author/login-name/`. The request is turned into a 404 at `parse_request`, before the canonical redirect, because a later handler would already have leaked the name in the headers. wp-admin and REST are untouched.
- **Login errors.** WordPress says whether the user exists or only the password is wrong. Credential errors (`invalid_username`, `incorrect_password`, `invalid_email`) are replaced by one message, in wp-login.php and in the WooCommerce login form. Other errors keep their text: an invalid or expired reset link, registration errors, other plugins' refusals, `empty_username` and `empty_password`.
- **Administrator sign-in.** Accounts with `manage_options` can only sign in at wp-login.php, so a front-end form cannot bypass a two-factor step. Cookie sessions (empty password), REST application passwords and WP-CLI are exempt; customers are unaffected.
- **Author details.** Two places still give the name away once the user routes and archives are closed. The oEmbed reply (`/wp-json/oembed/1.0/embed?url=...`) carries `author_name` and `author_url`, and that URL is `/author/login-name/`. Public posts, pages and other REST content types with an author carry an `author` field (a user ID) and a link to `/wp/v2/users/ID`. For anyone who cannot edit posts, the oEmbed author name and URL are dropped (the site name stays) and the REST `author` field and `author` link are removed, so `?_embed` has nothing to embed either. Editors keep them, because the block editor and the WooCommerce admin read the field. Visitors and customers do not see them. Empty uses "Incorrect username or password.".

- **Hide the login (optional, off by default).** A visitor who is not signed in gets the theme's normal 404 for `wp-login.php` and `wp-admin`. This is not a secret path: the staff entry address is guessable. What it gives is no login form and no password endpoint for the public, and less noise. Rate limiting, fail2ban and a second factor are still what hold.
  - **Entry.** Visiting `/<staff-address>/` sets `beltoft_hardening_entry` (`<expiry>.<HMAC-SHA256 with the auth salt>`, HttpOnly, SameSite=Lax, Secure on https, 15 minutes) and redirects to `wp-login.php`. A signed-in visitor goes to the admin. The cookie is renewed whenever a signed-in user loads an admin page, so the interim-login modal keeps working. Rotating the salts invalidates every cookie.
  - **Timing.** The decision is made at `init` priority -1000, before any SSO plugin's `init` redirect; the 404 is sent at `wp_loaded` priority 0 (real theme 404, no WordPress test cookie), or at once if another plugin tries to redirect first. A wp-admin 404 is rendered with the current screen set to `front`, so `is_admin()` is false for the theme.
  - **Stays reachable.** `admin-ajax.php`, `admin-post.php`; on wp-login.php `logout`, `postpass`, `rp`/`resetpass` and the password-reset-done screen, `confirmaction`, `enter_recovery_mode`, `lostpassword` and `checkemail` when the setting allows (default on without WooCommerce, off with it), and the two-factor second steps (`validate_2fa`, `backup_2fa`, `grace_2fa`, each only with `wp-auth-id` and `wp-auth-nonce`). A request carrying `log` or `pwd` is never public, whatever its action, because WordPress runs an unknown action through the password handler. WP-CLI, cron, REST, XML-RPC and signed-in users are untouched. `install.php`, `upgrade.php`, `load-styles.php`, `load-scripts.php` are out of a plugin's reach.
  - **Links.** `login_url`, `register_url` and `lostpassword_url` return the public sign-in address (default My Account with WooCommerce, else home) unless the request has the cookie or is a signed-in user in the admin. The WordPress `/login`, `/admin` and `/dashboard` redirects are removed for visitors.
  - **Way back.** `define( 'BELTOFT_HARDENING_DISABLE_HIDE_LOGIN', true );` in wp-config.php, or `wp option patch update beltoft_hardening_settings hide_login 0`. Multisite: not supported, the control stays off.
  - **Filters.** `beltoft_hardening_public_login_actions`, `beltoft_hardening_two_factor_actions`, `beltoft_hardening_public_login_url`, `beltoft_hardening_public_admin_pages`, `beltoft_hardening_entry_lifetime`.

Not covered: the `?author=ID` filter of the REST posts routes (it answers whether an ID has posts, but no longer shows which ID wrote an item), comment authors, and the author name that themes, feeds and SEO plugins print on purpose.

## Filters

| Filter | Purpose |
|---|---|
| `beltoft_hardening_admin_capability` | Capability that marks an administrator (default `manage_options`) |
| `beltoft_hardening_admin_login_allowed` | Allow an administrator sign-in outside wp-login.php (`bool`, `WP_User`) |
| `beltoft_hardening_hidden_rest_routes` | Routes removed from users who cannot list users |
| `beltoft_hardening_block_author_request` | Return false to let an author request through (`bool`, `WP`) |
| `beltoft_hardening_credential_error_codes` | Error codes replaced by the generic message |
| `beltoft_hardening_generic_message` | The generic message text |
| `beltoft_hardening_author_details_capability` | Capability that keeps author details in oEmbed and REST content (default `edit_posts`) |

## Install

Copy the plugin folder to `wp-content/plugins/` and activate it. Settings are stored in the option `beltoft_hardening_settings` and removed on uninstall.

## Development

```
phpunit   # WordPress test suite; the tests need only WordPress, not WooCommerce
```

License: GPL-2.0-or-later. Author: [beltoft.net](https://beltoft.net).
