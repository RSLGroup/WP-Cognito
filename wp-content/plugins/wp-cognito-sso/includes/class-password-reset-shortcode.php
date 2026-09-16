<?php

defined('ABSPATH') || exit;

use Aws\CognitoIdentityProvider\CognitoIdentityProviderClient;
use Aws\Exception\AwsException;

class WCSSO_Password_Reset_Shortcode
{
    const SHORTCODE = 'wcsso_cognito_password_reset';
    const ACTION = 'wcsso_cognito_password_reset';
    const NONCE_FIELD = 'wcsso_cognito_password_reset_nonce';

    public static function init()
    {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'render']);
        add_action('wp_ajax_wcsso_cognito_password_reset', [__CLASS__, 'handle_ajax']);
    }

    public static function render()
    {
        if (!is_user_logged_in()) {
            return '<p class="wcsso-password-reset-message">' . esc_html__('You must be logged in to reset your password.', 'wcsso') . '</p>';
        }

        self::enqueue_assets();

        $message = '';
        if (
            isset($_POST['wcsso_cognito_password_reset_submit']) &&
            isset($_POST[self::NONCE_FIELD]) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])), self::ACTION)
        ) {
            $result = self::handle_submission();
            if (is_wp_error($result)) {
                $message = '<p class="wcsso-password-reset-message wcsso-password-reset-error">' . esc_html($result->get_error_message()) . '</p>';
            } else {
                $message = '<p class="wcsso-password-reset-message wcsso-password-reset-success">' . esc_html__('Your password has been updated.', 'wcsso') . '</p>';
            }
        }

        ob_start();
        echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
        <form method="post" class="wcsso-password-reset-form" data-wcsso-password-reset-form>
            <?php wp_nonce_field(self::ACTION, self::NONCE_FIELD); ?>
            <input type="hidden" name="action" value="wcsso_cognito_password_reset" />
            <p>
                <label for="wcsso_new_password"><?php echo esc_html__('New password', 'wcsso'); ?></label>
                <input type="password" id="wcsso_new_password" name="wcsso_new_password" required autocomplete="new-password" />
            </p>
            <p>
                <label for="wcsso_confirm_password"><?php echo esc_html__('Confirm password', 'wcsso'); ?></label>
                <input type="password" id="wcsso_confirm_password" name="wcsso_confirm_password" required autocomplete="new-password" />
            </p>
            <p>
                <button type="submit" name="wcsso_cognito_password_reset_submit" value="1">
                    <?php echo esc_html__('Update password', 'wcsso'); ?>
                </button>
            </p>
        </form>
        <?php
        return ob_get_clean();
    }

    public static function handle_ajax()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error([
                'message' => __('You must be logged in to reset your password.', 'wcsso'),
            ], 401);
        }

        if (
            !isset($_POST[self::NONCE_FIELD]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])), self::ACTION)
        ) {
            wp_send_json_error([
                'message' => __('Your session has expired. Refresh the page and try again.', 'wcsso'),
            ], 403);
        }

        $result = self::handle_submission();
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code(),
            ], 400);
        }

        wp_send_json_success([
            'message' => __('Your password has been updated.', 'wcsso'),
        ]);
    }

    private static function enqueue_assets()
    {
        wp_enqueue_script(
            'wcsso-password-reset',
            WCSSO_PLUGIN_URL . 'assets/password-reset.js',
            [],
            '1.0.0',
            true
        );

        wp_localize_script('wcsso-password-reset', 'wcssoPasswordReset', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'workingText' => __('Updating password...', 'wcsso'),
        ]);
    }

    private static function handle_submission()
    {
        $password = isset($_POST['wcsso_new_password']) ? (string) wp_unslash($_POST['wcsso_new_password']) : '';
        $confirm = isset($_POST['wcsso_confirm_password']) ? (string) wp_unslash($_POST['wcsso_confirm_password']) : '';

        if ($password === '' || $confirm === '') {
            return new WP_Error('wcsso_password_required', __('Enter and confirm your new password.', 'wcsso'));
        }

        if ($password !== $confirm) {
            return new WP_Error('wcsso_password_mismatch', __('Passwords do not match.', 'wcsso'));
        }

        $minimum_length = (int) apply_filters('wcsso_cognito_password_min_length', 8);
        if (strlen($password) < $minimum_length) {
            return new WP_Error(
                'wcsso_password_too_short',
                sprintf(
                    /* translators: %d: minimum password length */
                    __('Password must be at least %d characters long.', 'wcsso'),
                    $minimum_length
                )
            );
        }

        if (!class_exists('Aws\\CognitoIdentityProvider\\CognitoIdentityProviderClient')) {
            return new WP_Error('wcsso_aws_sdk_missing', __('Password reset is unavailable because the AWS SDK is not installed.', 'wcsso'));
        }

        $settings = wcsso_get_settings();
        $required = [
            'aws_region',
            'aws_access_key',
            'aws_secret_key',
            'aws_user_pool_id',
        ];

        foreach ($required as $key) {
            if (empty($settings[$key])) {
                return new WP_Error('wcsso_aws_config_missing', __('Password reset is unavailable because Cognito AWS settings are incomplete.', 'wcsso'));
            }
        }

        $user = wp_get_current_user();
        if (!($user instanceof WP_User) || empty($user->ID)) {
            return new WP_Error('wcsso_user_missing', __('Unable to identify the current user.', 'wcsso'));
        }

        return self::set_cognito_password($settings, $user, $password);
    }

    private static function set_cognito_password($settings, WP_User $user, $password)
    {
        $client = new CognitoIdentityProviderClient([
            'region' => $settings['aws_region'],
            'version' => '2016-04-18',
            'credentials' => [
                'key' => $settings['aws_access_key'],
                'secret' => $settings['aws_secret_key'],
            ],
        ]);

        $wp_username = (string) $user->user_login;
        $wp_email = (string) $user->user_email;
        $usernames = array_filter(array_unique([
            $wp_username,
            $wp_email,
        ]));

        $last_error = null;
        foreach ($usernames as $username) {
            try {
                $client->adminSetUserPassword([
                    'UserPoolId' => $settings['aws_user_pool_id'],
                    'Username' => $username,
                    'Password' => $password,
                    'Permanent' => true,
                ]);

                do_action('wcsso_cognito_password_reset_success', $user->ID, $username);
                return true;
            } catch (AwsException $e) {
                $last_error = $e;
                if ($e->getAwsErrorCode() !== 'UserNotFoundException') {
                    break;
                }
            }
        }

        if ($last_error instanceof AwsException && $last_error->getAwsErrorCode() === 'UserNotFoundException') {
            return self::create_cognito_user_and_set_password($client, $settings, $user, $password);
        }

        if ($last_error instanceof AwsException) {
            self::log_aws_error($last_error);
            do_action('wcsso_cognito_password_reset_error', $user->ID, $last_error);
            return self::aws_exception_to_error($last_error);
        }

        return new WP_Error('wcsso_cognito_password_reset_failed', __('Unable to update your Cognito password. Please try again or contact support.', 'wcsso'));
    }

    private static function create_cognito_user_and_set_password(CognitoIdentityProviderClient $client, $settings, WP_User $user, $password)
    {
        $username = (string) $user->user_login;
        $email = (string) $user->user_email;

        if ($username === '' || $email === '') {
            return new WP_Error('wcsso_cognito_create_missing_user_data', __('Unable to create your Cognito user because your WordPress account is missing a username or email address.', 'wcsso'));
        }

        $first_name = (string) get_user_meta($user->ID, 'first_name', true);
        $last_name = (string) get_user_meta($user->ID, 'last_name', true);
        $display_name = trim((string) $user->display_name);
        $full_name = trim($first_name . ' ' . $last_name);
        if ($full_name === '') {
            $full_name = $display_name;
        }

        $attributes = [
            ['Name' => 'email', 'Value' => $email],
            ['Name' => 'email_verified', 'Value' => 'true'],
        ];

        if ($full_name !== '') {
            $attributes[] = ['Name' => 'name', 'Value' => $full_name];
        }

        if ($first_name !== '') {
            $attributes[] = ['Name' => 'given_name', 'Value' => $first_name];
        }

        if ($last_name !== '') {
            $attributes[] = ['Name' => 'family_name', 'Value' => $last_name];
        }

        $role_attribute = $settings['sync_role_attribute_name'] ?: 'custom:user_role';
        $primary_role = wcsso_get_primary_role($user->ID) ?: $settings['default_wp_role'];
        if (!empty($settings['sync_role_enabled']) && $primary_role !== '') {
            $attributes[] = ['Name' => $role_attribute, 'Value' => $primary_role];
        }

        try {
            $client->adminCreateUser([
                'UserPoolId' => $settings['aws_user_pool_id'],
                'Username' => $username,
                'UserAttributes' => $attributes,
                'MessageAction' => 'SUPPRESS',
            ]);

            $client->adminSetUserPassword([
                'UserPoolId' => $settings['aws_user_pool_id'],
                'Username' => $username,
                'Password' => $password,
                'Permanent' => true,
            ]);

            do_action('wcsso_cognito_password_reset_user_created', $user->ID, $username, $attributes);
            do_action('wcsso_cognito_password_reset_success', $user->ID, $username);
            return true;
        } catch (AwsException $e) {
            self::log_aws_error($e);
            do_action('wcsso_cognito_password_reset_error', $user->ID, $e);
            return self::aws_exception_to_error($e);
        }
    }

    private static function aws_exception_to_error(AwsException $e)
    {
        $code = $e->getAwsErrorCode();
        $message = $e->getAwsErrorMessage() ?: $e->getMessage();

        if ($code === 'InvalidPasswordException') {
            return new WP_Error('wcsso_cognito_invalid_password', $message ?: __('Password does not meet Cognito password requirements.', 'wcsso'));
        }

        if ($code === 'NotAuthorizedException' || $code === 'AccessDeniedException' || $code === 'UnrecognizedClientException') {
            return new WP_Error('wcsso_cognito_permission_denied', __('Cognito rejected the configured AWS credentials or permissions.', 'wcsso'));
        }

        if ($code === 'UsernameExistsException' || $code === 'AliasExistsException') {
            return new WP_Error('wcsso_cognito_username_exists', __('A Cognito user with this username or email already exists but could not be updated.', 'wcsso'));
        }

        if ($code === 'InvalidParameterException') {
            return new WP_Error('wcsso_cognito_invalid_parameter', $message ?: __('Cognito rejected one of the submitted user fields.', 'wcsso'));
        }

        if ($code === 'ResourceNotFoundException') {
            return new WP_Error('wcsso_cognito_pool_missing', __('Cognito could not find the configured user pool.', 'wcsso'));
        }

        return new WP_Error(
            'wcsso_cognito_password_reset_failed',
            $message ?: __('Unable to update your Cognito password. Please try again or contact support.', 'wcsso')
        );
    }

    private static function log_aws_error(AwsException $e)
    {
        wcsso_log([
            'message' => $e->getMessage(),
            'aws_error_code' => $e->getAwsErrorCode(),
            'aws_error_message' => $e->getAwsErrorMessage(),
            'aws_status_code' => $e->getStatusCode(),
        ]);
    }
}
