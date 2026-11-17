<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_site_transient('wcsso_github_release');
delete_site_transient('wcsso_update_error');

if (is_multisite()) {
    $sites = get_sites(['fields' => 'ids']);
    foreach ($sites as $site_id) {
        delete_blog_option($site_id, 'wcsso_settings');
        delete_blog_option($site_id, 'wcsso_flush_rewrites');
    }
} else {
    delete_option('wcsso_settings');
    delete_option('wcsso_flush_rewrites');
}
