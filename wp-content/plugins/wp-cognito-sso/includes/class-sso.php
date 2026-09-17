<?php

defined('ABSPATH') || exit;

class WCSSO_SSO {
    public static function init() {
        add_action('init', [__CLASS__, 'add_rewrite_rules']);
        add_filter('query_vars', [__CLASS__, 'register_query_var']);
        add_action('template_redirect', [__CLASS__, 'handle_callback'], 1);
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_to_cognito'], 2);
        add_action('login_form', [__CLASS__, 'login_form_redirect']);
        add_action('wp_logout', [__CLASS__, 'handle_logout']);
    }

    public static function add_rewrite_rules() {
        $settings = wcsso_get_settings();
        $path = trim(wcsso_normalize_path($settings['redirect_path']), '/');
        add_rewrite_rule('^' . preg_quote($path, '#') . '/?$', 'index.php?wcsso_cognito_login=1', 'top');
    }

    public static function register_query_var($vars) {
        $vars[] = 'wcsso_cognito_login';
        return $vars;
    }

    public static function login_form_redirect() {
        $settings = wcsso_get_settings();
        if (empty($settings['login_enabled'])) {
            return;
        }
        if (is_user_logged_in()) {
            return;
        }
        if (wcsso_is_request_excluded()) {
            return;
        }

        $original_url = isset($_GET['redirect_to']) ? sanitize_text_field($_GET['redirect_to']) : home_url();
        self::redirect_to_cognito($original_url);
    }

    public static function maybe_redirect_to_cognito() {
        $settings = wcsso_get_settings();
        if (empty($settings['login_enabled']) || empty($settings['auto_redirect_login'])) {
            return;
        }
        if (is_user_logged_in()) {
            return;
        }
        if (wcsso_is_request_excluded()) {
            return;
        }

        self::redirect_to_cognito(wcsso_current_url());
    }

    private static function redirect_to_cognito($redirect_to) {
        $settings = wcsso_get_settings();
        $client_id = $settings['client_id'];
        $cognito_domain = $settings['cognito_domain'];
        $redirect_uri = home_url(wcsso_normalize_path($settings['redirect_path']));

        if ($client_id === '' || $cognito_domain === '') {
            return;
        }

        $state = base64_encode(wp_json_encode([
            'redirect_to' => $redirect_to,
            'sso_attempted' => '0',
        ]));

        $scope = apply_filters('wcsso_scope', $settings['scope']);

        $params = [
            'client_id' => $client_id,
            'response_type' => 'code',
            'scope' => $scope,
            'redirect_uri' => $redirect_uri,
            'state' => $state,
        ];

        $login_url = 'https://' . $cognito_domain . '/oauth2/authorize?' . http_build_query($params);

        do_action('wcsso_before_redirect_to_cognito', $login_url, $state);
        wp_redirect($login_url);
        exit;
    }

    public static function handle_callback() {
        if (!get_query_var('wcsso_cognito_login')) {
            return;
        }

        $settings = wcsso_get_settings();
        if (empty($settings['login_enabled'])) {
            wp_die('Cognito SSO is disabled.');
        }

        if (!isset($_GET['code'])) {
            wp_die('Cognito login error: missing authorization code.');
        }

        $code = sanitize_text_field($_GET['code']);
        $client_id = $settings['client_id'];
        $client_secret = $settings['client_secret'];
        $cognito_domain = $settings['cognito_domain'];
        $redirect_uri = home_url(wcsso_normalize_path($settings['redirect_path']));

        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        if ($client_id && $client_secret) {
            $headers['Authorization'] = 'Basic ' . base64_encode($client_id . ':' . $client_secret);
        }

        $body = [
            'grant_type' => 'authorization_code',
            'client_id' => $client_id,
            'code' => $code,
            'redirect_uri' => $redirect_uri,
        ];
        // OAuth client authentication must use one method. Prefer HTTP Basic when
        // a client ID and secret are available; sending both Basic credentials and
        // client_secret in the body is rejected by the SSO broker.
        if ($client_secret && !$client_id) {
            $body['client_secret'] = $client_secret;
        }

        $response = wp_remote_post('https://' . $cognito_domain . '/oauth2/token', [
            'body' => http_build_query($body),
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            wcsso_log($response->get_error_message());
            wp_die('Cognito login failed.');
        }

        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($response_body['error'])) {
            wcsso_log($response_body);
            wp_die('Cognito login failed: ' . esc_html($response_body['error']));
        }

        if (empty($response_body['id_token'])) {
            wcsso_log($response_body);
            wp_die('Cognito login failed: missing id_token.');
        }

        do_action('wcsso_after_token_exchange', $response_body);

        $claims = WCSSO_JWT::decode($response_body['id_token'], [
            'verify' => !empty($settings['jwt_verify']),
            'client_id' => $client_id,
            'cognito_domain' => $cognito_domain,
        ]);

        if (is_wp_error($claims)) {
            wcsso_log($claims->get_error_message());
            wp_die('Cognito login failed: invalid token.');
        }

        $claims = apply_filters('wcsso_claims', $claims);

        if (!empty($settings['provisioning_enabled'])) {
            $user_id = WCSSO_User_Provisioner::create_or_update($claims);
            if (is_wp_error($user_id)) {
                wcsso_log($user_id->get_error_message());
                wp_die('Cognito login failed: user provisioning error.');
            }
        } else {
            $email = $claims[$settings['email_claim_key']] ?? '';
            $user = $email ? get_user_by('email', $email) : null;
            $user_id = $user ? $user->ID : 0;
        }

        if (empty($user_id)) {
            wp_die('Cognito login failed: user not found.');
        }

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);

        $redirect_to = home_url();
        $new_user_redirect = trim((string) $settings['new_user_redirect_url']);
        $is_new_user_login = (bool) get_user_meta($user_id, 'wcsso_pending_new_user_redirect', true);
        $should_use_new_user_redirect = $is_new_user_login && !empty($new_user_redirect);

        if ($should_use_new_user_redirect) {
            $resolved_redirect = wcsso_resolve_redirect_target($new_user_redirect);
            if ($resolved_redirect !== '') {
                $redirect_to = $resolved_redirect;
            }
        }

        if ($is_new_user_login) {
            delete_user_meta($user_id, 'wcsso_pending_new_user_redirect');
        }

        if (!$should_use_new_user_redirect && !empty($_GET['state'])) {
            $state = json_decode(base64_decode(sanitize_text_field($_GET['state'])), true);
            if (!empty($state['redirect_to']) && wcsso_is_safe_redirect($state['redirect_to'])) {
                $parsed = wp_parse_url($state['redirect_to']);
                $path = isset($parsed['path']) ? untrailingslashit($parsed['path']) : '';
                if (!in_array($path, wcsso_get_excluded_paths(), true)) {
                    $redirect_to = $state['redirect_to'];
                }
            }
        }

        wp_redirect($redirect_to);
        exit;
    }

    public static function handle_logout() {
        $settings = wcsso_get_settings();
        if (empty($settings['logout_enabled'])) {
            return;
        }

        if (!empty($_GET['logged_out'])) {
            return;
        }

        $current_path = wp_parse_url(wcsso_current_url(), PHP_URL_PATH);
        if ($current_path && untrailingslashit($current_path) === untrailingslashit(wcsso_normalize_path($settings['logout_redirect_path']))) {
            return;
        }

        if (empty($settings['cognito_domain']) || empty($settings['client_id'])) {
            return;
        }

        $logout_redirect = home_url(wcsso_normalize_path($settings['logout_redirect_path']));
        $logout_url = 'https://' . $settings['cognito_domain'] . '/logout?client_id=' . rawurlencode($settings['client_id']) . '&logout_uri=' . rawurlencode($logout_redirect);
        wp_redirect($logout_url);
        exit;
    }
}
