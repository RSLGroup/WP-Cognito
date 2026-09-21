=== WP Cognito SSO ===
Contributors: rslgroup
Tags: cognito, sso, aws, oauth2, login
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cognito Hosted UI SSO for WordPress with user provisioning, optional Cognito sync, migration verification and password management.

== Description ==

WP Cognito SSO provides:

* Cognito Hosted UI login redirect and callback handling.
* Automatic WordPress user provisioning from id_token claims.
* Configurable role mapping from Cognito claims to WordPress roles.
* Optional WordPress -> Cognito user sync via AWS SDK.
* A logged-in user Cognito password reset shortcode.
* Signed REST endpoints for verifying legacy WordPress accounts during migration.

== Installation ==

1. Upload the plugin to `wp-content/plugins/wp-cognito-sso`.
2. If you installed from source, install dependencies:

```
cd wp-content/plugins/wp-cognito-sso
composer install --no-dev --optimize-autoloader
```

3. Activate the plugin through the WordPress Plugins screen.
4. Configure settings under Settings -> Cognito SSO.

== Frequently Asked Questions ==

= Where is the callback endpoint? =

The callback endpoint is the configured redirect path (default: `/cognito-login`). The settings page shows the full callback URL.

= Do I need the AWS SDK? =

Only if you enable WordPress -> Cognito user sync or use the Cognito password reset shortcode. Hosted UI login works without the SDK.

= How do I add a Cognito password reset form? =

Add `[wcsso_cognito_password_reset]` to a WordPress page that is available to logged-in users. The form uses WordPress AJAX and a nonce. Logged-out visitors see a sign-in requirement.

The shortcode requires the AWS SDK plus the AWS region, access key, secret key and user pool ID under Settings -> Cognito SSO. Automatic WordPress -> Cognito sync may remain disabled.

It first tries to set a permanent password for a Cognito user matching the current WordPress username, then the WordPress email. If neither exists, it creates a suppressed-message Cognito account from the WordPress username, email, name and optional mapped role, marks the email verified, and sets the submitted password permanently. The WordPress user must have a username and email for account creation.

The default minimum length is eight characters, but the Cognito pool password policy remains authoritative. Developers can change the local minimum with the `wcsso_cognito_password_min_length` filter. The IAM identity needs `AdminSetUserPassword` and, when creation is required, `AdminCreateUser` for the configured user pool.

This is a logged-in account form, not a logged-out forgot-password flow. It relies on the current WordPress session and does not ask for the current password.

= How do the migration endpoints work? =

Set the Cognito user migration shared secret in the plugin settings. The plugin exposes `POST /wp-json/cognito-migrate/v1/verify` for password verification and `POST /wp-json/cognito-migrate/v1/lookup` for account lookup. Requests must use HTTPS and include a current `X-Cognito-Migrate-Timestamp` plus `X-Cognito-Migrate-Signature`, calculated as the lowercase HMAC-SHA256 of `<timestamp>.<raw request body>`.

= Does this plugin send users to Cognito automatically? =

Yes. If you enable "Auto redirect", unauthenticated front-end visits are redirected. WordPress admin, cron, REST/AJAX and configured excluded paths are not front-end auto-redirect targets. With only "Enable Hosted UI login" enabled, WordPress login-form requests are redirected.

Exclude login, callback and logout paths from full-page or reverse-proxy caches. Cached HTML can be served before WordPress and this plugin run. Common exclusions are `/login/`, `/wp-login.php`, `/cognito-login` and `/logout`.

== Screenshots ==

1. Cognito SSO settings page.

== Changelog ==

= 1.0.0 =
* Initial release.
