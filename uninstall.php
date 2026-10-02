<?php
// Removes the plugin's settings and cached prices when it is deleted.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('tlders_dp_settings');
wp_clear_scheduled_hook('tlders_dp_refresh');

global $wpdb;
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('_transient_tlders_dp_') . '%',
    $wpdb->esc_like('_transient_timeout_tlders_dp_') . '%'
));

$uploads = wp_upload_dir(null, false);
$dir = trailingslashit($uploads['basedir']) . 'tlders-cache';
if (is_dir($dir)) {
    // GLOB_BRACE isn't available everywhere (musl), so add the dotfile by hand.
    foreach (array_merge((array) glob($dir . '/*'), [$dir . '/.htaccess']) as $file) {
        if (is_file($file)) {
            wp_delete_file($file);
        }
    }
    @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
