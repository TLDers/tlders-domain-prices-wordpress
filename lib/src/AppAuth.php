<?php

namespace TLDers\Sdk;

// Only loaded through autoload.php (WordPress, the TLDers script, or the tests).
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Protects the mobile app API. The site owner generates an app key; the app signs every
 * request with it (HMAC-SHA256) and the server checks the signature. The key itself is never
 * sent, and a captured request stops working after five minutes.
 *
 * Request headers the app sends:
 *   X-App-Timestamp   unix time in seconds
 *   X-App-Signature   hex HMAC-SHA256 of the message below, keyed with the app key
 *
 * Message (joined with "\n"): timestamp, route path without slashes at the ends ("tld/com"),
 * and the canonical query string: the request's query parameters sorted by name, each
 * rawurlencoded as name=value and joined with "&" ("route" and "rest_route" are not part of it).
 *
 * An app key inside an app can be extracted by someone who takes the app apart. Rotating the key
 * (and shipping an updated app) is the answer to that; this keeps the endpoint closed to everyone else.
 */
class AppAuth
{
    const MAX_SKEW = 300;
    const KEY_PREFIX = 'tlk_';

    /** A new random app key, e.g. tlk_ followed by 48 hex characters. */
    public static function generateKey()
    {
        return self::KEY_PREFIX . bin2hex(random_bytes(24));
    }

    public static function looksLikeKey($key)
    {
        return is_string($key) && preg_match('/^' . self::KEY_PREFIX . '[0-9a-f]{48}$/', $key) === 1;
    }

    public static function canonicalQuery(array $query)
    {
        unset($query['route'], $query['rest_route']);
        ksort($query);
        $parts = [];
        foreach ($query as $name => $value) {
            if (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            }
            $parts[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }
        return implode('&', $parts);
    }

    public static function message($timestamp, $path, array $query)
    {
        return (int) $timestamp . "\n" . trim((string) $path, '/') . "\n" . self::canonicalQuery($query);
    }

    public static function sign($key, $timestamp, $path, array $query)
    {
        return hash_hmac('sha256', self::message($timestamp, $path, $query), (string) $key);
    }

    /**
     * Checks a request. An empty $key means the owner has not turned protection on: everything passes.
     *
     * @param string      $key       the site's app key ('' = off)
     * @param string|null $timestamp X-App-Timestamp header
     * @param string|null $signature X-App-Signature header
     * @param int|null    $now       for tests
     * @return array|null null when allowed, else [HTTP status, body]
     */
    public static function check($key, $path, array $query, $timestamp, $signature, $now = null)
    {
        if ((string) $key === '') {
            return null;
        }
        $now = $now === null ? time() : (int) $now;
        if ($timestamp === null || $signature === null || $timestamp === '' || $signature === '') {
            return [401, ['error' => 'This site needs an app key. Add it to the app and rebuild it.', 'code' => 'app_key_required']];
        }
        if (!ctype_digit((string) $timestamp)) {
            return [401, ['error' => 'Invalid request.', 'code' => 'app_key_invalid']];
        }
        if (abs($now - (int) $timestamp) > self::MAX_SKEW) {
            return [401, ['error' => "Your phone's clock looks wrong. Set it to automatic and try again.", 'code' => 'clock_skew']];
        }
        $expected = self::sign($key, $timestamp, $path, $query);
        if (!hash_equals($expected, strtolower((string) $signature))) {
            return [401, ['error' => 'The app key does not match this site. Update the app.', 'code' => 'app_key_invalid']];
        }
        return null;
    }

    /**
     * Counts a request from one client (usually the IP) and says whether it is over the limit.
     * Not atomic: good enough to stop a script hammering the endpoint, not an exact meter.
     *
     * @return bool true when the request is allowed
     */
    public static function withinRateLimit(Cache $cache, $client, $limit = 120, $windowSeconds = 60, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $key = 'rl_' . sha1((string) $client) . '_' . intdiv($now, $windowSeconds);
        $count = (int) $cache->get($key);
        if ($count >= $limit) {
            return false;
        }
        $cache->set($key, $count + 1, $windowSeconds * 2);
        return true;
    }
}
