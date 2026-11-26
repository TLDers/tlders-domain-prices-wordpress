<?php

namespace TLDers\Sdk;

// Only loaded through autoload.php (WordPress, the TLDers script, or the tests).
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The JSON contract the white-label mobile app reads. It's sold as an addon
 * for the PHP script (api.php/...), and the WordPress plugin serves the same
 * contract (/wp-json/tlders/v1/...). The site owner's API key and affiliate
 * links stay on their server; the app only receives prices and buy links.
 *
 *   GET {base}/config                  site name, currency, disclosure, popular TLDs, theme {skin, accent}
 *   GET {base}/search?q=example.io     offers for the TLD of what was typed
 *   GET {base}/tld/{tld}               offers for one TLD
 *   GET {base}/cheapest?tlds=com,io    cheapest offer per TLD
 *   GET {base}/tlds?q=&type=&category=&sort=popular|register|name&page=1&per=60
 *                                      every TLD with its cheapest price, filterable; also lists the
 *                                      available types and categories
 *
 * Prices in responses are already converted to the site's currency.
 */
class AppApi
{
    const CONTRACT = 1;
    const MAX_CHEAPEST = 30;

    /** @var Client */
    private $client;
    /** @var Links */
    private $links;
    /** @var Currency */
    private $currency;
    /** @var array */
    private $site;

    /**
     * @param array $site name, disclosure, popularTlds (string[]), skin, accent (#hex)
     */
    public function __construct(Client $client, Links $links, Currency $currency, array $site)
    {
        $this->client = $client;
        $this->links = $links;
        $this->currency = $currency;
        $this->site = $site;
    }

    /**
     * Routes a request path ("config", "tld/com", "search", "cheapest").
     *
     * @return array{0:int,1:array} [HTTP status, body]
     */
    public function handle($path, array $query)
    {
        $path = trim((string) $path, '/');
        if ($path === 'config' || $path === '') {
            return [200, $this->config()];
        }
        if ($path === 'search') {
            return $this->search(isset($query['q']) ? (string) $query['q'] : '');
        }
        if (strpos($path, 'tld/') === 0) {
            return $this->tld(substr($path, 4), '');
        }
        if ($path === 'cheapest') {
            return [200, $this->cheapest(isset($query['tlds']) ? $query['tlds'] : '')];
        }
        if ($path === 'tlds') {
            return [200, $this->tlds($query)];
        }
        return [404, ['error' => 'Unknown endpoint.']];
    }

    public function config()
    {
        return [
            'contract' => self::CONTRACT,
            'siteName' => (string) $this->site['name'],
            'disclosure' => (string) $this->site['disclosure'],
            'currency' => [
                'code' => $this->currency->code,
                'symbol' => $this->currency->symbol,
                'decimals' => $this->currency->decimals,
            ],
            'popularTlds' => array_values($this->site['popularTlds']),
            // The app takes its look from the site, so owners restyle it without an app update.
            'theme' => [
                'skin' => Insights::skin(isset($this->site['skin']) ? $this->site['skin'] : 'minimal'),
                'accent' => isset($this->site['accent']) ? (string) $this->site['accent'] : '',
            ],
            // Free TLDers keys can only look up some TLDs; the app uses this to
            // say so instead of showing an empty result.
            'searchableTlds' => $this->client->plan() === 'paid' ? null : $this->client->freeTlds(),
        ];
    }

    public function search($q)
    {
        list($domain, $tld) = $this->client->parseQuery($q);
        if ($tld === '') {
            return [400, ['error' => 'Enter a domain like example.com or a TLD like .io.']];
        }
        return $this->tld($tld, $domain);
    }

    public function tld($tld, $domain)
    {
        $tld = Client::normalizeTld($tld);
        if ($tld === '') {
            return [400, ['error' => 'Invalid TLD.']];
        }
        if (!$this->client->canLookup($tld)) {
            return [403, ['error' => "Prices for .$tld aren't available on this site."]];
        }
        $offers = $this->links->attach($this->client->offers($tld), $tld, $domain);
        if (!$offers) {
            return [404, ['error' => "No prices found for .$tld."]];
        }
        $summary = Insights::summary($offers);
        return [200, [
            'tld' => $tld,
            'domain' => $domain,
            'offers' => array_map([$this, 'present'], $offers),
            'average' => $this->currency->convert($summary['average']),
            'savingPct' => $summary['savingPct'],
        ]];
    }

    public function cheapest($tlds)
    {
        $items = [];
        foreach (array_slice(Client::normalizeList($tlds), 0, self::MAX_CHEAPEST) as $tld) {
            if (!$this->client->canLookup($tld)) {
                continue;
            }
            $offers = $this->links->attach($this->client->offers($tld), $tld);
            $best = null;
            foreach ($offers as $offer) {
                if ($offer['register'] !== null && ($best === null || $offer['register'] < $best['register'])) {
                    $best = $offer;
                }
            }
            if ($best !== null) {
                $summary = Insights::summary($offers);
                $items[] = ['tld' => $tld] + $this->present($best) + ['savingPct' => $summary['savingPct']];
            }
        }
        return ['items' => $items];
    }

    /**
     * The browsable directory: every TLD with its cheapest register price, filtered and
     * sorted server side so the app can page through 1,000+ extensions.
     *
     * @param array $query q, type, category, sort (popular|register|name), page, per
     */
    public function tlds(array $query)
    {
        $q = isset($query['q']) ? Client::normalizeTld((string) $query['q']) : '';
        $type = isset($query['type']) ? preg_replace('/[^a-z_]/', '', strtolower((string) $query['type'])) : '';
        $category = isset($query['category']) ? preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $query['category'])) : '';
        $sort = isset($query['sort']) && in_array($query['sort'], ['popular', 'register', 'name'], true) ? $query['sort'] : 'popular';
        $per = max(1, min(100, isset($query['per']) ? (int) $query['per'] : 60));
        $page = max(1, isset($query['page']) ? (int) $query['page'] : 1);

        $directory = $this->client->directory();
        $types = [];
        $categories = [];
        $rows = [];
        foreach ($this->client->tlds() as $tld) {
            $meta = isset($directory[$tld]) ? $directory[$tld] : ['type' => '', 'category' => [], 'rank' => 0];
            if ($meta['type'] !== '') {
                $types[$meta['type']] = true;
            }
            foreach ($meta['category'] as $c) {
                $categories[$c] = true;
            }
            if (($q !== '' && strpos($tld, $q) === false)
                || ($type !== '' && $meta['type'] !== $type)
                || ($category !== '' && !in_array($category, $meta['category'], true))) {
                continue;
            }
            $best = $this->client->cheapest($tld);
            if ($best === null) {
                continue;
            }
            $attached = $this->links->attach([$best], $tld);
            if ($attached) {
                $rows[] = ['tld' => $tld, 'meta' => $meta, 'offer' => $attached[0]];
            }
        }
        usort($rows, function ($a, $b) use ($sort) {
            if ($sort === 'register') {
                $c = $a['offer']['register'] <=> $b['offer']['register'];
            } elseif ($sort === 'name') {
                $c = 0;
            } else {
                $c = $b['meta']['rank'] <=> $a['meta']['rank'];
            }
            return $c ?: strcmp($a['tld'], $b['tld']);
        });
        $total = count($rows);
        $items = [];
        foreach (array_slice($rows, ($page - 1) * $per, $per) as $row) {
            $items[] = ['tld' => $row['tld'], 'type' => $row['meta']['type'], 'category' => $row['meta']['category']]
                + $this->present($row['offer']);
        }
        ksort($types);
        $categories = array_keys($categories);
        sort($categories);
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per' => $per,
            'types' => array_keys($types),
            'categories' => $categories,
        ];
    }

    private function present(array $offer)
    {
        return [
            'registrar' => ['slug' => $offer['slug'], 'name' => $offer['name']],
            'register' => $this->currency->convert($offer['register']),
            'renew' => $this->currency->convert($offer['renew']),
            'transfer' => $this->currency->convert($offer['transfer']),
            'buyUrl' => $offer['buyUrl'],
            'renewJump' => Insights::renewJump($offer),
        ];
    }
}
