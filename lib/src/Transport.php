<?php

namespace TLDers\Sdk;

// Only loaded through autoload.php (WordPress, the TLDers script, or the tests).
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Makes HTTP GET requests. CurlTransport is the default; the WordPress plugin
 * swaps in one built on wp_remote_get().
 */
interface Transport
{
    /**
     * @param string               $url
     * @param array<string,string> $headers
     * @return array{0:int,1:string,2:array<string,string>} [status (0 on network failure), body or error message, lower-cased response headers]
     */
    public function get($url, array $headers);
}
