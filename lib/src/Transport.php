<?php

namespace TLDers\Sdk;

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

class CurlTransport implements Transport
{
    /** @var int */
    private $timeout;

    public function __construct($timeout = 20)
    {
        $this->timeout = (int) $timeout;
    }

    public function get($url, array $headers)
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch); // a no-op since PHP 8, deprecated in 8.5
        }

        if ($body === false) {
            return [0, $error, []];
        }
        return [$status, (string) $body, $responseHeaders];
    }
}
