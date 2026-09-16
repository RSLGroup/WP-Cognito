<?php

defined('ABSPATH') || exit;

class WCSSO_Settings
{
    const OPTION = 'wcsso_settings';

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_notices', [__CLASS__, 'maybe_show_sdk_notice']);
    }

    public static function add_menu()
    {
        add_options_page(
            'Cognito Hosted UI',
            'Cognito Hosted UI',
            'manage_options',
            'wcsso-settings',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings()
    {
        register_setting('wcsso_settings_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default' => wcsso_get_default_settings(),
        ]);
    }

    public static function enqueue_assets($hook)
    {
        if ($hook !== 'settings_page_wcsso-settings') {
            return;
        }
        wp_enqueue_script('wcsso-admin', WCSSO_PLUGIN_URL . 'assets/admin.js', ['jquery'], '1.0.0', true);
        wp_enqueue_style('wcsso-admin', WCSSO_PLUGIN_URL . 'assets/admin.css', [], '1.0.0');
    }

    public static function maybe_show_sdk_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = wcsso_get_settings();
        if (empty($settings['sync_enabled'])) {
            return;
        }
        if (class_exists('Aws\\CognitoIdentityProvider\\CognitoIdentityProviderClient')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' . esc_html__('WP Cognito SSO: AWS SDK not found. Install aws/aws-sdk-php or disable sync.', 'wcsso') . '</p></div>';
    }

    public static function sanitize_settings($input)
    {
        $defaults = wcsso_get_default_settings();
        $input = is_array($input) ? $input : [];
        $sanitized = [];

        $previous = get_option(self::OPTION, []);
        if (!is_array($previous)) {
            $previous = [];
        }

        $sanitized['cognito_domain'] = sanitize_text_field($input['cognito_domain'] ?? $defaults['cognito_domain']);
        $sanitized['client_id'] = sanitize_text_field($input['client_id'] ?? $defaults['client_id']);
        $sanitized['client_secret'] = sanitize_text_field($input['client_secret'] ?? $defaults['client_secret']);
        $sanitized['scope'] = sanitize_text_field($input['scope'] ?? $defaults['scope']);

        $sanitized['redirect_path'] = wcsso_normalize_path($input['redirect_path'] ?? $defaults['redirect_path']);
        $sanitized['login_enabled'] = !empty($input['login_enabled']) ? 1 : 0;
        $sanitized['auto_redirect_login'] = !empty($input['auto_redirect_login']) ? 1 : 0;
        $sanitized['excluded_paths'] = sanitize_textarea_field($input['excluded_paths'] ?? $defaults['excluded_paths']);
        $sanitized['new_user_redirect_url'] = wcsso_sanitize_redirect_target($input['new_user_redirect_url'] ?? $defaults['new_user_redirect_url']);

        $sanitized['logout_enabled'] = !empty($input['logout_enabled']) ? 1 : 0;
        $sanitized['logout_redirect_path'] = wcsso_normalize_path($input['logout_redirect_path'] ?? $defaults['logout_redirect_path']);

        $sanitized['provisioning_enabled'] = !empty($input['provisioning_enabled']) ? 1 : 0;
        $sanitized['email_claim_key'] = sanitize_text_field($input['email_claim_key'] ?? $defaults['email_claim_key']);
        $sanitized['username_claim_key'] = sanitize_text_field($input['username_claim_key'] ?? $defaults['username_claim_key']);
        $sanitized['display_name_claim_key'] = sanitize_text_field($input['display_name_claim_key'] ?? $defaults['display_name_claim_key']);

        $sanitized['role_claim_key'] = sanitize_text_field($input['role_claim_key'] ?? $defaults['role_claim_key']);
        $sanitized['default_wp_role'] = sanitize_key($input['default_wp_role'] ?? $defaults['default_wp_role']);
        $sanitized['only_set_role_if_current_role_is_default'] = !empty($input['only_set_role_if_current_role_is_default']) ? 1 : 0;

        $sanitized['sync_enabled'] = !empty($input['sync_enabled']) ? 1 : 0;
        $sanitized['aws_region'] = sanitize_text_field($input['aws_region'] ?? $defaults['aws_region']);
        $sanitized['aws_access_key'] = sanitize_text_field($input['aws_access_key'] ?? $defaults['aws_access_key']);
        $sanitized['aws_secret_key'] = sanitize_text_field($input['aws_secret_key'] ?? $defaults['aws_secret_key']);
        $sanitized['aws_user_pool_id'] = sanitize_text_field($input['aws_user_pool_id'] ?? $defaults['aws_user_pool_id']);
        $sanitized['sync_role_attribute_name'] = sanitize_text_field($input['sync_role_attribute_name'] ?? $defaults['sync_role_attribute_name']);
        $sanitized['sync_role_enabled'] = !empty($input['sync_role_enabled']) ? 1 : 0;
        $sanitized['sync_on_profile_update'] = !empty($input['sync_on_profile_update']) ? 1 : 0;
        $sanitized['sync_on_user_register'] = !empty($input['sync_on_user_register']) ? 1 : 0;
        $sanitized['migration_shared_secret'] = isset($input['migration_shared_secret']) ? trim((string) $input['migration_shared_secret']) : $defaults['migration_shared_secret'];

        $sanitized['debug'] = !empty($input['debug']) ? 1 : 0;
        $sanitized['jwt_verify'] = !empty($input['jwt_verify']) ? 1 : 0;

        $role_map = [];
        $keys = isset($input['role_mapping_keys']) && is_array($input['role_mapping_keys']) ? $input['role_mapping_keys'] : [];
        $values = isset($input['role_mapping_values']) && is_array($input['role_mapping_values']) ? $input['role_mapping_values'] : [];

        foreach ($keys as $index => $key) {
            $key = sanitize_text_field($key);
            $value = isset($values[$index]) ? sanitize_key($values[$index]) : '';
            if ($key === '' || $value === '') {
                continue;
            }
            $role_map[$key] = $value;
        }
        $sanitized['role_mapping'] = $role_map;

        if (($previous['redirect_path'] ?? '') !== $sanitized['redirect_path']) {
            update_option('wcsso_flush_rewrites', 1);
        }

        return array_merge($defaults, $sanitized);
    }

    public static function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = wcsso_get_settings();
        $roles = wp_roles();
        $role_options = $roles ? $roles->roles : [];
        $callback_url = home_url(wcsso_normalize_path($settings['redirect_path']));
        $logo_url = WCSSO_PLUGIN_URL . 'img/rsl-group-logo.svg';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Wordpress AWS Cognito Hosted UI', 'wcsso'); ?></h1>

            <p><img src="<?php echo esc_url($logo_url); ?>" class="rsl-group-dashboard-img"
                    alt="<?php echo esc_attr__('Plugin by RSL Group', 'wcsso'); ?>" width="300" /></p>

            <form method="post" action="options.php">
                <?php settings_fields('wcsso_settings_group'); ?>

                <h2><?php echo esc_html__('Cognito Hosted UI config', 'wcsso'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wcsso_cognito_domain">Cognito domain</label></th>
                        <td><input type="text" id="wcsso_cognito_domain" name="wcsso_settings[cognito_domain]"
                                value="<?php echo esc_attr($settings['cognito_domain']); ?>" class="regular-text"
                                placeholder="auth.example.com" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_client_id">Client ID</label></th>
                        <td><input type="text" id="wcsso_client_id" name="wcsso_settings[client_id]"
                                value="<?php echo esc_attr($settings['client_id']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_client_secret">Client Secret</label></th>
                        <td><input type="password" id="wcsso_client_secret" name="wcsso_settings[client_secret]"
                                value="<?php echo esc_attr($settings['client_secret']); ?>" class="regular-text"
                                autocomplete="new-password" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_scope">Scope</label></th>
                        <td><input type="text" id="wcsso_scope" name="wcsso_settings[scope]"
                                value="<?php echo esc_attr($settings['scope']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_redirect_path">Redirect path</label></th>
                        <td>
                            <input type="text" id="wcsso_redirect_path" name="wcsso_settings[redirect_path]"
                                value="<?php echo esc_attr($settings['redirect_path']); ?>" class="regular-text" />
                            <p class="description">Callback URL: <?php echo esc_html($callback_url); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Login behaviour', 'wcsso'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Enable SSO</th>
                        <td><label><input type="checkbox" name="wcsso_settings[login_enabled]" value="1" <?php checked($settings['login_enabled']); ?> /> Enable Hosted UI login</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Auto redirect</th>
                        <td><label><input type="checkbox" name="wcsso_settings[auto_redirect_login]" value="1" <?php checked($settings['auto_redirect_login']); ?> /> Automatically redirect unauthenticated
                                visits</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_excluded_paths">Excluded paths</label></th>
                        <td>
                            <textarea id="wcsso_excluded_paths" name="wcsso_settings[excluded_paths]" rows="5"
                                class="large-text code"><?php echo esc_textarea($settings['excluded_paths']); ?></textarea>
                            <p class="description">One path per line, include leading slash.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_new_user_redirect_url">New user redirect URL</label></th>
                        <td>
                            <input type="text" id="wcsso_new_user_redirect_url" name="wcsso_settings[new_user_redirect_url]"
                                value="<?php echo esc_attr($settings['new_user_redirect_url']); ?>" class="regular-text" placeholder="/welcome" />
                            <p class="description">Optional. For first-time Cognito-provisioned users only. Supports a relative path like <code>/welcome</code> or a full URL.</p>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Logout', 'wcsso'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Enable logout</th>
                        <td><label><input type="checkbox" name="wcsso_settings[logout_enabled]" value="1" <?php checked($settings['logout_enabled']); ?> /> Redirect to Cognito on logout</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_logout_redirect_path">Logout redirect path</label></th>
                        <td><input type="text" id="wcsso_logout_redirect_path" name="wcsso_settings[logout_redirect_path]"
                                value="<?php echo esc_attr($settings['logout_redirect_path']); ?>" class="regular-text" /></td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('User provisioning', 'wcsso'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Enable provisioning</th>
                        <td><label><input type="checkbox" name="wcsso_settings[provisioning_enabled]" value="1" <?php checked($settings['provisioning_enabled']); ?> /> Create or update WP users on login</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_email_claim_key">Email claim key</label></th>
                        <td><input type="text" id="wcsso_email_claim_key" name="wcsso_settings[email_claim_key]"
                                value="<?php echo esc_attr($settings['email_claim_key']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_username_claim_key">Username claim key</label></th>
                        <td><input type="text" id="wcsso_username_claim_key" name="wcsso_settings[username_claim_key]"
                                value="<?php echo esc_attr($settings['username_claim_key']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_display_name_claim_key">Display name claim key</label></th>
                        <td><input type="text" id="wcsso_display_name_claim_key" name="wcsso_settings[display_name_claim_key]"
                                value="<?php echo esc_attr($settings['display_name_claim_key']); ?>" class="regular-text" />
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Role mapping', 'wcsso'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wcsso_role_claim_key">Role claim key</label></th>
                        <td><input type="text" id="wcsso_role_claim_key" name="wcsso_settings[role_claim_key]"
                                value="<?php echo esc_attr($settings['role_claim_key']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_default_role">Default WP role</label></th>
                        <td>
                            <select id="wcsso_default_role" name="wcsso_settings[default_wp_role]">
                                <?php foreach ($role_options as $role_key => $role_data): ?>
                                    <option value="<?php echo esc_attr($role_key); ?>" <?php selected($settings['default_wp_role'], $role_key); ?>><?php echo esc_html($role_data['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Role assignment</th>
                        <td>
                            <label><input type="checkbox" name="wcsso_settings[only_set_role_if_current_role_is_default]"
                                    value="1" <?php checked($settings['only_set_role_if_current_role_is_default']); ?> /> Only
                                set role if user has default role</label>
                        </td>
                    </tr>
                </table>

                <table class="widefat striped" id="wcsso-role-mapping-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Claim value', 'wcsso'); ?></th>
                            <th><?php echo esc_html__('WordPress role', 'wcsso'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($settings['role_mapping'])): ?>
                            <?php foreach ($settings['role_mapping'] as $claim_value => $role_slug): ?>
                                <tr>
                                    <td><input type="text" name="wcsso_settings[role_mapping_keys][]"
                                            value="<?php echo esc_attr($claim_value); ?>" class="regular-text" /></td>
                                    <td>
                                        <select name="wcsso_settings[role_mapping_values][]">
                                            <option value="">Select role</option>
                                            <?php foreach ($role_options as $role_key => $role_data): ?>
                                                <option value="<?php echo esc_attr($role_key); ?>" <?php selected($role_slug, $role_key); ?>><?php echo esc_html($role_data['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><button type="button" class="button wcsso-remove-row">Remove</button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td><input type="text" name="wcsso_settings[role_mapping_keys][]" value="" class="regular-text" />
                                </td>
                                <td>
                                    <select name="wcsso_settings[role_mapping_values][]">
                                        <option value="">Select role</option>
                                        <?php foreach ($role_options as $role_key => $role_data): ?>
                                            <option value="<?php echo esc_attr($role_key); ?>">
                                                <?php echo esc_html($role_data['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><button type="button" class="button wcsso-remove-row">Remove</button></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button"
                        id="wcsso-add-role-mapping"><?php echo esc_html__('Add mapping', 'wcsso'); ?></button></p>

                <h2><?php echo esc_html__('WP to Cognito sync', 'wcsso'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Enable sync</th>
                        <td><label><input type="checkbox" name="wcsso_settings[sync_enabled]" value="1" <?php checked($settings['sync_enabled']); ?> /> Sync WordPress users to Cognito</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_aws_region">AWS region</label></th>
                        <td><input type="text" id="wcsso_aws_region" name="wcsso_settings[aws_region]"
                                value="<?php echo esc_attr($settings['aws_region']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_aws_access_key">AWS access key</label></th>
                        <td><input type="text" id="wcsso_aws_access_key" name="wcsso_settings[aws_access_key]"
                                value="<?php echo esc_attr($settings['aws_access_key']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_aws_secret_key">AWS secret key</label></th>
                        <td><input type="password" id="wcsso_aws_secret_key" name="wcsso_settings[aws_secret_key]"
                                value="<?php echo esc_attr($settings['aws_secret_key']); ?>" class="regular-text"
                                autocomplete="new-password" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_aws_user_pool_id">User pool ID</label></th>
                        <td><input type="text" id="wcsso_aws_user_pool_id" name="wcsso_settings[aws_user_pool_id]"
                                value="<?php echo esc_attr($settings['aws_user_pool_id']); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Role sync</th>
                        <td><label><input type="checkbox" name="wcsso_settings[sync_role_enabled]" value="1" <?php checked($settings['sync_role_enabled']); ?> /> Sync WordPress role attribute to Cognito</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wcsso_sync_role_attribute_name">Role attribute name</label></th>
                        <td><input type="text" id="wcsso_sync_role_attribute_name"
                                name="wcsso_settings[sync_role_attribute_name]"
                                value="<?php echo esc_attr($settings['sync_role_attribute_name']); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sync triggers</th>
                        <td>
                            <label><input type="checkbox" name="wcsso_settings[sync_on_user_register]" value="1" <?php checked($settings['sync_on_user_register']); ?> /> On user register</label><br />
                            <label><input type="checkbox" name="wcsso_settings[sync_on_profile_update]" value="1" <?php checked($settings['sync_on_profile_update']); ?> /> On profile update</label>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Cognito user migration', 'wcsso'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wcsso_migration_shared_secret">Shared secret</label></th>
                        <td>
                            <input type="password" id="wcsso_migration_shared_secret" name="wcsso_settings[migration_shared_secret]"
                                value="<?php echo esc_attr($settings['migration_shared_secret']); ?>" class="regular-text"
                                autocomplete="new-password" />
                            <p class="description">Used by the migration verification endpoint at <?php echo esc_html(rest_url('cognito-migrate/v1/verify')); ?>.</p>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Password reset shortcode', 'wcsso'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Shortcode', 'wcsso'); ?></th>
                        <td>
                            <code>[wcsso_cognito_password_reset]</code>
                            <p class="description">Adds a logged-in user password reset form that updates the user password in Cognito using the AWS region, access key, secret key, and user pool ID configured above. If the Cognito user does not exist, it creates one from the current WordPress username and email, then sets the submitted password.</p>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('Diagnostics', 'wcsso'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">JWT verification</th>
                        <td><label><input type="checkbox" name="wcsso_settings[jwt_verify]" value="1" <?php checked($settings['jwt_verify']); ?> /> Verify JWT signature and claims</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Debug logging</th>
                        <td><label><input type="checkbox" name="wcsso_settings[debug]" value="1" <?php checked($settings['debug']); ?> /> Log errors to PHP error log</label></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
