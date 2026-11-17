<?php
/** Verify missing-SDK detection without needing a live WordPress installation. */
define('ABSPATH', __DIR__);
$can_manage = true;
function current_user_can($capability) { return $GLOBALS['can_manage']; }
function esc_html__($value, $domain) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
// This must never gate detection: missing dependencies matter even when sync is off.
function wcsso_get_settings() { throw new RuntimeException('SDK detection must not depend on sync settings.'); }
if (($argv[1] ?? '') === 'installed') {
    require dirname(__DIR__) . '/wp-content/plugins/wp-cognito-sso/vendor/autoload.php';
    if (!class_exists('Aws\\CognitoIdentityProvider\\CognitoIdentityProviderClient')) {
        throw new RuntimeException('The bundled Cognito client must load.');
    }
}
require dirname(__DIR__) . '/wp-content/plugins/wp-cognito-sso/includes/class-settings.php';
ob_start();
WCSSO_Settings::maybe_show_sdk_notice();
$output = ob_get_clean();
if (($argv[1] ?? '') === 'installed') {
    if ($output !== '') { throw new RuntimeException('An installed SDK must not trigger a missing dependency warning.'); }
} elseif (strpos($output, 'AWS SDK is not installed') === false || strpos($output, 'complete plugin ZIP') === false) {
    throw new RuntimeException('A missing SDK must produce an actionable administrator warning.');
}
$can_manage = false;
ob_start();
WCSSO_Settings::maybe_show_sdk_notice();
if (ob_get_clean() !== '') { throw new RuntimeException('Non-administrators must not receive dependency notices.'); }
echo "SDK notice test passed (" . ($argv[1] ?? 'missing') . ").\n";
