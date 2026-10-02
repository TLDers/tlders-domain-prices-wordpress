<?php

namespace TLDers\Sdk;

// Only loaded through autoload.php (WordPress, the TLDers script, or the tests).
if (!defined('ABSPATH')) {
    exit;
}

class ApiException extends \RuntimeException
{
}

/**
 * Reads TLDers prices and keeps them cached.
 *
 * Paid keys: one bulk /prices call refreshes every TLD and registrar at once
 * (twice a day by default). Free keys (100 requests/month): one /tlds/{tld}
 * call per TLD, cached for a day, limited to the TLDs in `freeTlds` so random
 * visitor searches can't burn through the quota.
 *
 * All prices are USD. An offer is
 *   ['slug' => 'porkbun', 'name' => 'Porkbun', 'register' => 11.08, 'renew' => 11.08, 'transfer' => 11.08]
 * with null for a price the registrar doesn't publish. Offers come sorted by
 * register price, unknown last.
 */
class Client
{
    const VERSION = '1.0.0';
    // ~3 TLDs × 1 request/day fits a free key's 100 requests/month.
    const DEFAULT_FREE_TLDS = ['com', 'net', 'org'];

    /** @var string */
    private $apiKey;
    /** @var Transport */
    private $http;
    /** @var Cache */
    private $cache;
    /** @var string */
    private $base;
    /** @var string */
    private $userAgent;
    /** @var int */
    private $ttl;
    /** @var string[] */
    private $freeTlds;
    /** @var string|null */
    private $lastError;

    /**
     * @param string $apiKey TLDers API key (tlders.com/developers)
     * @param array  $options base, userAgent, ttl (seconds), freeTlds (string[])
     */
    public function __construct($apiKey, ?Transport $http = null, ?Cache $cache = null, array $options = [])
    {
        $this->apiKey = trim((string) $apiKey);
        $this->http = $http ?: new CurlTransport();
        $this->cache = $cache ?: new MemoryCache();
        $this->base = rtrim(isset($options['base']) ? $options['base'] : 'https://www.tlders.com/api/v1', '/');
        $this->userAgent = isset($options['userAgent']) ? $options['userAgent'] : 'TLDers-PHP/' . self::VERSION;
        $this->ttl = isset($options['ttl']) ? max(3600, (int) $options['ttl']) : 12 * 3600;
        $this->freeTlds = isset($options['freeTlds']) ? self::normalizeList($options['freeTlds']) : self::DEFAULT_FREE_TLDS;

        // A different key may be on a different plan: start from a clean cache.
        $fingerprint = substr(sha1('tlders:' . $this->apiKey), 0, 16);
        if ($this->cache->get('key', true) !== $fingerprint) {
            $this->cache->clear();
            $this->cache->set('key', $fingerprint, 10 * 365 * 86400);
        }
    }

    /** The last API error, for admin screens. Visitors just see no prices. */
    public function lastError()
    {
        return $this->lastError;
    }

    public function clearCache()
    {
        $this->cache->clear();
    }

    /** 'paid', 'free', or 'unknown' before the first successful call. */
    public function plan()
    {
        $plan = $this->cache->get('plan', true);
        return is_string($plan) ? $plan : 'unknown';
    }

    /**
     * Whether a lookup of $tld can be answered: always on a paid key, and on a
     * free key only for the configured TLDs (or ones already cached).
     */
    public function canLookup($tld)
    {
        $tld = self::normalizeTld($tld);
        if ($tld === '') {
            return false;
        }
        if ($this->detectPlan() !== 'free') {
            return true;
        }
        return in_array($tld, $this->freeTlds, true) || $this->cache->get('offers:' . $tld, true) !== null;
    }

    /** @return string[] the TLDs a free key may look up */
    public function freeTlds()
    {
        return $this->freeTlds;
    }

    /**
     * Every TLD with prices: all of them on a paid key, the free list on a
     * free key.
     *
     * @return string[]
     */
    public function tlds()
    {
        if ($this->detectPlan() === 'paid') {
            $this->refreshBook();
            $index = $this->cache->get('book:index', true);
            if (is_array($index)) {
                return $index;
            }
        }
        return $this->freeTlds;
    }

    /**
     * Offers for one TLD, cheapest first. Empty when the TLD is unknown or
     * prices can't be fetched (see lastError()).
     *
     * @return array<int,array{slug:string,name:string,register:?float,renew:?float,transfer:?float}>
     */
    public function offers($tld)
    {
        $tld = self::normalizeTld($tld);
        if ($tld === '' || !$this->canLookup($tld)) {
            return [];
        }

        if ($this->detectPlan() === 'paid') {
            $this->refreshBook();
            $offers = $this->cache->get('offers:' . $tld, true);
            return is_array($offers) ? $offers : [];
        }

        $offers = $this->cache->get('offers:' . $tld);
        if (is_array($offers)) {
            return $offers;
        }
        $fetched = $this->fetchTld($tld);
        if ($fetched !== null) {
            return $fetched;
        }
        $stale = $this->cache->get('offers:' . $tld, true);
        return is_array($stale) ? $stale : [];
    }

    /** The cheapest offer to register $tld, or null. */
    public function cheapest($tld, $type = 'register')
    {
        $best = null;
        foreach ($this->offers($tld) as $offer) {
            if ($offer[$type] !== null && ($best === null || $offer[$type] < $best[$type])) {
                $best = $offer;
            }
        }
        return $best;
    }

    /**
     * Registrars TLDers covers, keyed by slug. searchUrl is the registrar's
     * plain search page with a {domain} placeholder — the starting point for
     * a site owner's affiliate link.
     *
     * affiliateNetwork names the network the registrar's program runs on
     * (CJ, Impact …) when TLDers knows it.
     *
     * @return array<string,array{name:string,searchUrl:string,affiliateNetwork:string}>
     */
    public function registrars()
    {
        $cached = $this->cache->get('registrars');
        if (is_array($cached)) {
            return $cached;
        }
        $json = $this->request('/registrars', false);
        if ($json === null || !isset($json['data']) || !is_array($json['data'])) {
            $stale = $this->cache->get('registrars', true);
            return is_array($stale) ? $stale : [];
        }
        $registrars = [];
        foreach ($json['data'] as $r) {
            $registrars[$r['slug']] = [
                'name' => (string) $r['name'],
                'searchUrl' => isset($r['searchUrl']) ? (string) $r['searchUrl'] : '',
                'affiliateNetwork' => isset($r['affiliateNetwork']) ? (string) $r['affiliateNetwork'] : '',
            ];
        }
        uasort($registrars, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        $this->cache->set('registrars', $registrars, 24 * 3600);
        return $registrars;
    }

    /**
     * Splits what a visitor typed ("Example.co.uk", "https://www.foo.io/",
     * "foo", ".dev") into [domain, tld]. domain is '' when only a TLD was
     * given; a bare name gets .com.
     *
     * @return array{0:string,1:string}
     */
    public function parseQuery($query)
    {
        $q = strtolower(trim((string) $query));
        $q = preg_replace('#^[a-z]+://#', '', $q);
        $q = preg_replace('#[/?\#].*$#', '', $q);
        $q = preg_replace('/^www\./', '', $q);
        $q = trim($q, ". \t");
        if ($q === '' || !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $q)) {
            return ['', ''];
        }
        $labels = explode('.', $q);
        if (count($labels) === 1) {
            // "dev" is a TLD search when we know it, otherwise a name to try as .com.
            if ($this->isKnownTld($q)) {
                return ['', $q];
            }
            return [$q . '.com', 'com'];
        }
        // Prefer a two-label TLD (co.uk) when TLDers tracks it.
        if (count($labels) >= 3) {
            $two = implode('.', array_slice($labels, -2));
            if ($this->isKnownTld($two)) {
                return [$q, $two];
            }
        }
        return [$q, end($labels)];
    }

    private function isKnownTld($tld)
    {
        if ($this->detectPlan() === 'paid') {
            return in_array($tld, $this->tlds(), true);
        }
        return in_array($tld, $this->freeTlds, true);
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** Works out (once, then cached) whether the key is on a paid plan. */
    private function detectPlan()
    {
        $plan = $this->cache->get('plan');
        if (is_string($plan)) {
            return $plan;
        }
        if ($this->apiKey === '') {
            return 'free';
        }
        // Fetching the bulk book doubles as the check: 402 means free.
        $this->refreshBook(true);
        $plan = $this->cache->get('plan', true);
        return is_string($plan) ? $plan : 'free';
    }

    private function refreshBook($detecting = false)
    {
        if ($this->cache->get('book:fresh') !== null || $this->cache->get('book:retry') !== null) {
            return;
        }
        $json = $this->request('/prices?format=compact', true, $status);
        if ($status === 402) {
            $this->cache->set('plan', 'free', $this->ttl);
            return;
        }
        if ($json === null || !isset($json['data']) || !is_array($json['data'])) {
            // Don't retry on every page view while TLDers is down; keep stale prices.
            $this->cache->set('book:retry', 1, 900);
            if ($detecting && $this->cache->get('plan', true) === null) {
                $this->cache->set('plan', 'free', 900);
            }
            return;
        }

        $names = [];
        foreach ($this->registrars() as $slug => $r) {
            $names[$slug] = $r['name'];
        }
        $byTld = [];
        foreach ($json['data'] as $slug => $prices) {
            foreach ($prices as $tld => $p) {
                $byTld[$tld][] = [
                    'slug' => (string) $slug,
                    'name' => isset($names[$slug]) ? $names[$slug] : ucfirst((string) $slug),
                    'register' => self::price($p[0]),
                    'renew' => self::price($p[1]),
                    'transfer' => self::price($p[2]),
                ];
            }
        }
        // Stale entries live a week past their refresh so an outage or an
        // expired plan degrades to old prices rather than empty tables.
        $keep = $this->ttl + 7 * 24 * 3600;
        foreach ($byTld as $tld => $offers) {
            $this->cache->set('offers:' . $tld, self::sortOffers($offers), $keep);
        }
        $index = array_keys($byTld);
        sort($index);
        $this->cache->set('book:index', $index, $keep);
        $this->cache->set('book:fresh', time(), $this->ttl);
        $this->cache->set('plan', 'paid', $this->ttl);
    }

    private function fetchTld($tld)
    {
        if ($this->cache->get('quota:exhausted') !== null) {
            $this->lastError = 'TLDers API quota used up; showing cached prices until it resets.';
            return null;
        }
        $json = $this->request('/tlds/' . rawurlencode($tld), true, $status, $headers);
        if ($status === 404) {
            $this->cache->set('offers:' . $tld, [], 24 * 3600);
            return [];
        }
        if ($status === 429) {
            $retry = isset($headers['retry-after']) ? (int) $headers['retry-after'] : 3600;
            $this->cache->set('quota:exhausted', 1, max(60, $retry));
        }
        if ($json === null || !isset($json['data']['currentPrices'])) {
            return null;
        }
        $offers = [];
        $seen = [];
        foreach ($json['data']['currentPrices'] as $p) {
            $slug = $p['registrar']['slug'];
            if ((isset($p['currency']) && $p['currency'] !== 'USD') || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $offers[] = [
                'slug' => (string) $slug,
                'name' => (string) $p['registrar']['name'],
                'register' => self::price($p['priceRegister']),
                'renew' => self::price($p['priceRenew']),
                'transfer' => self::price($p['priceTransfer']),
            ];
        }
        $offers = self::sortOffers($offers);
        $this->cache->set('offers:' . $tld, $offers, max($this->ttl, 24 * 3600));
        return $offers;
    }

    /**
     * GET an endpoint; returns the decoded JSON or null (lastError set).
     *
     * @param int|null   $status
     * @param array|null $headers
     */
    private function request($path, $withKey, &$status = null, &$headers = null)
    {
        $send = ['Accept' => 'application/json', 'User-Agent' => $this->userAgent];
        if ($withKey) {
            if ($this->apiKey === '') {
                $status = 401;
                $this->lastError = 'No TLDers API key set. Get a free one at https://www.tlders.com/developers';
                return null;
            }
            $send['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        list($status, $body, $headers) = $this->http->get($this->base . $path, $send);
        if ($status === 0) {
            $this->lastError = 'Could not reach TLDers: ' . $body;
            return null;
        }
        $json = json_decode($body, true);
        if ($status !== 200) {
            $this->lastError = is_array($json) && isset($json['error']) ? (string) $json['error'] : "TLDers returned HTTP $status";
            return null;
        }
        if (!is_array($json)) {
            $this->lastError = 'TLDers returned a response that is not JSON.';
            return null;
        }
        $this->lastError = null;
        return $json;
    }

    private static function price($value)
    {
        return is_numeric($value) && $value > 0 ? round((float) $value, 2) : null;
    }

    private static function sortOffers(array $offers)
    {
        usort($offers, function ($a, $b) {
            if ($a['register'] === $b['register']) {
                return strcasecmp($a['name'], $b['name']);
            }
            if ($a['register'] === null) {
                return 1;
            }
            if ($b['register'] === null) {
                return -1;
            }
            return $a['register'] < $b['register'] ? -1 : 1;
        });
        return $offers;
    }

    public static function normalizeTld($tld)
    {
        $tld = strtolower(trim((string) $tld, ". \t\n"));
        return preg_match('/^[a-z0-9-]{1,63}(\.[a-z0-9-]{1,63})?$/', $tld) ? $tld : '';
    }

    /** "com, .IO ,net" or ['com','io'] → ['com','io','net'] */
    public static function normalizeList($list)
    {
        $items = is_array($list) ? $list : explode(',', (string) $list);
        $out = [];
        foreach ($items as $item) {
            $tld = self::normalizeTld($item);
            if ($tld !== '' && !in_array($tld, $out, true)) {
                $out[] = $tld;
            }
        }
        return $out;
    }
}
