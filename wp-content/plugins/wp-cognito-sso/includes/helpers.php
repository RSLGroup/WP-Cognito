<?php

defined('ABSPATH') || exit;

function wcsso_get_default_settings() {
    return [
        'cognito_domain' => '',
        'client_id' => '',
        'client_secret' => '',
        'scope' => 'openid email profile',
        'redirect_path' => '/cognito-login',
        'login_enabled' => 0,
        'auto_redirect_login' => 0,
        'excluded_paths' => "/logout\n/cognito-login",
        'new_user_redirect_url' => '',
        'logout_enabled' => 0,
        'logout_redirect_path' => '/logout',
        'provisioning_enabled' => 1,
        'email_claim_key' => 'email',
        'username_claim_key' => 'cognito:username',
        'display_name_claim_key' => 'name',
        'role_claim_key' => 'custom:user_role',
        'role_mapping' => [],
        'default_wp_role' => 'subscriber',
        'only_set_role_if_current_role_is_default' => 1,
        'sync_enabled' => 0,
        'aws_region' => '',
        'aws_access_key' => '',
        'aws_secret_key' => '',
        'aws_user_pool_id' => '',
        'sync_role_attribute_name' => 'custom:user_role',
        'sync_role_enabled' => 1,
        'sync_on_profile_update' => 1,
        'sync_on_user_register' => 1,
        'migration_shared_secret' => '',
        'debug' => 0,
        'jwt_verify' => 1,
    ];
}

function wcsso_get_settings() {
    $defaults = wcsso_get_default_settings();
    $saved = get_option('wcsso_settings', []);
    if (!is_array($saved)) {
        $saved = [];
    }
    return array_merge($defaults, $saved);
}

function wcsso_get_setting($key, $default = null) {
    $settings = wcsso_get_settings();
    if (array_key_exists($key, $settings)) {
        return $settings[$key];
    }
    return $default;
}

function wcsso_is_absolute_url($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }

    return (bool) preg_match('#^https?://#i', $value);
}

function wcsso_sanitize_redirect_target($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (wcsso_is_absolute_url($value)) {
        return esc_url_raw($value, ['http', 'https']);
    }

    $parts = wp_parse_url($value);
    if ($parts === false) {
        return '';
    }

    $path = $parts['path'] ?? '';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return wcsso_normalize_path($path) . $query . $fragment;
}

function wcsso_resolve_redirect_target($value) {
    $value = wcsso_sanitize_redirect_target($value);
    if ($value === '') {
        return '';
    }

    if (wcsso_is_absolute_url($value)) {
        return $value;
    }

    return home_url($value);
}

function wcsso_normalize_path($path) {
    $path = trim((string) $path);
    if ($path === '') {
        return '/';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    return '/' . ltrim($path, '/');
}

function wcsso_current_url() {
    $scheme = is_ssl() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return $scheme . '://' . $host . $uri;
}

function wcsso_is_safe_redirect($url) {
    $home = wp_parse_url(home_url());
    $target = wp_parse_url($url);
    if (empty($target['host']) || empty($home['host'])) {
        return false;
    }
    return strtolower($target['host']) === strtolower($home['host']);
}

function wcsso_get_excluded_paths() {
    $settings = wcsso_get_settings();
    $paths = preg_split('/\r\n|\r|\n/', (string) $settings['excluded_paths']);
    $paths = array_filter(array_map('trim', $paths));

    $paths[] = wcsso_normalize_path($settings['redirect_path']);
    $paths[] = wcsso_normalize_path($settings['logout_redirect_path']);

    $paths = array_unique(array_map('untrailingslashit', $paths));

    return apply_filters('wcsso_excluded_paths', $paths);
}

function wcsso_is_request_excluded() {
    $settings = wcsso_get_settings();
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
        return true;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return true;
    }

    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $allow_login_hijack = !empty($settings['login_enabled']);
    if (
        !$allow_login_hijack &&
        (strpos($request_uri, 'wp-login.php') !== false || strpos($request_uri, 'wp-admin') !== false)
    ) {
        return true;
    }

    $parsed = wp_parse_url(wcsso_current_url());
    $path = isset($parsed['path']) ? untrailingslashit($parsed['path']) : '';

    foreach (wcsso_get_excluded_paths() as $excluded) {
        if ($excluded === '') {
            continue;
        }
        if (untrailingslashit($excluded) === $path) {
            return true;
        }
    }

    return false;
}

function wcsso_log($message) {
    if (!wcsso_get_setting('debug')) {
        return;
    }
    if (is_array($message) || is_object($message)) {
        $message = wp_json_encode($message);
    }
    error_log('[wcsso] ' . $message);
}

function wcsso_generate_unique_username($base) {
    $base = sanitize_user($base, true);
    if ($base === '') {
        $base = 'user';
    }
    $username = $base;
    $suffix = 1;
    while (username_exists($username)) {
        $username = $base . $suffix;
        $suffix++;
    }
    return $username;
}

function wcsso_get_primary_role($user_id) {
    $user = get_userdata($user_id);
    if (!$user || empty($user->roles)) {
        return '';
    }
    $roles = array_values($user->roles);
    return $roles[0] ?? '';
}

function wcsso_get_user_address($user_id) {
    $billing_address_1 = get_user_meta($user_id, 'billing_address_1', true) ?? '';
    $billing_address_2 = get_user_meta($user_id, 'billing_address_2', true) ?? '';
    $billing_city = get_user_meta($user_id, 'billing_city', true) ?? '';
    $billing_postcode = get_user_meta($user_id, 'billing_postcode', true) ?? '';
    $billing_state = get_user_meta($user_id, 'billing_state', true) ?? '';
    $billing_country = get_user_meta($user_id, 'billing_country', true) ?? '';

    if ($billing_address_1 === '' && $billing_address_2 === '' && $billing_city === '' && $billing_postcode === '' && $billing_state === '' && $billing_country === '') {
        return '';
    }

    $billing_address = trim("$billing_address_1 $billing_address_2, $billing_city, $billing_postcode, $billing_country");
    $billing_street_address = trim("$billing_address_1 $billing_address_2");

    $address = [
        'formatted' => $billing_address,
        'street_address' => $billing_street_address,
        'locality' => $billing_city,
        'region' => $billing_state,
        'postal_code' => $billing_postcode,
        'country' => $billing_country,
    ];

    return wp_json_encode($address);
}
