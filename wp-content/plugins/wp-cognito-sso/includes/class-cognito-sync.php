<?php

defined('ABSPATH') || exit;

use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\Exception\AwsException;

class WCSSO_Cognito_Sync {
    public static function init() {
        add_action('user_register', [__CLASS__, 'on_user_register'], 10, 1);
        add_action('profile_update', [__CLASS__, 'on_profile_update'], 10, 2);
    }

    public static function on_user_register($user_id) {
        $settings = wcsso_get_settings();
        if (empty($settings['sync_enabled']) || empty($settings['sync_on_user_register'])) {
            return;
        }
        self::sync_user($user_id);
    }

    public static function on_profile_update($user_id, $old_user_data) {
        $settings = wcsso_get_settings();
        if (empty($settings['sync_enabled']) || empty($settings['sync_on_profile_update'])) {
            return;
        }
        self::sync_user($user_id);
    }

    private static function sync_user($user_id) {
        if (!class_exists('Aws\\CognitoIdentityProvider\\CognitoIdentityProviderClient')) {
            wcsso_log('AWS SDK missing, sync skipped.');
            return;
        }

        $settings = wcsso_get_settings();
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $aws_region = $settings['aws_region'];
        $access_key = $settings['aws_access_key'];
        $secret_key = $settings['aws_secret_key'];
        $user_pool_id = $settings['aws_user_pool_id'];

        if (!$aws_region || !$access_key || !$secret_key || !$user_pool_id) {
            wcsso_log('Cognito sync aborted: missing AWS config.');
            return;
        }

        $client = new CognitoIdentityProviderClient([
            'region' => $aws_region,
            'version' => '2016-04-18',
            'credentials' => [
                'key' => $access_key,
                'secret' => $secret_key,
            ],
        ]);

        $first_name = get_user_meta($user_id, 'first_name', true) ?? '';
        $last_name = get_user_meta($user_id, 'last_name', true) ?? '';
        $full_name = trim($first_name . ' ' . $last_name);
        if ($full_name === '') {
            $full_name = $user->display_name ?? '';
        }

        $email = $user->user_email;
        if (empty($email)) {
            wcsso_log('Cognito sync aborted: missing user email.');
            return;
        }

        $user_phone = trim((string) get_user_meta($user_id, 'billing_phone', true));
        $address = wcsso_get_user_address($user_id);

        $role_attribute = $settings['sync_role_attribute_name'] ?: 'custom:user_role';
        $primary_role = wcsso_get_primary_role($user_id) ?: $settings['default_wp_role'];
        $cognito_role = self::get_cognito_role_value($primary_role, $settings);

        // Cognito rejects some empty attributes (notably phone_number). Only
        // submit profile data WordPress actually has, so one incomplete field
        // cannot prevent the entire account from being created or updated.
        $attributes = [
            ['Name' => 'email', 'Value' => $email],
            ['Name' => 'email_verified', 'Value' => 'true'],
        ];

        self::add_attribute_if_present($attributes, 'name', $full_name);
        self::add_attribute_if_present($attributes, 'given_name', $first_name);
        self::add_attribute_if_present($attributes, 'family_name', $last_name);
        self::add_attribute_if_present($attributes, 'address', $address);

        if ($user_phone !== '') {
            if (preg_match('/^\+[1-9][0-9]{1,14}$/', $user_phone)) {
                $attributes[] = ['Name' => 'phone_number', 'Value' => $user_phone];
                $attributes[] = ['Name' => 'phone_number_verified', 'Value' => 'true'];
            } else {
                wcsso_log('Cognito sync skipped an invalid billing phone number for WordPress user ' . $user_id . '. Phone numbers must use E.164 format.');
            }
        }

        if (!empty($settings['sync_role_enabled'])) {
            $attributes[] = ['Name' => $role_attribute, 'Value' => $cognito_role];
        }

        $raw_pass = apply_filters('wcsso_raw_password_for_user_sync', null, $user_id);

        try {
            $client->adminUpdateUserAttributes([
                'UserPoolId' => $user_pool_id,
                'Username' => $user->user_login,
                'UserAttributes' => $attributes,
            ]);
            if (is_string($raw_pass) && $raw_pass !== '') {
                $client->adminSetUserPassword([
                    'UserPoolId' => $user_pool_id,
                    'Username' => $user->user_login,
                    'Password' => $raw_pass,
                    'Permanent' => true,
                ]);
            }
            do_action('wcsso_sync_success', $user_id, 'update', $attributes);
        } catch (AwsException $e) {
            $code = $e->getAwsErrorCode();
            if ($code === 'UserNotFoundException') {
                try {
                    $params = [
                        'UserPoolId' => $user_pool_id,
                        'Username' => $user->user_login,
                        'UserAttributes' => $attributes,
                    ];
                    $client->adminCreateUser($params);

                    if (is_string($raw_pass) && $raw_pass !== '') {
                        $client->adminSetUserPassword([
                            'UserPoolId' => $user_pool_id,
                            'Username' => $user->user_login,
                            'Password' => $raw_pass,
                            'Permanent' => true,
                        ]);
                    }
                    do_action('wcsso_sync_success', $user_id, 'create', $attributes);
                } catch (AwsException $e2) {
                    wcsso_log($e2->getMessage());
                    do_action('wcsso_sync_error', $user_id, 'create', $e2);
                }
            } else {
                wcsso_log($e->getMessage());
                do_action('wcsso_sync_error', $user_id, 'update', $e);
            }
        }
    }

    private static function add_attribute_if_present(array &$attributes, $name, $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $attributes[] = ['Name' => $name, 'Value' => $value];
        }
    }

    /**
     * Role mapping is stored as Cognito claim value => WordPress role. Reverse
     * that mapping for WordPress-to-Cognito sync so the same mapping works in
     * both directions. For example, instructor => music_teacher means a
     * music_teacher user is written to Cognito as instructor.
     */
    private static function get_cognito_role_value($wp_role, array $settings) {
        $role_map = is_array($settings['role_mapping'] ?? null) ? $settings['role_mapping'] : [];

        foreach ($role_map as $claim_value => $mapped_wp_role) {
            if ($mapped_wp_role === $wp_role) {
                return apply_filters('wcsso_mapped_cognito_role', $claim_value, $wp_role, $role_map);
            }
        }

        return apply_filters('wcsso_mapped_cognito_role', $wp_role, $wp_role, $role_map);
    }
}
