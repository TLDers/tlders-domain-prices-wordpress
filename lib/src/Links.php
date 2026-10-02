<?php

namespace TLDers\Sdk;

// Only loaded through autoload.php (WordPress, the TLDers script, or the tests).
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds "Buy" links. A registrar the site owner has set up uses the owner's
 * own affiliate link, so the owner earns the commission. The rest either go
 * through tlders.com/go (TLDers' affiliate links, tagged with the source so
 * TLDers can see which integration sent the click) or are hidden.
 *
 * Templates take {domain} (example.com, or the bare TLD when no domain was
 * searched), {sld} (example) and {tld} (com). A template without placeholders
 * is used as-is.
 */
class Links
{
    const FALLBACK_TLDERS = 'tlders';
    const FALLBACK_HIDE = 'hide';

    /** @var array<string,string> */
    private $templates;
    /** @var string */
    private $fallback;
    /** @var string */
    private $source;
    /** @var string */
    private $site;

    /**
     * @param array<string,string> $templates registrar slug => affiliate link template
     * @param string $fallback FALLBACK_TLDERS or FALLBACK_HIDE
     * @param string $source   integration tag sent as /go?src= (wp-plugin, php-script …)
     */
    public function __construct(array $templates, $fallback = self::FALLBACK_TLDERS, $source = 'php-sdk', $site = 'https://www.tlders.com')
    {
        $this->templates = [];
        foreach ($templates as $slug => $template) {
            $template = trim((string) $template);
            if ($template !== '' && self::isHttpUrl($template)) {
                $this->templates[(string) $slug] = $template;
            }
        }
        $this->fallback = $fallback === self::FALLBACK_HIDE ? self::FALLBACK_HIDE : self::FALLBACK_TLDERS;
        $this->source = preg_match('/^[a-z0-9-]{1,32}$/', $source) ? $source : 'php-sdk';
        $this->site = rtrim($site, '/');
    }

    /** True when the owner has their own link for this registrar. */
    public function isOwn($slug)
    {
        return isset($this->templates[$slug]);
    }

    /**
     * @param string $slug   registrar slug
     * @param string $tld    e.g. "com"
     * @param string $domain e.g. "example.com", or '' when only a TLD is shown
     * @return string|null null when the registrar should be hidden
     */
    public function url($slug, $tld, $domain = '')
    {
        if (isset($this->templates[$slug])) {
            $sld = $domain !== '' && substr($domain, -strlen('.' . $tld)) === '.' . $tld
                ? substr($domain, 0, -strlen('.' . $tld))
                : '';
            return strtr($this->templates[$slug], [
                '{domain}' => rawurlencode($domain !== '' ? $domain : $tld),
                '{sld}' => rawurlencode($sld),
                '{tld}' => rawurlencode($tld),
            ]);
        }
        if ($this->fallback === self::FALLBACK_HIDE) {
            return null;
        }
        $query = ['tld' => $tld];
        if ($domain !== '') {
            $query['domain'] = $domain;
        }
        $query['src'] = $this->source;
        return $this->site . '/go/' . rawurlencode($slug) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** Drops offers with no link and adds buyUrl + isAffiliate to the rest. */
    public function attach(array $offers, $tld, $domain = '')
    {
        $out = [];
        foreach ($offers as $offer) {
            $url = $this->url($offer['slug'], $tld, $domain);
            if ($url === null) {
                continue;
            }
            $offer['buyUrl'] = $url;
            $offer['isOwnLink'] = $this->isOwn($offer['slug']);
            $out[] = $offer;
        }
        return $out;
    }

    public static function isHttpUrl($url)
    {
        return (bool) preg_match('#^https?://[^\s"<>]+$#i', $url);
    }
}

/** Shows USD prices in the site's currency at a fixed rate the owner sets. */
class Currency
{
    /** @var string */
    public $code;
    /** @var string */
    public $symbol;
    /** @var float */
    public $rate;
    /** @var int */
    public $decimals;

    public function __construct($code = 'USD', $symbol = '$', $rate = 1.0, $decimals = 2)
    {
        $this->code = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $code)) ?: 'USD';
        $this->symbol = (string) $symbol;
        $this->rate = is_numeric($rate) && $rate > 0 ? (float) $rate : 1.0;
        $this->decimals = max(0, min(3, (int) $decimals));
    }

    /** USD amount → amount in this currency, or null. */
    public function convert($usd)
    {
        return $usd === null ? null : round($usd * $this->rate, $this->decimals);
    }

    /** USD amount → "₹829.00", or "—" for an unknown price. */
    public function format($usd)
    {
        if ($usd === null) {
            return '—';
        }
        return $this->symbol . number_format($this->convert($usd), $this->decimals);
    }
}
