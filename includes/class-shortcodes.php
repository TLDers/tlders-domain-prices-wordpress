<?php

if (!defined('ABSPATH')) {
    exit;
}

use TLDers\Sdk\Client;
use TLDers\Sdk\Insights;

/**
 * [tlders_price tld="com"]                       "$9.58 at Namecheap", linked
 * [tlders_table tld="com" limit="10"]            registrar comparison table for one TLD
 * [tlders_cheapest tlds="com,net,io"]            cheapest registrar for each TLD
 * [tlders_search]                                search box; shows the table for what was typed
 * [tlders_search page="12"]                      search box only, sending visitors to page 12 for results
 *                                                (for sidebars: page 12 holds a plain [tlders_search])
 * Every shortcode also takes skin="theme|aurora|midnight|fresh|sunset|minimal" (default: Settings).
 */
class TLDers_DP_Shortcodes
{
    const QUERY_VAR = 'tlders_q';
    const TYPES = ['register', 'renew', 'transfer'];
    /** "theme" blends into the site's theme; the others are the shared TLDers skins. */
    const SKINS = ['theme', 'aurora', 'midnight', 'fresh', 'sunset', 'minimal'];

    /** @var TLDers_DP_Plugin */
    private static $plugin;
    /** @var bool */
    private static $disclosed = false;

    public static function register(TLDers_DP_Plugin $plugin)
    {
        self::$plugin = $plugin;
        add_shortcode('tlders_price', [__CLASS__, 'price']);
        add_shortcode('tlders_table', [__CLASS__, 'table']);
        add_shortcode('tlders_cheapest', [__CLASS__, 'cheapest']);
        add_shortcode('tlders_search', [__CLASS__, 'search']);
    }

    public static function price($atts)
    {
        $a = shortcode_atts(['tld' => 'com', 'type' => 'register', 'registrar' => '', 'link' => 'yes', 'show_registrar' => 'yes'], $atts, 'tlders_price');
        $tld = Client::normalizeTld($a['tld']);
        $type = in_array($a['type'], self::TYPES, true) ? $a['type'] : 'register';
        $offer = null;
        foreach (self::offers($tld) as $o) {
            if ($o[$type] === null || ($a['registrar'] !== '' && $o['slug'] !== $a['registrar'])) {
                continue;
            }
            if ($offer === null || $o[$type] < $offer[$type]) {
                $offer = $o;
            }
        }
        if ($offer === null) {
            return '';
        }
        $text = self::$plugin->currency()->format($offer[$type]);
        if ($a['show_registrar'] === 'yes') {
            /* translators: 1: price, 2: registrar name */
            $text = sprintf(__('%1$s at %2$s', 'tlders-domain-prices'), $text, $offer['name']);
        }
        $html = $a['link'] === 'yes' ? self::link($offer['buyUrl'], $text, 'tlders-price') : '<span class="tlders-price">' . esc_html($text) . '</span>';
        return $html;
    }

    public static function table($atts)
    {
        $a = shortcode_atts(['tld' => 'com', 'domain' => '', 'limit' => 10, 'show' => 'register,renew,transfer', 'stats' => 'yes', 'skin' => ''], $atts, 'tlders_table');
        $tld = Client::normalizeTld($a['tld']);
        $domain = sanitize_text_field($a['domain']);
        $skin = self::skin($a['skin']);
        $html = self::render_offers($tld, $domain, (int) $a['limit'], self::columns($a['show']), $a['stats'] !== 'no', $skin);
        return $html === '' ? self::unavailable() : self::wrap($html, $skin);
    }

    public static function cheapest($atts)
    {
        $a = shortcode_atts(['tlds' => self::$plugin->settings()['popular_tlds'], 'type' => 'register', 'skin' => ''], $atts, 'tlders_cheapest');
        $type = in_array($a['type'], self::TYPES, true) ? $a['type'] : 'register';
        $skin = self::skin($a['skin']);
        $currency = self::$plugin->currency();
        $cards = '';
        foreach (array_slice(Client::normalizeList($a['tlds']), 0, 50) as $tld) {
            $summary = Insights::summary(self::offers($tld), $type);
            $o = $summary['cheapest'];
            if ($o === null) {
                continue;
            }
            $renew = $o['renew'] !== null
                ? '<span class="' . (Insights::renewJump($o) ? 'tlders-badge tlders-warn' : 'tlders-muted') . '">' . esc_html(sprintf(
                    /* translators: %s: renewal price */
                    __('Renews %s', 'tlders-domain-prices'),
                    $currency->format($o['renew'])
                )) . '</span>'
                : '<span></span>';
            $cards .= '<div class="tlders-card"><div class="tlders-card-head">'
                . '<span class="tlders-ext">.' . esc_html($tld) . '</span>'
                . ($summary['savingPct'] ? '<span class="tlders-badge tlders-save">' . esc_html(sprintf(
                    /* translators: %d: percentage saved */
                    __('Save %d%%', 'tlders-domain-prices'),
                    $summary['savingPct']
                )) . '</span>' : '')
                . '</div>'
                . '<div class="tlders-from">' . esc_html(self::label($type)) . '</div>'
                . '<div class="tlders-big">' . esc_html($currency->format($o[$type])) . '</div>'
                . '<div class="tlders-at">' . self::avatar($o, $skin) . '<span>' . esc_html(sprintf(
                    /* translators: %s: registrar name */
                    __('at %s', 'tlders-domain-prices'),
                    $o['name']
                )) . '</span></div>'
                . '<div class="tlders-card-foot">' . $renew . self::link($o['buyUrl'], __('Buy', 'tlders-domain-prices'), 'tlders-button') . '</div>'
                . '</div>';
        }
        if ($cards === '') {
            return self::unavailable();
        }
        return self::wrap('<div class="tlders-grid">' . $cards . '</div>', $skin);
    }

    public static function search($atts)
    {
        $a = shortcode_atts([
            'placeholder' => __('e.g. mybrand.com', 'tlders-domain-prices'),
            'title' => __('Find the cheapest place to register your domain', 'tlders-domain-prices'),
            'limit' => 10,
            'show' => 'register,renew',
            'page' => 0,
            'skin' => '',
        ], $atts, 'tlders_search');
        $skin = self::skin($a['skin']);
        // With a results page set, this is just a form (e.g. in a sidebar) that
        // sends visitors there, so results never show twice on that page.
        $resultsPage = (int) $a['page'] > 0 ? get_permalink((int) $a['page']) : false;
        // A read-only GET search form showing public prices: nothing to protect with a nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $query = isset($_GET[self::QUERY_VAR]) ? sanitize_text_field(wp_unslash($_GET[self::QUERY_VAR])) : '';

        // A GET form drops the action's own query string, so with plain
        // permalinks (?page_id=4) carry those parameters as hidden fields.
        $action = (string) ($resultsPage ?: get_permalink());
        $hidden = '';
        parse_str((string) wp_parse_url($action, PHP_URL_QUERY), $params);
        foreach ($params as $key => $value) {
            if ($key !== self::QUERY_VAR && is_scalar($value)) {
                $hidden .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
            }
        }
        $form = '<form class="tlders-search" method="get" action="' . esc_url($action) . '" role="search">' . $hidden
            . '<input type="search" name="' . esc_attr(self::QUERY_VAR) . '" value="' . esc_attr($resultsPage ? '' : $query) . '" placeholder="' . esc_attr($a['placeholder']) . '" aria-label="' . esc_attr__('Domain or extension', 'tlders-domain-prices') . '" maxlength="253" required>'
            . '<button type="submit">' . esc_html__('Compare prices', 'tlders-domain-prices') . '</button></form>';

        // The search box sits in a hero panel; in a sidebar (results on another page) it's compact.
        $panel = '<div class="tlders-hero' . ($resultsPage ? ' tlders-compact' : '') . '">'
            . (!$resultsPage && trim($a['title']) !== '' ? '<p class="tlders-hero-title">' . esc_html($a['title']) . '</p>' : '')
            . $form . '</div>';

        if ($query === '' || $resultsPage) {
            wp_enqueue_style('tlders-dp');
            return '<div class="tlders tlders-skin-' . esc_attr($skin) . '">' . $panel . '</div>';
        }

        $client = self::$plugin->client();
        list($domain, $tld) = $client->parseQuery($query);
        if ($tld === '') {
            $result = '<p class="tlders-note">' . esc_html__('Enter a domain like mybrand.com or an extension like .io.', 'tlders-domain-prices') . '</p>';
        } elseif (!$client->canLookup($tld)) {
            $result = '<p class="tlders-note">' . esc_html(sprintf(
                /* translators: 1: TLD, 2: comma-separated list of TLDs */
                __('Prices for .%1$s aren\'t available here. Try: %2$s', 'tlders-domain-prices'),
                $tld,
                '.' . implode(', .', $client->freeTlds())
            )) . '</p>';
        } else {
            $heading = '<h3 class="tlders-heading">' . esc_html(sprintf(
                /* translators: %s: domain or TLD */
                __('Prices for %s', 'tlders-domain-prices'),
                $domain !== '' ? $domain : '.' . $tld
            )) . '</h3>';
            $offers = self::render_offers($tld, $domain, (int) $a['limit'], self::columns($a['show']), true, $skin);
            $result = $heading . ($offers !== '' ? $offers : '<p class="tlders-note">' . esc_html__('No prices found.', 'tlders-domain-prices') . '</p>');
        }
        return self::wrap($panel . $result, $skin);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** Offers with buy links (owner links, TLDers fallback, or hidden). */
    private static function offers($tld, $domain = '')
    {
        if ($tld === '') {
            return [];
        }
        return self::$plugin->links()->attach(self::$plugin->client()->offers($tld), $tld, $domain);
    }

    /** Summary stats + one card per registrar; '' when there are no prices. */
    private static function render_offers($tld, $domain, $limit, array $columns, $stats, $skin)
    {
        $offers = array_slice(self::offers($tld, $domain), 0, max(1, min(100, $limit)));
        if (!$offers) {
            return '';
        }
        $currency = self::$plugin->currency();
        $summary = Insights::summary($offers);
        $bars = Insights::bars($offers, $columns[0]);
        $html = '';
        if ($stats && $summary['cheapest']) {
            $html .= '<div class="tlders-stats">'
                . self::stat(__('Best price', 'tlders-domain-prices'), $currency->format($summary['cheapest']['register']), true)
                . self::stat(__('Average', 'tlders-domain-prices'), $currency->format($summary['average']))
                . self::stat(__('You save', 'tlders-domain-prices'), $summary['savingPct'] ? $summary['savingPct'] . '%' : '—')
                . '</div>';
        }
        $html .= '<div class="tlders-offers">';
        foreach ($offers as $i => $o) {
            $best = $summary['cheapest'] && $o['slug'] === $summary['cheapest']['slug'];
            $html .= '<div class="tlders-offer tlders-cols-' . count($columns) . ($best ? ' tlders-is-best' : '') . '">'
                . '<div class="tlders-who">' . self::avatar($o, $skin) . '<div><strong>' . esc_html($o['name']) . '</strong>'
                . ($best ? '<span class="tlders-badge tlders-best">' . esc_html__('Best price', 'tlders-domain-prices') . '</span>' : '')
                . '</div></div>';
            foreach ($columns as $c => $col) {
                $html .= '<div class="tlders-cell tlders-col-' . esc_attr($col) . ($c === 0 ? ' tlders-main' : '') . '">'
                    . '<span class="tlders-lbl">' . esc_html(self::label($col)) . '</span>'
                    . '<b>' . esc_html($currency->format($o[$col])) . '</b>';
                if ($c === 0 && $bars[$i]) {
                    $html .= '<span class="tlders-bar"><i style="width:' . (int) $bars[$i] . '%"></i></span>';
                }
                if ($col === 'renew' && Insights::renewJump($o)) {
                    $html .= '<span class="tlders-badge tlders-warn">' . esc_html__('Promo first year', 'tlders-domain-prices') . '</span>';
                }
                $html .= '</div>';
            }
            $html .= self::link($o['buyUrl'], __('Buy', 'tlders-domain-prices'), 'tlders-button') . '</div>';
        }
        return $html . '</div>';
    }

    private static function stat($label, $value, $highlight = false)
    {
        return '<div class="tlders-stat' . ($highlight ? ' tlders-hl' : '') . '"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong></div>';
    }

    /** Registrar initials on a colour that's stable per registrar (plain CSS, so it survives wp_kses). */
    private static function avatar(array $offer, $skin)
    {
        $h = Insights::hue($offer['slug']);
        $style = $skin === 'midnight'
            ? "background-color:hsl($h,45%,20%);color:hsl($h,90%,80%)"
            : "background-color:hsl($h,85%,90%);color:hsl($h,65%,30%)";
        return '<span class="tlders-avatar" style="' . esc_attr($style) . '" aria-hidden="true">' . esc_html(Insights::initials($offer['name'])) . '</span>';
    }

    /** A valid skin: the shortcode's, else the one in Settings. */
    public static function skin($skin)
    {
        $skin = (string) $skin !== '' ? $skin : self::$plugin->settings()['skin'];
        return in_array($skin, self::SKINS, true) ? $skin : 'theme';
    }

    private static function columns($show)
    {
        $cols = array_values(array_intersect(self::TYPES, array_map('trim', explode(',', (string) $show))));
        return $cols ?: ['register'];
    }

    private static function label($type)
    {
        $labels = [
            'register' => __('Register', 'tlders-domain-prices'),
            'renew' => __('Renew', 'tlders-domain-prices'),
            'transfer' => __('Transfer', 'tlders-domain-prices'),
        ];
        return $labels[$type];
    }

    private static function link($url, $text, $class)
    {
        $newTab = self::$plugin->settings()['new_tab'];
        return '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '" rel="sponsored nofollow noopener"'
            . ($newTab ? ' target="_blank"' : '') . '>' . esc_html($text) . '</a>';
    }

    /** Wraps a block in its skin, adding the affiliate disclosure (once per page) and the optional credit. */
    private static function wrap($html, $skin)
    {
        wp_enqueue_style('tlders-dp');
        $s = self::$plugin->settings();
        $foot = '';
        if ($s['show_disclosure'] && !self::$disclosed && trim($s['disclosure']) !== '') {
            self::$disclosed = true;
            $foot .= '<span class="tlders-disclosure">' . esc_html($s['disclosure']) . '</span> ';
        }
        if ($s['credit']) {
            $foot .= '<span class="tlders-credit">' . sprintf(
                /* translators: %s: link to TLDers */
                esc_html__('Prices by %s', 'tlders-domain-prices'),
                '<a href="https://www.tlders.com" target="_blank" rel="noopener">TLDers</a>'
            ) . '</span>';
        }
        return '<div class="tlders tlders-skin-' . esc_attr($skin) . '">' . $html . ($foot !== '' ? '<p class="tlders-foot">' . $foot . '</p>' : '') . '</div>';
    }

    private static function unavailable()
    {
        // Visitors see a quiet note; the reason is on the settings screen.
        return current_user_can('manage_options')
            ? '<p class="tlders-note">' . esc_html(sprintf(
                /* translators: %s: error message */
                __('TLDers prices unavailable (only admins see this): %s', 'tlders-domain-prices'),
                self::$plugin->client()->lastError() ?: __('no prices for this TLD.', 'tlders-domain-prices')
            )) . '</p>'
            : '';
    }
}
