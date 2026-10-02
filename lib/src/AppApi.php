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
 *   GET {base}/config                  site name, currency, disclosure, popular TLDs
 *   GET {base}/search?q=example.io     offers for the TLD of what was typed
 *   GET {base}/tld/{tld}               offers for one TLD
 *   GET {base}/cheapest?tlds=com,io    cheapest offer per TLD
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
     * @param array $site name, disclosure, popularTlds (string[])
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
        return [200, [
            'tld' => $tld,
            'domain' => $domain,
            'offers' => array_map([$this, 'present'], $offers),
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
                $items[] = ['tld' => $tld] + $this->present($best);
            }
        }
        return ['items' => $items];
    }

    private function present(array $offer)
    {
        return [
            'registrar' => ['slug' => $offer['slug'], 'name' => $offer['name']],
            'register' => $this->currency->convert($offer['register']),
            'renew' => $this->currency->convert($offer['renew']),
            'transfer' => $this->currency->convert($offer['transfer']),
            'buyUrl' => $offer['buyUrl'],
        ];
    }
}
