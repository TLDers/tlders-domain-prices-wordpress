<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Sends the SDK's requests through the WordPress HTTP API. */
class TLDers_DP_Transport implements TLDers\Sdk\Transport
{
    public function get($url, array $headers)
    {
        $response = wp_remote_get($url, ['headers' => $headers, 'timeout' => 30]);
        if (is_wp_error($response)) {
            return [0, $response->get_error_message(), []];
        }
        $out = [];
        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $out[strtolower($name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        return [(int) wp_remote_retrieve_response_code($response), (string) wp_remote_retrieve_body($response), $out];
    }
}

/**
 * Transient-backed price cache (a paid key stores one entry per TLD; with a
 * persistent object cache these never touch the database). Each value is stored with its own expiry inside a longer-lived transient so
 * stale prices remain available as a fallback.
 */
class TLDers_DP_TransientCache implements TLDers\Sdk\Cache
{
    const PREFIX = 'tlders_dp_';
    const KEEP = 14 * DAY_IN_SECONDS;

    public function get($key, $allowStale = false)
    {
        $entry = get_transient(self::PREFIX . md5($key));
        if (!is_array($entry) || !array_key_exists('value', $entry)) {
            return null;
        }
        return ($allowStale || $entry['expires'] >= time()) ? $entry['value'] : null;
    }

    public function set($key, $value, $ttl)
    {
        set_transient(self::PREFIX . md5($key), ['expires' => time() + (int) $ttl, 'value' => $value], max((int) $ttl, self::KEEP));
    }

    public function clear()
    {
        global $wpdb;
        // Transients have no "delete by prefix" API; this only touches our own rows.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_' . self::PREFIX) . '%',
            $wpdb->esc_like('_transient_timeout_' . self::PREFIX) . '%'
        ));
    }
}
