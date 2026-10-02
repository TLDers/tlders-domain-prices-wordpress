<?php

namespace TLDers\Sdk;

// Only loaded through autoload.php (WordPress, the TLDers script, or the tests).
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Key/value cache. Entries keep their value after they expire so the client
 * can fall back to stale prices when TLDers is unreachable or the API key is
 * out of quota, instead of showing nothing.
 */
interface Cache
{
    /**
     * @param string $key
     * @param bool   $allowStale return the value even if it has expired
     * @return mixed|null null when missing (or expired and $allowStale is false)
     */
    public function get($key, $allowStale = false);

    /**
     * @param string $key
     * @param mixed  $value JSON-serialisable
     * @param int    $ttl   seconds
     */
    public function set($key, $value, $ttl);

    /** Removes every entry this cache owns. */
    public function clear();
}

/** In-memory cache, for tests and one-off scripts. */
class MemoryCache implements Cache
{
    /** @var array<string,array{expires:int,value:mixed}> */
    private $entries = [];

    public function get($key, $allowStale = false)
    {
        if (!isset($this->entries[$key])) {
            return null;
        }
        $entry = $this->entries[$key];
        return ($allowStale || $entry['expires'] >= time()) ? $entry['value'] : null;
    }

    public function set($key, $value, $ttl)
    {
        $this->entries[$key] = ['expires' => time() + (int) $ttl, 'value' => $value];
    }

    public function clear()
    {
        $this->entries = [];
    }

    /** Test helper: make every entry expired. */
    public function expireAll()
    {
        foreach ($this->entries as $key => $entry) {
            $this->entries[$key]['expires'] = time() - 1;
        }
    }
}
