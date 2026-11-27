<?php

namespace TLDers\Sdk;

// Only loaded through autoload.php (WordPress, the TLDers script, or the tests).
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Makes HTTP requests. CurlTransport is the default; the WordPress plugin
 * swaps in one built on wp_remote_get() and wp_remote_post().
 */
interface Transport
{
    /**
     * @param string               $url
     * @param array<string,string> $headers
     * @return array{0:int,1:string,2:array<string,string>} [status (0 on network failure), body or error message, lower-cased response headers]
     */
    public function get($url, array $headers);

    /**
     * @param string               $url
     * @param array<string,string> $headers
     * @param string               $body raw request body (JSON, or a urlencoded form)
     * @return array{0:int,1:string,2:array<string,string>} same shape as get()
     */
    public function post($url, array $headers, $body);
}
