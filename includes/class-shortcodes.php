<?php

if (!defined('ABSPATH')) {
    exit;
}

use TLDers\Sdk\Client;

/**
 * [tlders_price tld="com"]                       "$9.58 at Namecheap", linked
 * [tlders_table tld="com" limit="10"]            registrar comparison table for one TLD
 * [tlders_cheapest tlds="com,net,io"]            cheapest registrar for each TLD
 * [tlders_search]                                search box; shows the table for what was typed
 * [tlders_search page="12"]                      search box only, sending visitors to page 12 for results
 *                                                (for sidebars: page 12 holds a plain [tlders_search])
 */
class TLDers_DP_Shortcodes
{
    const QUERY_VAR = 'tlders_q';
    const TYPES = ['register', 'renew', 'transfer'];

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
        $a = shortcode_atts(['tld' => 'com', 'domain' => '', 'limit' => 10, 'show' => 'register,renew,transfer'], $atts, 'tlders_table');
        $tld = Client::normalizeTld($a['tld']);
        $domain = sanitize_text_field($a['domain']);
        return self::render_table($tld, $domain, (int) $a['limit'], self::columns($a['show']));
    }

    public static function cheapest($atts)
    {
        $a = shortcode_atts(['tlds' => self::$plugin->settings()['popular_tlds'], 'type' => 'register'], $atts, 'tlders_cheapest');
        $type = in_array($a['type'], self::TYPES, true) ? $a['type'] : 'register';
        $currency = self::$plugin->currency();
        $rows = '';
        foreach (array_slice(Client::normalizeList($a['tlds']), 0, 50) as $tld) {
            $best = null;
            foreach (self::offers($tld) as $o) {
                if ($o[$type] !== null && ($best === null || $o[$type] < $best[$type])) {
                    $best = $o;
                }
            }
            if ($best === null) {
                continue;
            }
            $rows .= '<tr><td class="tlders-tld">.' . esc_html($tld) . '</td>'
                . '<td class="tlders-num" data-label="' . esc_attr(self::label($type)) . '">' . esc_html($currency->format($best[$type])) . '</td>'
                . '<td class="tlders-at">' . esc_html($best['name']) . '</td>'
                . '<td class="tlders-buy">' . self::link($best['buyUrl'], __('Buy', 'tlders-domain-prices'), 'tlders-button') . '</td></tr>';
        }
        if ($rows === '') {
            return self::unavailable();
        }
        $head = '<tr><th>' . esc_html__('Extension', 'tlders-domain-prices') . '</th><th class="tlders-num">' . esc_html(self::label($type)) . '</th><th>'
            . esc_html__('Cheapest at', 'tlders-domain-prices') . '</th><th></th></tr>';
        return self::wrap('<table class="tlders-table"><thead>' . $head . '</thead><tbody>' . $rows . '</tbody></table>');
    }

    public static function search($atts)
    {
        $a = shortcode_atts(['placeholder' => __('Search a domain, e.g. mybrand.com', 'tlders-domain-prices'), 'limit' => 10, 'show' => 'register,renew', 'page' => 0], $atts, 'tlders_search');
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

        if ($query === '' || $resultsPage) {
            wp_enqueue_style('tlders-dp');
            return '<div class="tlders">' . $form . '</div>';
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
            $result = $heading . self::render_table($tld, $domain, (int) $a['limit'], self::columns($a['show']), false);
        }
        return self::wrap($form . $result);
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

    private static function render_table($tld, $domain, $limit, array $columns, $wrap = true)
    {
        $offers = array_slice(self::offers($tld, $domain), 0, max(1, min(100, $limit)));
        if (!$offers) {
            return $wrap ? self::unavailable() : '<p class="tlders-note">' . esc_html__('No prices found.', 'tlders-domain-prices') . '</p>';
        }
        $currency = self::$plugin->currency();
        $head = '<th>' . esc_html__('Registrar', 'tlders-domain-prices') . '</th>';
        foreach ($columns as $col) {
            $head .= '<th class="tlders-num">' . esc_html(self::label($col)) . '</th>';
        }
        $head .= '<th></th>';
        $rows = '';
        foreach ($offers as $i => $o) {
            $rows .= '<tr' . ($i === 0 ? ' class="tlders-best"' : '') . '><td class="tlders-name">' . esc_html($o['name']) . '</td>';
            foreach ($columns as $col) {
                $rows .= '<td class="tlders-num" data-label="' . esc_attr(self::label($col)) . '">' . esc_html($currency->format($o[$col])) . '</td>';
            }
            $rows .= '<td class="tlders-buy">' . self::link($o['buyUrl'], __('Buy', 'tlders-domain-prices'), 'tlders-button') . '</td></tr>';
        }
        $table = '<table class="tlders-table"><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table>';
        return $wrap ? self::wrap($table) : $table;
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

    /** Wraps a block, adding the affiliate disclosure (once per page) and the optional credit. */
    private static function wrap($html)
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
        return '<div class="tlders">' . $html . ($foot !== '' ? '<p class="tlders-foot">' . $foot . '</p>' : '') . '</div>';
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
