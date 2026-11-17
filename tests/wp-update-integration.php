<?php
/** Run WordPress's actual update function with controlled HTTP/storage adapters.
 * Usage: php tests/wp-update-integration.php /path/to/wordpress/wp-includes/update.php
 */
if (empty($argv[1]) || !is_file($argv[1])) {
    fwrite(STDERR, "Pass the path to WordPress's wp-includes/update.php.\n");
    exit(1);
}
define('ABSPATH', __DIR__ . '/');
define('WCSSO_PLUGIN_FILE', dirname(__DIR__) . '/wp-content/plugins/wp-cognito-sso/wp-cognito-sso.php');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
$hooks = [];
$cache = [];
$installed = '1.0.11';
$wordpress_status = 200;
$github_status = 200;
$plugin_file = 'wp-cognito-sso/wp-cognito-sso.php';
function add_filter($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$hook] = [$callback, $args]; }
function add_action($hook, $callback, $priority = 10, $args = 1) { add_filter($hook, $callback, $priority, $args); }
function apply_filters($hook, $value, ...$args) {
    if (!isset($GLOBALS['hooks'][$hook])) { return $value; }
    [$callback, $count] = $GLOBALS['hooks'][$hook];
    return call_user_func_array($callback, array_slice(array_merge([$value], $args), 0, $count));
}
function plugin_basename($file) { return $GLOBALS['plugin_file']; }
function get_file_data($file, $headers, $context = '') { return ['Version' => $GLOBALS['installed']]; }
function get_site_transient($key) { return isset($GLOBALS['cache'][$key]) ? unserialize(serialize($GLOBALS['cache'][$key])) : false; }
function set_site_transient($key, $value, $ttl = 0) { $GLOBALS['cache'][$key] = apply_filters('pre_set_site_transient_' . $key, $value); }
function delete_site_transient($key) { unset($GLOBALS['cache'][$key]); }
function wp_installing() { return false; }
function wp_doing_cron() { return false; }
function is_main_site() { return false; }
function is_network_admin() { return false; }
function current_filter() { return 'load-update-core.php'; }
function get_plugins() {
    return [$GLOBALS['plugin_file'] => ['Version' => $GLOBALS['installed'], 'UpdateURI' => WCSSO_Updater::REPOSITORY],
        'other/plugin.php' => ['Version' => '1.0', 'UpdateURI' => '']];
}
function get_option($key, $default = false) { return $default; }
function get_available_languages() { return []; }
function wp_get_installed_translations($type) { return []; }
function wp_json_encode($value) { return json_encode($value); }
function wp_get_wp_version() { return '6.9'; }
function home_url($path) { return 'https://example.test' . $path; }
function wp_http_supports($args) { return true; }
function set_url_scheme($url, $scheme) { return preg_replace('/^https?:/', $scheme . ':', $url); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function sanitize_url($url) { return $url; }
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_post($url, $args) {
    return ['status' => $GLOBALS['wordpress_status'], 'body' => json_encode([
        'plugins' => ['other/plugin.php' => ['new_version' => '4.0']], 'translations' => [], 'no_update' => [],
    ])];
}
function wp_remote_get($url, $args) {
    if ($url === WCSSO_Updater::API) {
        return ['status' => $GLOBALS['github_status'], 'body' => json_encode([
            'tag_name' => 'v1.0.15', 'draft' => false, 'prerelease' => false,
            'assets' => [['name' => 'wp-cognito-sso.zip', 'state' => 'uploaded', 'size' => 100,
                'browser_download_url' => WCSSO_Updater::REPOSITORY . '/releases/download/v1.0.15/wp-cognito-sso.zip']],
        ])];
    }
    if ($url !== 'https://raw.githubusercontent.com/RSLGroup/WP-Cognito/v1.0.15/wp-content/plugins/wp-cognito-sso/wp-cognito-sso.php') {
        throw new RuntimeException('Unexpected update request: ' . $url);
    }
    return ['status' => 200, 'body' => " * Version: 1.0.15\n * Requires at least: 6.0\n * Requires PHP: 8.0\n * Tested up to: 6.9\n"];
}
function integration_expect($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
require $argv[2] ?? dirname(__DIR__) . '/wp-content/plugins/wp-cognito-sso/includes/class-updater.php';
WCSSO_Updater::init();
require $argv[1];

foreach ([200, 503] as $wordpress_status) {
    $cache = [];
    $installed = '1.0.11';
    wp_update_plugins();
    $result = get_site_transient('update_plugins');
    integration_expect(($result->response[$plugin_file]->new_version ?? '') === '1.0.15', 'The update must be offered even when WordPress.org returns HTTP ' . $wordpress_status);
    integration_expect($result->response[$plugin_file]->package === WCSSO_Updater::REPOSITORY . '/releases/download/v1.0.15/wp-cognito-sso.zip', 'The native update offer must use the installable ZIP.');
    integration_expect(apply_filters('auto_update_plugin', null, $result->response[$plugin_file]) === null, 'Update discovery must not force automatic installation.');
    if ($wordpress_status === 200) {
        integration_expect(($result->response['other/plugin.php']->new_version ?? '') === '4.0', 'Other plugins must retain their native offers.');
    }
}
$cache = [];
$installed = '1.0.15';
wp_update_plugins();
$result = get_site_transient('update_plugins');
integration_expect(!isset($result->response[$plugin_file]) && isset($result->no_update[$plugin_file]), 'The installed release must be classified as current.');
$cache = [];
$installed = '1.0.11';
$github_status = 403;
wp_update_plugins();
$result = get_site_transient('update_plugins');
integration_expect(!isset($result->response[$plugin_file]), 'A blocked GitHub request must not invent an update offer.');
integration_expect(strpos(get_site_transient(WCSSO_Updater::ERROR_KEY), 'HTTP 403') !== false, 'A GitHub failure must report its cause.');
echo "WordPress core update integration tests passed (WordPress.org available/unavailable, current release, blocked GitHub).\n";
