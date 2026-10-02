<?php

namespace TLDers\Sdk;

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

/** One JSON file per key in a private directory. */
class FileCache implements Cache
{
    /** @var string */
    private $dir;

    public function __construct($dir)
    {
        $this->dir = rtrim($dir, '/\\');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
        // Keep the cache out of reach on Apache; nginx users should keep the
        // directory outside the web root (the default for the PHP script).
        if (is_dir($this->dir) && !file_exists($this->dir . '/.htaccess')) {
            @file_put_contents($this->dir . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($this->dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
    }

    public function isWritable()
    {
        return is_dir($this->dir) && is_writable($this->dir);
    }

    private function path($key)
    {
        return $this->dir . '/' . preg_replace('/[^a-z0-9._-]/i', '_', $key) . '.json';
    }

    public function get($key, $allowStale = false)
    {
        $file = $this->path($key);
        if (!is_readable($file)) {
            return null;
        }
        $entry = json_decode((string) file_get_contents($file), true);
        if (!is_array($entry) || !array_key_exists('value', $entry)) {
            return null;
        }
        if (!$allowStale && $entry['expires'] < time()) {
            return null;
        }
        return $entry['value'];
    }

    public function set($key, $value, $ttl)
    {
        $file = $this->path($key);
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(['expires' => time() + (int) $ttl, 'value' => $value])) !== false) {
            @rename($tmp, $file);
        }
    }

    public function clear()
    {
        foreach ((array) glob($this->dir . '/*.json') as $file) {
            @unlink($file);
        }
    }
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
