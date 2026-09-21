# WP Cognito SSO

A WordPress plugin for authenticating through an AWS Cognito-compatible OAuth portal. It supports Hosted UI login, WordPress user provisioning, optional WordPress-to-Cognito sync, legacy-account verification endpoints, and a self-service Cognito password form.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- Composer when installing from source
- The AWS SDK for PHP when using WordPress-to-Cognito sync or the password-reset shortcode

## Installation

1. Copy the plugin to `wp-content/plugins/wp-cognito-sso`.
2. Install production dependencies from the plugin directory:

   ```bash
   cd wp-content/plugins/wp-cognito-sso
   composer install --no-dev --optimize-autoloader
   ```

3. Activate **WP Cognito SSO** in WordPress.
4. Open **Settings → Cognito SSO**.

Hosted login and migration verification do not use the AWS SDK directly. The SDK is required for features that call Cognito's administrative API: user sync and the password-reset shortcode.

## Hosted login

Configure the Cognito domain, OAuth client ID and secret, scopes, and redirect path. Add the callback URL displayed by the settings page to the OAuth client's allowed callback URLs. The default callback path is `/cognito-login`.

- **Enable Hosted UI login** redirects WordPress login-form requests to the configured OAuth portal.
- **Automatically redirect unauthenticated visits** protects front-end requests as well.
- **Excluded paths** remain accessible without an SSO redirect. Enter one path per line, including the leading slash.
- **New user redirect URL** applies only to a user's first login when WordPress provisioning creates their local account.
- Logout can redirect through the configured Cognito-compatible logout endpoint and then return to WordPress.

WordPress admin, cron, AJAX and REST requests are not front-end auto-redirect targets. Page caches and reverse proxies must exclude login, callback and logout paths; cached HTML can be served before WordPress and this plugin execute. At minimum, exclude `/login/`, `/wp-login.php`, `/cognito-login` and `/logout` where those paths are in use.

## WordPress user provisioning

After a successful OAuth callback, the plugin can create or update the corresponding WordPress user from ID-token claims. Email, username, display-name and role claim keys are configurable. Role mapping can translate Cognito role values into WordPress roles, with an option to avoid replacing a non-default existing role.

JWT signature and claim validation is enabled by default and should remain enabled.

## WordPress-to-Cognito sync

This is separate from login-time WordPress provisioning and legacy migration. When **Sync WordPress users to Cognito** is enabled, registration and/or profile updates can create or update users through the Cognito administrative API.

Configure:

- AWS region, access key and secret key
- Cognito user pool ID
- registration/profile-update triggers
- optional role synchronization and its Cognito attribute name

The IAM identity needs only the Cognito actions used by the enabled operations. Keep sync disabled while testing migration if WordPress users must not be created in Cognito automatically.

## Legacy migration verification endpoints

The plugin exposes server-to-server endpoints that allow a trusted migration service to verify a WordPress account without exposing WordPress password hashes:

- `POST /wp-json/cognito-migrate/v1/verify` authenticates a username or email plus password.
- `POST /wp-json/cognito-migrate/v1/lookup` looks up a username or email for password-recovery migration flows.

Set **Cognito user migration → Shared secret** before using them. Requests must include:

- `X-Cognito-Migrate-Timestamp`: current Unix timestamp, within five minutes of the server
- `X-Cognito-Migrate-Signature`: lowercase HMAC-SHA256 of `<timestamp>.<raw request body>`

The endpoint returns only identity fields required for migration. Keep the shared secret out of source control and use HTTPS.

## Password-reset shortcode

Add the following shortcode to a WordPress page that is available to logged-in users:

```text
[wcsso_cognito_password_reset]
```

It renders new-password and confirmation fields and submits over WordPress AJAX with a nonce. Logged-out visitors see a sign-in requirement instead of the form. The default minimum password length is eight characters; Cognito's pool policy is still authoritative and may require a stronger password.

The shortcode uses the AWS region, access key, secret key and user pool ID from **Settings → Cognito SSO**. The AWS SDK must be installed even if automatic WordPress-to-Cognito sync is disabled.

On submission, the plugin:

1. Tries to set a permanent password for a Cognito account matching the current WordPress username.
2. If not found, tries the current WordPress email.
3. If neither exists, creates a suppressed-message Cognito account from the WordPress username, email, name and optional mapped role, then sets the submitted password permanently.

The current WordPress user must have both a username and email to create a new Cognito account. The shortcode marks that WordPress email as verified in Cognito and does not send Cognito's welcome message. Use it only where WordPress account access is sufficient proof that the user controls the corresponding identity.

The shortcode does not ask for the current password and is not a logged-out “forgot password” flow. Access is protected by the active WordPress session and a WordPress nonce. Your IAM policy must allow the required `AdminSetUserPassword` and, when account creation is expected, `AdminCreateUser` operations for the configured pool.

The minimum length can be customized in WordPress code:

```php
add_filter('wcsso_cognito_password_min_length', fn () => 12);
```

## Diagnostics

Enable plugin debug logging only while diagnosing a problem. Passwords and migration secrets should never be logged. If WordPress reports that the AWS SDK is missing, run Composer from the plugin directory or disable features that use Cognito's administrative API.

## Notes

- Tested up to WordPress 6.9.
- The AWS SDK for PHP is distributed under the Apache 2.0 licence.
- Changing the callback path requires flushing WordPress rewrite rules; saving the plugin settings schedules this automatically.
