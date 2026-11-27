<?php

namespace TLDers\Sdk;

// Only loaded through autoload.php (WordPress, the TLDers script, or the tests).
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sends push notifications to a Firebase Cloud Messaging topic (HTTP v1 API).
 *
 * The site owner pastes their own Firebase service account JSON; nothing is shared with TLDers. The apps subscribe to
 * topics, so the server never stores device tokens: "offers" for everyone, and "tld_<extension>" for people watching
 * one extension.
 *
 * Needs the PHP openssl extension (to sign the OAuth token request).
 */
class Fcm
{
    const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';
    const TOPIC_OFFERS = 'offers';
    const TOPIC_TLD_PREFIX = 'tld_';

    /** @var array */
    private $account;
    /** @var Transport */
    private $http;
    /** @var Cache */
    private $cache;

    /**
     * @param array $account a parsed service account (see parseServiceAccount)
     */
    public function __construct(array $account, Transport $http, Cache $cache)
    {
        $this->account = $account;
        $this->http = $http;
        $this->cache = $cache;
    }

    /**
     * Checks the JSON of a Firebase service account key (Firebase console, Project settings, Service accounts).
     *
     * @param string $json
     * @return array{0:?array,1:string} [account or null, error message]
     */
    public static function parseServiceAccount($json)
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            return [null, 'That is not valid JSON. Paste the whole service account file.'];
        }
        foreach (['project_id', 'client_email', 'private_key'] as $field) {
            if (empty($data[$field]) || !is_string($data[$field])) {
                return [null, "The file has no \"$field\". Download a new private key from Firebase, Project settings, Service accounts."];
            }
        }
        if (strpos($data['private_key'], 'BEGIN PRIVATE KEY') === false) {
            return [null, 'The private key does not look right. Use the JSON file Firebase gives you, unchanged.'];
        }
        if (!preg_match('/^[a-z0-9-]{4,60}$/', $data['project_id'])) {
            return [null, 'The project id looks wrong.'];
        }
        $uri = isset($data['token_uri']) && is_string($data['token_uri']) ? $data['token_uri'] : self::DEFAULT_TOKEN_URI;
        // Only Google's own token endpoint: a pasted file must not be able to point the server elsewhere.
        if ($uri !== self::DEFAULT_TOKEN_URI) {
            return [null, 'Unexpected token address in the file.'];
        }
        return [[
            'project_id' => $data['project_id'],
            'client_email' => $data['client_email'],
            'private_key' => $data['private_key'],
            'token_uri' => $uri,
        ], ''];
    }

    /** FCM topic names may only contain [a-zA-Z0-9-_.~%]. */
    public static function topic($name)
    {
        return preg_replace('/[^a-zA-Z0-9\-_.~%]/', '', (string) $name);
    }

    public static function tldTopic($tld)
    {
        return self::topic(self::TOPIC_TLD_PREFIX . $tld);
    }

    /**
     * @param array<string,scalar> $data extra values the app receives (all become strings)
     * @return array{0:bool,1:string} [sent, message]
     */
    public function sendToTopic($topic, $title, $body, array $data = [])
    {
        $topic = self::topic($topic);
        if ($topic === '') {
            return [false, 'No topic.'];
        }
        $token = $this->accessToken($error);
        if ($token === null) {
            return [false, $error];
        }
        $strings = [];
        foreach ($data as $k => $v) {
            $strings[(string) $k] = (string) $v;
        }
        $message = [
            'topic' => $topic,
            'notification' => ['title' => (string) $title, 'body' => (string) $body],
            'android' => ['priority' => 'HIGH', 'notification' => ['channel_id' => 'tlders_alerts']],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
        ];
        if ($strings) {
            $message['data'] = $strings;
        }
        $url = self::fcmBase() . '/v1/projects/' . rawurlencode($this->account['project_id']) . '/messages:send';
        list($status, $response) = $this->http->post($url, [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json; charset=utf-8',
        ], json_encode(['message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($status === 200) {
            return [true, 'Sent.'];
        }
        if ($status === 401) {
            $this->cache->set('fcm:token', null, 1); // a rejected token is dropped, the next call signs in again
        }
        return [false, self::errorMessage($status, $response)];
    }

    private function accessToken(&$error = null)
    {
        $cached = $this->cache->get('fcm:token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        if (!function_exists('openssl_sign')) {
            $error = 'The PHP openssl extension is needed to send push notifications.';
            return null;
        }
        $now = time();
        $b64 = function ($raw) {
            return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        };
        $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
            'iss' => $this->account['client_email'],
            'scope' => self::SCOPE,
            'aud' => $this->account['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $signature = '';
        $key = openssl_pkey_get_private($this->account['private_key']);
        if ($key === false || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            $error = 'Could not sign in to Firebase: the private key in the service account file was not accepted.';
            return null;
        }
        list($status, $response) = $this->http->post(self::tokenUrl($this->account['token_uri']), ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned . '.' . $b64($signature),
        ]));
        $json = json_decode($response, true);
        if ($status !== 200 || !is_array($json) || empty($json['access_token'])) {
            $error = 'Google refused the sign-in: ' . self::errorMessage($status, $response);
            return null;
        }
        $ttl = isset($json['expires_in']) ? max(60, (int) $json['expires_in'] - 300) : 3000;
        $this->cache->set('fcm:token', (string) $json['access_token'], $ttl);
        return (string) $json['access_token'];
    }

    /**
     * Google's addresses. TLDERS_FCM_BASE exists only so the tests can aim these at a local fake server;
     * it is an environment variable the server operator sets, never something a visitor or the admin form can reach.
     */
    private static function fcmBase()
    {
        $test = getenv('TLDERS_FCM_BASE');
        return $test ? rtrim($test, '/') : 'https://fcm.googleapis.com';
    }

    private static function tokenUrl($fromAccount)
    {
        $test = getenv('TLDERS_FCM_BASE');
        return $test ? rtrim($test, '/') . '/token' : $fromAccount;
    }

    private static function errorMessage($status, $body)
    {
        if ($status === 0) {
            return 'Could not reach Google: ' . $body;
        }
        $json = json_decode((string) $body, true);
        if (is_array($json)) {
            if (isset($json['error']['message'])) {
                return (string) $json['error']['message'];
            }
            if (isset($json['error_description'])) {
                return (string) $json['error_description'];
            }
            if (isset($json['error']) && is_string($json['error'])) {
                return $json['error'];
            }
        }
        return "HTTP $status";
    }
}
