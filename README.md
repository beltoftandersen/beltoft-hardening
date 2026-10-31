# Beltoft Hardening

Closes the routes that reveal an administrator's login name, makes login failures uniform, keeps administrators on wp-login.php, and hides author details from oEmbed and the public REST API. Requires WordPress 6.5 and PHP 8.0. Works with or without WooCommerce.

## Controls

Settings → Hardening. Every control is a checkbox, all on by default.

- **REST user routes.** `/wp/v2/users` exposes each user's slug, which is derived from the login name: half of a credential pair. The routes are hidden from anyone without `list_users`, so a shop's customers cannot enumerate staff either. Administrators keep them (wp-admin and page builders use them).
- **Author archives.** `/?author=1` is answered by WordPress with a 301 to `/author/login-name/`. The request is turned into a 404 at `parse_request`, before the canonical redirect, because a later handler would already have leaked the name in the headers. wp-admin and REST are untouched.
- **Login errors.** WordPress says whether the user exists or only the password is wrong. Credential errors (`invalid_username`, `incorrect_password`, `invalid_email`) are replaced by one message, in wp-login.php and in the WooCommerce login form. Other errors keep their text: an invalid or expired reset link, registration errors, other plugins' refusals, `empty_username` and `empty_password`.
- **Administrator sign-in.** Accounts with `manage_options` can only sign in at wp-login.php, so a front-end form cannot bypass a two-factor step. Cookie sessions (empty password), REST application passwords and WP-CLI are exempt; customers are unaffected.
- **Author details.** Two places still give the name away once the user routes and archives are closed. The oEmbed reply (`/wp-json/oembed/1.0/embed?url=...`) carries `author_name` and `author_url`, and that URL is `/author/login-name/`. Public posts, pages and other REST content types with an author carry an `author` field (a user ID) and a link to `/wp/v2/users/ID`. For anyone who cannot edit posts, the oEmbed author name and URL are dropped (the site name stays) and the REST `author` field and `author` link are removed, so `?_embed` has nothing to embed either. Editors keep them, because the block editor and the WooCommerce admin read the field. Visitors and customers do not see them. Empty uses "Incorrect username or password.".

Not done on purpose: hiding wp-login.php behind a secret path. It breaks resets and integrations and only buys obscurity. Use rate limiting, fail2ban and a second factor.

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
