<?php

defined('ABSPATH') || exit;

/** Connect published GitHub release packages to WordPress's native updater. */
class WCSSO_Updater {
    const REPOSITORY = 'https://github.com/RSLGroup/WP-Cognito';
    const API = 'https://api.github.com/repos/RSLGroup/WP-Cognito/releases/latest';
    const CACHE_KEY = 'wcsso_github_release';
    const SLUG = 'wp-cognito-sso';

    public static function init() {
        add_filter('update_plugins_github.com', [__CLASS__, 'check_update'], 10, 3);
        add_filter('auto_update_plugin', [__CLASS__, 'auto_update'], 10, 2);
        add_filter('plugins_api', [__CLASS__, 'plugin_information'], 10, 3);
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache'], 10, 2);
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

        // WordPress still enforces its global updater and filesystem restrictions.
        return empty($item->disable_autoupdate)
            && (!defined('WCSSO_AUTO_UPDATE') || WCSSO_AUTO_UPDATE);
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
        }
    }

    private static function request($url) {
        return wp_remote_get($url, [
            'timeout' => 10,
            'limit_response_size' => 262144,
            'headers' => ['Accept' => 'application/vnd.github+json'],
        ]);
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
            return false;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])
            || !is_string($release['tag_name'] ?? null)
            || !preg_match('/^v?(\d+\.\d+\.\d+)$/D', $release['tag_name'], $matches)) {
            return false;
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
            return false;
        }

        // Read requirements from the release tag, rather than the installed version.
        $headers = self::request('https://raw.githubusercontent.com/RSLGroup/WP-Cognito/' . $tag
            . '/wp-content/plugins/wp-cognito-sso/wp-cognito-sso.php');
        if (is_wp_error($headers) || wp_remote_retrieve_response_code($headers) !== 200) {
            return false;
        }
        $source = wp_remote_retrieve_body($headers);
        $metadata = [];
        foreach (['version' => 'Version', 'requires' => 'Requires at least',
            'requires_php' => 'Requires PHP', 'tested' => 'Tested up to'] as $key => $label) {
            if (!preg_match('/^[ \t\/*#@]*' . preg_quote($label, '/') . ':[ \t]*(\d+(?:\.\d+){1,2})[ \t]*\r?$/m', $source, $value)) {
                return false;
            }
            $metadata[$key] = $value[1];
        }
        if ($metadata['version'] !== $version) {
            return false;
        }

        $metadata['package'] = $package;
        $metadata['url'] = self::REPOSITORY . '/releases/tag/' . $tag;
        $metadata['notes'] = is_string($release['body'] ?? null) ? $release['body'] : '';
        set_site_transient(self::CACHE_KEY, $metadata, HOUR_IN_SECONDS);
        return $metadata;
    }
}
