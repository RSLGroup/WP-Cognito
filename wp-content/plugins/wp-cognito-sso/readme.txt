=== WP Cognito SSO ===
Contributors: rslgroup
Tags: cognito, sso, aws, oauth2, login
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cognito Hosted UI SSO for WordPress with optional user provisioning and Cognito user sync.

== Description ==

WP Cognito SSO provides:

* Cognito Hosted UI login redirect and callback handling.
* Automatic WordPress user provisioning from id_token claims.
* Configurable role mapping from Cognito claims to WordPress roles.
* Optional WordPress -> Cognito user sync via AWS SDK.
* A logged-in user Cognito password reset shortcode.

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

Add `[wcsso_cognito_password_reset]` to a WordPress page. The shortcode renders a logged-in user form with new password and confirm password fields, then updates the user's Cognito password using the AWS region, access key, secret key, and user pool ID from the plugin settings. If the Cognito user does not exist, it creates one from the current WordPress username and email, then sets the submitted password.

= Does this plugin send users to Cognito automatically? =

Yes, if you enable "Auto redirect" all visitors are redrecited. REST/AJAX, and excluded paths are not redirected. With only "Enable Hosted UI login" enabled it will redirect only the logins for the wp-admin dahsboard.

== Screenshots ==

1. Cognito SSO settings page.

== Changelog ==

= 1.0.0 =
* Initial release.
