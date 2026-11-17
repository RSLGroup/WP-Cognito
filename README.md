# WP Cognito SSO

A WordPress plugin for authenticating through an AWS Cognito-compatible OAuth portal. It supports Hosted UI login, WordPress user provisioning, optional WordPress-to-Cognito sync, legacy-account verification endpoints, and a self-service Cognito password form.

## Requirements

- WordPress 6.0+
- PHP 8.1+
- Composer when installing from source
- AWS SDK for PHP `^3.356` (bundled in release ZIPs; used by WordPress-to-Cognito sync and the password-reset shortcode)

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

Release ZIPs include the SDK and its production dependencies; no separate installation is needed on the site. Source installations require the Composer step above. Administrators see a warning if the Cognito SDK client cannot be loaded, even when sync is disabled. The release builder refuses to package a missing or outdated SDK.

## Automatic plugin updates

Starting with 1.0.8, this plugin checks the latest published stable release in [RSLGroup/WP-Cognito](https://github.com/RSLGroup/WP-Cognito/releases) during WordPress's normal plugin update checks. WordPress displays available updates under **Dashboard → Updates** and **Plugins**. When an administrator enables auto-updates, WordPress installs this plugin's updates using its background updater. The plugin details dialog shows release notes. WordPress's standard automatic-update result emails remain in use, subject to the site's email settings and delivery.

**Install 1.0.8 once on each existing site** to enable this mechanism. Older versions cannot discover this update themselves. Use the release's `wp-cognito-sso.zip` asset, which contains the correct plugin folder and production AWS SDK dependencies. GitHub's generated source archives are not installable plugin packages for this repository.

Updates follow WordPress's schedule, rather than happening immediately after a release. WP-Cron, outbound access to GitHub and raw.githubusercontent.com, and permission to install background updates must be available. Global WordPress or hosting restrictions still apply. Successful release lookups are cached for one hour; failed or incomplete releases are retried after five minutes. Drafts, prereleases, invalid versions, missing ZIPs and mismatched version headers are ignored. Compatibility requirements come from the released plugin's headers.

To request an immediate check, open **Dashboard → Updates → Check again**. This clears both this plugin's GitHub release cache and WordPress's plugin update cache before the normal update checks run. Only administrators with plugin-update permission can trigger this refresh. Merely opening the Updates page keeps the release cache in use. Older installed versions without this refresh behavior may continue using the release cache for up to one hour.

From 1.0.16, GitHub update offers are registered directly in WordPress's plugin update data, so a failed WordPress.org request no longer skips this plugin's check. If GitHub cannot be reached, is rate-limited, or returns an invalid package or version, administrators see the specific failure on the Plugins and Updates screens. This message does not imply that the plugin is current. The GitHub release API and tagged source must still be reachable from the site's PHP environment.

If an older installation cannot discover releases, install the fixed plugin ZIP once through **Plugins → Add New Plugin → Upload Plugin**, and choose to replace the existing plugin. Then use **Dashboard → Updates → Check again**. A site-specific connection problem will be shown as a warning; the site host may need to allow outbound requests to GitHub. Automatic installation remains opt-in.

On new installations, auto-updates start disabled. To opt in, open **Plugins → Installed Plugins** and click **Enable auto-updates** beside WP Cognito SSO. Once enabled, click **Disable auto-updates** to turn them off again. Existing saved WordPress choices are preserved across page loads, plugin reactivation and updates. On multisite, use **Network Admin → Plugins**. Available update notices remain visible when automatic installation is disabled.

For a configuration-level override that prevents automatic installation, add this to `wp-config.php` (this intentionally locks the WordPress toggle to disabled):

```php
define('WCSSO_AUTO_UPDATE', false);
```

### Publishing a plugin update

1. Merge your changes into `main`. Every push to `main`, including a merged pull request, starts the release workflow.
2. The workflow chooses the greater of the source plugin version and existing stable `vmajor.minor.patch` tags, then increases its patch version. For example, `1.0.10` becomes `1.0.11`. No manual version bump or tag is needed.
3. It creates a release commit containing matching plugin and readme versions and a changelog entry, then tags that commit. This commit is based on the exact triggering `main` commit; it does not push a version commit back to `main`, so protected branches are supported and no release loop is created.
4. The workflow checks PHP, runs the plugin tests, installs locked production dependencies, builds `wp-cognito-sso.zip`, and pushes the release tag. It publishes the GitHub release only after the ZIP is attached. WordPress discovers it during its normal update checks.

Release runs are queued so simultaneous merges cannot allocate the same version. Retrying the same source commit reuses its tag and resumes an existing draft; an already published release is left untouched. Direct pushes to `main` also release; pushes to other branches do not. The workflow needs the repository's built-in GitHub token to have contents-write permission and permission to create `v*` tags. No personal token is required. Keep this repository public so sites can retrieve releases without GitHub credentials.

For a local release build:

```bash
composer install --working-dir=wp-content/plugins/wp-cognito-sso --no-dev --prefer-dist --optimize-autoloader
php tests/updater-test.php
php tests/release-test.php
php scripts/build-release.php
```

The installable ZIP is written to `dist/wp-cognito-sso.zip`.

### Testing automatic update delivery

1. Keep the test site's installed plugin version below the next release version. The site must run PHP 8.1 or newer.
2. Enable auto-updates for WP Cognito SSO under **Plugins → Installed Plugins** if testing automatic installation. Leave them disabled to test update notifications only.
3. Merge a change into `main` and wait for the release workflow to publish its installable ZIP.
4. Use **Dashboard → Updates → Check again** to refresh release discovery. The newer release should appear without uploading a ZIP manually.
5. For automatic installation, leave WP-Cron enabled and allow WordPress's background update schedule to run. Afterwards, confirm the installed plugin version matches the published release and no missing-SDK warning is displayed.

If discovery fails, record the installed version, published release version and any WP Cognito SSO update-check warning shown on the Plugins or Updates screen.

## Hosted login

Configure the Cognito domain, OAuth client ID and secret, scopes, and redirect path. Add the callback URL displayed by the settings page to the OAuth client's allowed callback URLs. The default callback path is `/cognito-login`.

- **Enable Hosted UI login** redirects WordPress login-form requests to the configured OAuth portal.
- **Automatically redirect unauthenticated visits** protects front-end requests as well.
- **Excluded paths** remain accessible without an SSO redirect. Enter one path per line, including the leading slash.
- **New user redirect URL** applies only to a user's first login when WordPress provisioning creates their local account.
- **Cognito role blacklist** optionally blocks SSO logins whose `custom:user_role` claim matches any comma-separated entry (for example, `student, parent`). Leave it blank to disable. Matching is exact and case-sensitive, with surrounding spaces ignored; the claim may contain one role or comma-separated roles. The check runs before account creation, profile updates, role mapping, or authentication, including for existing WordPress users. Blocked users see the access-denied message and **Log out** link. Missing or unlisted roles continue through the normal login flow. This uses `custom:user_role` regardless of the role-mapping claim setting; existing sessions and native WordPress password logins are unaffected.
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
