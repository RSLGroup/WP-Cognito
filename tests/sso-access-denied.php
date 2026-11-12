<?php
// Run with: php tests/sso-access-denied.php
// Isolated callback checks: no WordPress install or identity-provider requests.
define('ABSPATH', __DIR__);
require __DIR__ . '/../wp-content/plugins/wp-cognito-sso/includes/helpers.php';
require __DIR__ . '/../wp-content/plugins/wp-cognito-sso/includes/class-sso.php';

class TestResponse extends RuntimeException {
    public $payload;
    public function __construct($payload) { $this->payload = $payload; }
}
class WCSSO_JWT {
    public static function decode($token, $options) { return ['email' => 'missing@example.test']; }
}
class WCSSO_User_Provisioner {
    public static function create_or_update($claims) { return 42; }
}
function get_option($key, $default) { return $GLOBALS['settings']; }
function get_query_var($key) { return 1; }
function sanitize_text_field($value) { return $value; }
function wp_unslash($value) { return $value; }
function home_url($path = '') { return 'https://site.example' . $path; }
function wp_remote_post($url, $args) { return []; }
function wp_remote_retrieve_body($response) { return '{"id_token":"test"}'; }
function is_wp_error($value) { return false; }
function do_action(...$args) {}
function apply_filters($name, $value) { return $value; }
function get_user_by($field, $email) { return $GLOBALS['existing_user']; }
function add_query_arg($key, $value, $url) { return $url . '?' . $key . '=' . $value; }
function wp_nonce_url($url, $action) { return $url . '&_wpnonce=valid'; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid' && $action === 'wcsso_denied_login_logout'; }
function esc_html__($value, $domain) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES); }
function wp_die($message, $title = '', $args = []) { throw new TestResponse(['message' => $message, 'args' => $args]); }
function wp_set_current_user($id) { $GLOBALS['authenticated'] = $id; throw new TestResponse(['authenticated' => $id]); }
function wp_set_auth_cookie($id) { throw new RuntimeException('Unexpected authentication cookie'); }
function nocache_headers() { $GLOBALS['nocache'] = true; }
function remove_action($hook, $callback) { $GLOBALS['removed_logout_redirect'] = $hook === 'wp_logout'; }
function wp_logout() { $GLOBALS['logged_out'] = true; }
function wp_redirect($url) { throw new TestResponse(['redirect' => $url]); }
function wp_safe_redirect($url) { wp_redirect($url); }
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function run_callback($query) {
    $_GET = $query;
    try { WCSSO_SSO::handle_callback(); } catch (TestResponse $response) { return $response->payload; }
    throw new RuntimeException('Callback returned unexpectedly');
}

$GLOBALS['settings'] = array_merge(wcsso_get_default_settings(), [
    'login_enabled' => 1, 'provisioning_enabled' => 0,
    'cognito_domain' => 'auth.example', 'client_id' => 'client',
]);
$GLOBALS['existing_user'] = false;
$result = run_callback(['code' => 'test']);
check($result['args']['response'] === 403, 'Access must be denied with HTTP 403');
check(strpos($result['message'], 'You do not have access to log in to this platform. Please contact your administrator if you think this is in error.') === 0, 'Exact requested message missing');
check(strpos($result['message'], 'wcsso_logout=1&amp;_wpnonce=valid') !== false && strpos($result['message'], '>Log out</a>') !== false, 'Nonce-protected logout link missing');
check(empty($GLOBALS['authenticated']), 'Denied user was authenticated');

$GLOBALS['existing_user'] = (object) ['ID' => 7];
check(run_callback(['code' => 'test'])['authenticated'] === 7, 'Existing user login changed');
$GLOBALS['settings']['provisioning_enabled'] = 1;
check(run_callback(['code' => 'test'])['authenticated'] === 42, 'Provisioning login changed');

$result = run_callback(['wcsso_logout' => '1', '_wpnonce' => 'invalid']);
check($result['args']['response'] === 403 && empty($GLOBALS['logged_out']), 'Invalid nonce allowed logout');
// Routine Cognito logout is disabled by default; recovery must still log out there.
$result = run_callback(['wcsso_logout' => '1', '_wpnonce' => 'valid']);
check(!empty($GLOBALS['logged_out']) && !empty($GLOBALS['nocache']) && !empty($GLOBALS['removed_logout_redirect']), 'WordPress logout did not run');
check($result['redirect'] === 'https://auth.example/logout?client_id=client&logout_uri=https%3A%2F%2Fsite.example%2Flogout', 'Wrong Cognito logout URL');
unset($GLOBALS['settings']['client_id']);
check(run_callback(['wcsso_logout' => '1', '_wpnonce' => 'valid'])['redirect'] === 'https://site.example/logout', 'Local fallback failed');
echo "Passed: denied message/link, existing/provisioned logins, nonce checks, WordPress/Cognito logout, local fallback.\n";
