<?php

defined('ABSPATH') || exit;

/** Connect published GitHub release packages to WordPress's native updater. */
class WCSSO_Updater {
    const REPOSITORY = 'https://github.com/RSLGroup/WP-Cognito';
    const API = 'https://api.github.com/repos/RSLGroup/WP-Cognito/releases/latest';
    const CACHE_KEY = 'wcsso_github_release';
    const ERROR_KEY = 'wcsso_update_error';
    const SLUG = 'wp-cognito-sso';

    public static function init() {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'inject_update'], 10, 1);
        add_filter('auto_update_plugin', [__CLASS__, 'auto_update'], 10, 2);
        add_filter('all_plugins', [__CLASS__, 'mark_update_supported'], 10, 1);
        add_filter('plugins_api', [__CLASS__, 'plugin_information'], 10, 3);
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache'], 10, 2);
        // Run before WordPress's own update checks on this screen (priority 10).
        add_action('load-update-core.php', [__CLASS__, 'maybe_force_update_check'], 9, 0);
        add_action('admin_notices', [__CLASS__, 'show_update_error'], 10, 0);
        add_action('network_admin_notices', [__CLASS__, 'show_update_error'], 10, 0);
    }

    public static function inject_update($transient) {
        if (!is_object($transient) || !isset($transient->last_checked)) {
            return $transient;
        }
        $plugin_file = plugin_basename(WCSSO_PLUGIN_FILE);
        $plugin_data = get_file_data(WCSSO_PLUGIN_FILE, ['Version' => 'Version'], 'plugin');
        $update = self::check_update(false, $plugin_data, $plugin_file);
        if (!$update || empty($plugin_data['Version'])) {
            return $transient;
        }
        $update['plugin'] = $plugin_file;
        $update['new_version'] = $update['version'];
        $transient->response = (array) ($transient->response ?? []);
        $transient->no_update = (array) ($transient->no_update ?? []);
        unset($transient->response[$plugin_file], $transient->no_update[$plugin_file]);
        if (version_compare($update['new_version'], $plugin_data['Version'], '>')) {
            $transient->response[$plugin_file] = (object) $update;
        } else {
            $transient->no_update[$plugin_file] = (object) $update;
        }
        return $transient;
    }

    public static function show_update_error() {
        $screen = get_current_screen();
        if (!current_user_can('update_plugins') || !$screen
            || !in_array($screen->id, ['plugins', 'plugins-network', 'update-core', 'update-core-network'], true)) {
            return;
        }
        $error = get_site_transient(self::ERROR_KEY);
        if (is_string($error) && $error !== '') {
            echo '<div class="notice notice-warning"><p>'
                . esc_html('WP Cognito SSO could not check for updates: ' . $error)
                . '</p></div>';
        }
    }

    public static function maybe_force_update_check() {
        if (empty($_GET['force-check']) || !current_user_can('update_plugins')) {
            return;
        }

        delete_site_transient(self::CACHE_KEY);
        delete_site_transient(self::ERROR_KEY);
        delete_site_transient('update_plugins');
    }

    public static function mark_update_supported($plugins) {
        $plugin_file = plugin_basename(WCSSO_PLUGIN_FILE);
        if (isset($plugins[$plugin_file])) {
            // Keep the toggle available even before the first successful GitHub check.
            $plugins[$plugin_file]['update-supported'] = true;
        }
        return $plugins;
    }

    public static function check_update($update, $plugin_data, $plugin_file) {
        if ($plugin_file !== plugin_basename(WCSSO_PLUGIN_FILE)) {
            return $update;
        }

        $release = self::get_release();
        if (!$release) {
            return $update;
        }

        return [
            'id' => self::REPOSITORY,
            'slug' => self::SLUG,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
            'tested' => $release['tested'],
        ];
    }

    public static function auto_update($update, $item) {
        if (($item->plugin ?? '') !== plugin_basename(WCSSO_PLUGIN_FILE)) {
            return $update;
        }

        if (!empty($item->disable_autoupdate)
            || (defined('WCSSO_AUTO_UPDATE') && !WCSSO_AUTO_UPDATE)) {
            return false;
        }

        // Preserve null during the Plugins screen's forced-update probe, and the
        // saved WordPress choice during background updates. A boolean here would
        // lock the UI and hide its Enable/Disable auto-updates link.
        return $update;
    }

    public static function plugin_information($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }

        $release = self::get_release();
        if (!$release) {
            return $result;
        }

        return (object) [
            'name' => 'WP Cognito SSO',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => 'RSL Group',
            'homepage' => self::REPOSITORY,
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
            'tested' => $release['tested'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Cognito Hosted UI SSO for WordPress with optional user sync.',
                'changelog' => '<pre>' . esc_html($release['notes']) . '</pre>',
            ],
        ];
    }

    public static function clear_cache($upgrader, $options) {
        if (($options['type'] ?? '') === 'plugin' && ($options['action'] ?? '') === 'update'
            && in_array(plugin_basename(WCSSO_PLUGIN_FILE), $options['plugins'] ?? [], true)) {
            delete_site_transient(self::CACHE_KEY);
            delete_site_transient(self::ERROR_KEY);
        }
    }

    private static function request($url) {
        return wp_remote_get($url, [
            'timeout' => 10,
            'limit_response_size' => 262144,
            'headers' => ['Accept' => 'application/vnd.github+json'],
        ]);
    }

    private static function fail($message) {
        set_site_transient(self::ERROR_KEY, $message, 5 * MINUTE_IN_SECONDS);
        return false;
    }

    private static function get_release() {
        $cached = get_site_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return is_array($cached) ? $cached : false;
        }

        // Cache failed/incomplete releases briefly to avoid repeated requests.
        set_site_transient(self::CACHE_KEY, 'unavailable', 5 * MINUTE_IN_SECONDS);
        $response = self::request(self::API);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return self::fail(is_wp_error($response) ? $response->get_error_message()
                : 'GitHub release service returned HTTP ' . wp_remote_retrieve_response_code($response) . '.');
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])
            || !is_string($release['tag_name'] ?? null)
            || !preg_match('/^v?(\d+\.\d+\.\d+)$/D', $release['tag_name'], $matches)) {
            return self::fail('GitHub did not return a valid stable release.');
        }

        $version = $matches[1];
        $tag = rawurlencode($release['tag_name']);
        $package = self::REPOSITORY . '/releases/download/' . $tag . '/wp-cognito-sso.zip';
        $has_package = false;
        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
            if (is_array($asset) && ($asset['name'] ?? '') === 'wp-cognito-sso.zip'
                && ($asset['state'] ?? '') === 'uploaded' && ($asset['size'] ?? 0) > 0
                && ($asset['browser_download_url'] ?? '') === $package) {
                $has_package = true;
                break;
            }
        }
        if (!$has_package) {
            return self::fail('The latest release is missing the installable wp-cognito-sso.zip asset.');
        }

        // Read requirements from the release tag, rather than the installed version.
        $headers = self::request('https://raw.githubusercontent.com/RSLGroup/WP-Cognito/' . $tag
            . '/wp-content/plugins/wp-cognito-sso/wp-cognito-sso.php');
        if (is_wp_error($headers) || wp_remote_retrieve_response_code($headers) !== 200) {
            return self::fail(is_wp_error($headers) ? $headers->get_error_message()
                : 'GitHub release source returned HTTP ' . wp_remote_retrieve_response_code($headers) . '.');
        }
        $source = wp_remote_retrieve_body($headers);
        $metadata = [];
        foreach (['version' => 'Version', 'requires' => 'Requires at least',
            'requires_php' => 'Requires PHP', 'tested' => 'Tested up to'] as $key => $label) {
            if (!preg_match('/^[ \t\/*#@]*' . preg_quote($label, '/') . ':[ \t]*(\d+(?:\.\d+){1,2})[ \t]*\r?$/m', $source, $value)) {
                return self::fail('The release source is missing a valid ' . $label . ' header.');
            }
            $metadata[$key] = $value[1];
        }
        if ($metadata['version'] !== $version) {
            return self::fail('The release tag and plugin Version header do not match.');
        }

        $metadata['package'] = $package;
        $metadata['url'] = self::REPOSITORY . '/releases/tag/' . $tag;
        $metadata['notes'] = is_string($release['body'] ?? null) ? $release['body'] : '';
        set_site_transient(self::CACHE_KEY, $metadata, HOUR_IN_SECONDS);
        delete_site_transient(self::ERROR_KEY);
        return $metadata;
    }
}
