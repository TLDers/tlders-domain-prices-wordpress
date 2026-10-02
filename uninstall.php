<?php
// Removes the plugin's settings and cached prices when it is deleted.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('tlders_dp_settings');
wp_clear_scheduled_hook('tlders_dp_refresh');

global $wpdb;
// Transients have no "delete by prefix" API; this only touches the plugin's own rows.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('_transient_tlders_dp_') . '%',
    $wpdb->esc_like('_transient_timeout_tlders_dp_') . '%'
));
