<?php
/**
 * Plugin Name: WP Cognito SSO
 * Description: Cognito Hosted UI SSO for WordPress with optional user sync.
 * Version: 1.0.7
 * Author: RSL Group
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Tested up to: 6.9
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wcsso
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('WCSSO_PLUGIN_FILE', __FILE__);
define('WCSSO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCSSO_PLUGIN_URL', plugin_dir_url(__FILE__));

if (file_exists(WCSSO_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once WCSSO_PLUGIN_DIR . 'vendor/autoload.php';
}

require_once WCSSO_PLUGIN_DIR . 'includes/helpers.php';
require_once WCSSO_PLUGIN_DIR . 'includes/class-settings.php';
require_once WCSSO_PLUGIN_DIR . 'includes/class-jwt.php';
require_once WCSSO_PLUGIN_DIR . 'includes/class-user-provisioner.php';
require_once WCSSO_PLUGIN_DIR . 'includes/class-sso.php';
require_once WCSSO_PLUGIN_DIR . 'includes/class-cognito-sync.php';
require_once WCSSO_PLUGIN_DIR . 'includes/class-cognito-migration-endpoint.php';
require_once WCSSO_PLUGIN_DIR . 'includes/class-password-reset-shortcode.php';

function wcsso_activate_plugin() {
    WCSSO_SSO::add_rewrite_rules();
    flush_rewrite_rules();
}

function wcsso_deactivate_plugin() {
    flush_rewrite_rules();
}

register_activation_hook(__FILE__, 'wcsso_activate_plugin');
register_deactivation_hook(__FILE__, 'wcsso_deactivate_plugin');

add_action('plugins_loaded', function () {
    WCSSO_Settings::init();
    WCSSO_SSO::init();
    WCSSO_Cognito_Sync::init();
    WCSSO_Cognito_Migration_Endpoint::init();
    WCSSO_Password_Reset_Shortcode::init();
});

add_action('admin_init', function () {
    if (get_option('wcsso_flush_rewrites')) {
        delete_option('wcsso_flush_rewrites');
        flush_rewrite_rules();
    }
});
