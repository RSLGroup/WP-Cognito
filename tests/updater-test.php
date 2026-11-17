<?php
/** Isolated contract tests for update discovery; no WordPress installation required. */
define('ABSPATH', __DIR__);
define('WCSSO_PLUGIN_FILE', dirname(__DIR__) . '/wp-content/plugins/wp-cognito-sso/wp-cognito-sso.php');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
$cache = [];
$responses = [];
$requests = [];
$hooks = [];
function plugin_basename($file) { return 'wp-cognito-sso/' . basename($file); }
function add_filter($hook, $callback, $priority, $args) { $GLOBALS['hooks'][$hook] = [$callback, $args]; }
function add_action($hook, $callback, $priority, $args) { add_filter($hook, $callback, $priority, $args); }
function get_site_transient($key) { return $GLOBALS['cache'][$key][0] ?? false; }
function set_site_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = [$value, $ttl]; }
function delete_site_transient($key) { unset($GLOBALS['cache'][$key]); }
function wp_remote_get($url, $args) {
    $GLOBALS['requests'][] = $url;
    if (!array_key_exists($url, $GLOBALS['responses'])) {
        throw new RuntimeException('Unexpected HTTP request: ' . $url);
    }
    return $GLOBALS['responses'][$url];
}
function is_wp_error($response) { return $response instanceof RuntimeException; }
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
require dirname(__DIR__) . '/wp-content/plugins/wp-cognito-sso/includes/class-updater.php';

function expect($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function fixture($changes = [], $headers = null) {
    $GLOBALS['cache'] = [];
    $GLOBALS['requests'] = [];
    $release = array_replace([
        'tag_name' => 'v1.0.9', 'draft' => false, 'prerelease' => false,
        'body' => 'Fix <script>alert(1)</script>',
        'assets' => [[
            'name' => 'wp-cognito-sso.zip', 'state' => 'uploaded', 'size' => 2000,
            'browser_download_url' => WCSSO_Updater::REPOSITORY . '/releases/download/v1.0.9/wp-cognito-sso.zip',
        ]],
    ], $changes);
    $GLOBALS['responses'] = [
        WCSSO_Updater::API => ['status' => 200, 'body' => json_encode($release)],
        'https://raw.githubusercontent.com/RSLGroup/WP-Cognito/v1.0.9/wp-content/plugins/wp-cognito-sso/wp-cognito-sso.php'
            => ['status' => 200, 'body' => $headers ?? "<?php\n/**\n * Version: 1.0.9\n * Requires at least: 6.5\n * Requires PHP: 8.2\n * Tested up to: 6.9\n */\n"],
    ];
}
function check() {
    return WCSSO_Updater::check_update(false, ['Version' => '1.0.8'], plugin_basename(WCSSO_PLUGIN_FILE));
}

WCSSO_Updater::init();
expect(count($hooks) === 4, 'Updater hooks must be registered.');
fixture();
$unchanged = ['other' => true];
expect(WCSSO_Updater::check_update($unchanged, [], 'other/plugin.php') === $unchanged, 'Other GitHub plugins must be untouched.');
expect(count($requests) === 0, 'Other plugins must not cause HTTP requests.');
$update = check();
expect($update['version'] === '1.0.9', 'Release version must be normalized.');
expect($update['requires_php'] === '8.2' && $update['requires'] === '6.5', 'Compatibility must come from the new release.');
expect($update['package'] === WCSSO_Updater::REPOSITORY . '/releases/download/v1.0.9/wp-cognito-sso.zip', 'Must use installable release asset.');
expect(count($requests) === 2, 'Discovery must fetch the release and tagged headers.');
check();
expect(count($requests) === 2, 'Valid releases must be cached.');
expect($cache[WCSSO_Updater::CACHE_KEY][1] === HOUR_IN_SECONDS, 'Success cache must last one hour.');
$info = WCSSO_Updater::plugin_information(false, 'plugin_information', (object) ['slug' => 'wp-cognito-sso']);
expect($info->download_link === $update['package'], 'Details dialog must use release ZIP.');
expect(strpos($info->sections['changelog'], '<script>') === false, 'Release notes must be escaped.');
expect(WCSSO_Updater::plugin_information($unchanged, 'query_plugins', (object) []) === $unchanged, 'Other plugin API requests must be untouched.');

foreach ([['draft' => true], ['prerelease' => true], ['tag_name' => 'v2.0.0-beta'],
    ['tag_name' => 'main'], ['tag_name' => ['bad']], ['assets' => []], ['assets' => 'invalid'],
    ['assets' => [['name' => 'wp-cognito-sso.zip', 'state' => 'uploaded', 'size' => 1,
        'browser_download_url' => 'https://example.com/foreign.zip']]]] as $changes) {
    fixture($changes);
    expect(check() === false, 'Unsafe or incomplete releases must be ignored: ' . json_encode($changes));
    $count = count($requests);
    check();
    expect(count($requests) === $count, 'Failed release discovery must be cached.');
    expect($cache[WCSSO_Updater::CACHE_KEY][1] === 5 * MINUTE_IN_SECONDS, 'Failure cache must last five minutes.');
}
foreach ([new RuntimeException('Offline'), ['status' => 403, 'body' => 'rate limited'],
    ['status' => 404, 'body' => 'no release'], ['status' => 200, 'body' => 'invalid JSON'],
    ['status' => 200, 'body' => 'null']] as $response) {
    fixture();
    $responses[WCSSO_Updater::API] = $response;
    expect(check() === false, 'HTTP errors and malformed responses must not offer updates.');
}
fixture([], " * Version: 2.0.0\n * Requires at least: 6.0\n * Requires PHP: 8.0\n * Tested up to: 6.9\n");
expect(check() === false, 'Tag/header mismatch must not offer an update.');
fixture([], " * Version: 1.0.9\n");
expect(check() === false, 'Missing compatibility requirements must not offer updates.');
fixture([], " * Version: 1.0.9\r\n * Requires at least: 6.0\r\n * Requires PHP: 8.0\r\n * Tested up to: 6.9\r\n");
expect(check()['version'] === '1.0.9', 'Windows line endings must be accepted.');
fixture();
$header_url = array_keys($responses)[1];
$responses[$header_url] = ['status' => 404, 'body' => 'not found'];
expect(check() === false, 'Unavailable tagged source must not offer updates.');

$own = (object) ['plugin' => plugin_basename(WCSSO_PLUGIN_FILE)];
expect(WCSSO_Updater::auto_update(false, $own) === true, 'This plugin must auto-update by default.');
expect(WCSSO_Updater::auto_update(null, (object) ['plugin' => 'other/plugin.php']) === null, 'Other plugins must retain their auto-update policy.');
expect(WCSSO_Updater::auto_update(true, (object) ['plugin' => $own->plugin, 'disable_autoupdate' => true]) === false, 'Update veto flag must be respected.');
define('WCSSO_AUTO_UPDATE', false);
expect(WCSSO_Updater::auto_update(true, $own) === false, 'Site-specific opt-out must be respected.');
fixture();
check();
WCSSO_Updater::clear_cache(null, ['type' => 'plugin', 'action' => 'update', 'plugins' => ['other/plugin.php']]);
expect(get_site_transient(WCSSO_Updater::CACHE_KEY) !== false, 'Unrelated upgrades must retain cache.');
WCSSO_Updater::clear_cache(null, ['type' => 'plugin', 'action' => 'update', 'plugins' => [$own->plugin]]);
expect(get_site_transient(WCSSO_Updater::CACHE_KEY) === false, 'Own upgrades must clear cache.');
echo "Updater contract tests passed.\n";
