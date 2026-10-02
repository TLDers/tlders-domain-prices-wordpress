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
            $cards .= '<div class="tlders-card" data-item data-name="' . esc_attr($tld) . '" data-register="' . esc_attr((string) $o[$type]) . '"><div class="tlders-card-head">'
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
        $toolbar = '<div class="tlders-toolbar" data-js-only hidden>'
            . '<span class="tlders-sort" role="group" aria-label="' . esc_attr__('Sort by', 'tlders-domain-prices') . '">'
            . '<button type="button" data-sort="register" class="is-active" aria-pressed="true">' . esc_html__('Cheapest', 'tlders-domain-prices') . '</button>'
            . '<button type="button" data-sort="name" aria-pressed="false">' . esc_html__('A–Z', 'tlders-domain-prices') . '</button></span>'
            . '<input type="search" data-filter hidden placeholder="' . esc_attr__('Filter extensions…', 'tlders-domain-prices') . '" aria-label="' . esc_attr__('Filter extensions', 'tlders-domain-prices') . '">'
            . self::view_toggle('grid') . '</div>';
        return self::wrap('<div class="tlders-list is-grid" data-tlders-list="tlds">' . $toolbar
            . '<div class="tlders-grid" data-items>' . $cards . '</div>'
            . '<p class="tlders-note" data-empty hidden>' . esc_html__('No extension matches that filter.', 'tlders-domain-prices') . '</p></div>', $skin);
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
        static $instance = 0;
        $instance++;
        $live = !$resultsPage;
        $targetId = 'tlders-live-' . $instance;
        $endpoint = add_query_arg([
            'limit' => (int) $a['limit'],
            'show' => implode(',', self::columns($a['show'])),
            'skin' => $skin,
        ], rest_url(TLDers_DP_Plugin::REST_NAMESPACE . '/live'));
        $form = '<form class="tlders-search" method="get" action="' . esc_url($action) . '" role="search"'
            . ($live ? ' data-tlders-live data-endpoint="' . esc_url($endpoint) . '" data-target="#' . esc_attr($targetId) . '"' : '') . '>' . $hidden
            . '<input type="search" name="' . esc_attr(self::QUERY_VAR) . '" value="' . esc_attr($resultsPage ? '' : $query) . '" placeholder="' . esc_attr($a['placeholder']) . '" aria-label="' . esc_attr__('Domain or extension', 'tlders-domain-prices') . '" maxlength="253" required autocomplete="off">'
            . '<button type="submit">' . esc_html__('Compare prices', 'tlders-domain-prices') . '</button></form>';

        // The search box sits in a hero panel; in a sidebar (results on another page) it's compact.
        $panel = '<div class="tlders-hero' . ($resultsPage ? ' tlders-compact' : '') . '">'
            . (!$resultsPage && trim($a['title']) !== '' ? '<p class="tlders-hero-title">' . esc_html($a['title']) . '</p>' : '')
            . $form . '</div>';

        if ($resultsPage) {
            self::enqueue();
            return '<div class="tlders tlders-skin-' . esc_attr($skin) . '">' . $panel . '</div>';
        }
        $result = $query !== '' ? self::search_results($query, (int) $a['limit'], self::columns($a['show']), $skin) : '';
        $results = '<div id="' . esc_attr($targetId) . '" class="tlders-live" aria-live="polite"' . ($result === '' ? ' hidden' : '') . '>' . $result . '</div>';
        if ($query === '') {
            self::enqueue();
            return '<div class="tlders tlders-skin-' . esc_attr($skin) . '">' . $panel . $results . '</div>';
        }
        return self::wrap($panel . $results, $skin);
    }

    /**
     * Heading + registrar list for what a visitor searched (or a note explaining why not).
     * Used by [tlders_search] and the live-search REST endpoint, so both render the same.
     */
    public static function search_results($query, $limit, array $columns, $skin)
    {
        $client = self::$plugin->client();
        list($domain, $tld) = $client->parseQuery($query);
        if ($tld === '') {
            return '<p class="tlders-note">' . esc_html__('Enter a domain like mybrand.com or an extension like .io.', 'tlders-domain-prices') . '</p>';
        }
        if (!$client->canLookup($tld)) {
            return '<p class="tlders-note">' . esc_html(sprintf(
                /* translators: 1: TLD, 2: comma-separated list of TLDs */
                __('Prices for .%1$s aren\'t available here. Try: %2$s', 'tlders-domain-prices'),
                $tld,
                '.' . implode(', .', $client->freeTlds())
            )) . '</p>';
        }
        $heading = '<h3 class="tlders-heading">' . esc_html(sprintf(
            /* translators: %s: domain or TLD */
            __('Prices for %s', 'tlders-domain-prices'),
            $domain !== '' ? $domain : '.' . $tld
        )) . '</h3>';
        $offers = self::render_offers($tld, $domain, $limit, $columns, true, $skin);
        return $heading . ($offers !== '' ? $offers : '<p class="tlders-note">' . esc_html__('No prices found.', 'tlders-domain-prices') . '</p>');
    }

    /** Live search (tlders-ui.js): GET /wp-json/tlders/v1/live?tlders_q=…&limit=&show=&skin= → {html}. */
    public static function rest_live(WP_REST_Request $request)
    {
        $query = sanitize_text_field((string) $request->get_param(self::QUERY_VAR));
        $limit = max(1, min(100, (int) ($request->get_param('limit') ?: 10)));
        $html = $query === '' ? '' : self::search_results($query, $limit, self::columns((string) $request->get_param('show')), self::skin((string) $request->get_param('skin')));
        $s = self::$plugin->settings();
        if ($html !== '' && $s['show_disclosure'] && trim($s['disclosure']) !== '') {
            $html .= '<p class="tlders-foot"><span class="tlders-disclosure">' . esc_html($s['disclosure']) . '</span></p>';
        }
        $response = new WP_REST_Response(['html' => $html], 200);
        $response->header('Cache-Control', 'public, max-age=300');
        return $response;
    }

    private static function enqueue()
    {
        wp_enqueue_style('tlders-dp');
        wp_enqueue_script('tlders-dp-ui');
    }

    private static function view_toggle($active)
    {
        $views = ['list' => ['☰', __('List view', 'tlders-domain-prices')], 'grid' => ['▦', __('Grid view', 'tlders-domain-prices')]];
        $html = '<span class="tlders-views" data-js-only hidden>';
        foreach ($views as $view => $info) {
            $on = $view === $active;
            $html .= '<button type="button" data-view="' . esc_attr($view) . '"' . ($on ? ' class="is-active"' : '') . ' aria-pressed="' . ($on ? 'true' : 'false') . '" title="' . esc_attr($info[1]) . '" aria-label="' . esc_attr($info[1]) . '">' . esc_html($info[0]) . '</button>';
        }
        return $html . '</span>';
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

    /**
     * Summary stats + one card per registrar; '' when there are no prices. Every registrar is
     * rendered; rows past $limit start hidden and "Show all" (tlders-ui.js) reveals them.
     */
    private static function render_offers($tld, $domain, $limit, array $columns, $stats, $skin)
    {
        $offers = array_slice(self::offers($tld, $domain), 0, 100);
        if (!$offers) {
            return '';
        }
        $limit = max(1, min(100, $limit));
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
        $toolbar = '<div class="tlders-toolbar" data-js-only hidden>';
        if (count($columns) > 1) {
            $toolbar .= '<span class="tlders-sort" role="group" aria-label="' . esc_attr__('Sort by', 'tlders-domain-prices') . '">';
            foreach ($columns as $c => $col) {
                $toolbar .= '<button type="button" data-sort="' . esc_attr($col) . '"' . ($c === 0 ? ' class="is-active"' : '') . ' aria-pressed="' . ($c === 0 ? 'true' : 'false') . '">' . esc_html(self::label($col)) . '</button>';
            }
            $toolbar .= '</span>';
        }
        if (count($offers) > 4) {
            $toolbar .= '<input type="search" data-filter hidden placeholder="' . esc_attr__('Filter registrars…', 'tlders-domain-prices') . '" aria-label="' . esc_attr__('Filter registrars', 'tlders-domain-prices') . '">';
        }
        $toolbar .= self::view_toggle('list') . '</div>';

        $html .= '<div class="tlders-list" data-tlders-list="offers" data-limit="' . (int) $limit . '">' . $toolbar . '<div class="tlders-offers" data-items>';
        foreach ($offers as $i => $o) {
            $best = $summary['cheapest'] && $o['slug'] === $summary['cheapest']['slug'];
            $data = ' data-item data-name="' . esc_attr($o['name']) . '"';
            foreach (self::TYPES as $t) {
                $data .= ' data-' . $t . '="' . esc_attr($o[$t] === null ? '' : (string) $o[$t]) . '"';
            }
            $html .= '<div class="tlders-offer tlders-cols-' . count($columns) . ($best ? ' tlders-is-best' : '') . '"' . $data . ($i >= $limit ? ' hidden' : '') . '>'
                . '<div class="tlders-who">' . self::avatar($o, $skin) . '<div><strong>' . esc_html($o['name']) . '</strong>'
                . ($best ? '<span class="tlders-badge tlders-best">' . esc_html__('Best price', 'tlders-domain-prices') . '</span>' : '')
                . '</div></div>';
            foreach ($columns as $c => $col) {
                $html .= '<div class="tlders-cell tlders-col-' . esc_attr($col) . ($c === 0 ? ' tlders-main' : '') . '">'
                    . '<span class="tlders-lbl">' . esc_html(self::label($col)) . '</span>'
                    . '<b>' . esc_html($currency->format($o[$col])) . '</b>';
                if ($c === 0) {
                    $html .= '<span class="tlders-bar"><i data-bar style="width:' . (int) $bars[$i] . '%"></i></span>';
                }
                if ($col === 'renew' && Insights::renewJump($o)) {
                    $html .= '<span class="tlders-badge tlders-warn">' . esc_html__('Promo first year', 'tlders-domain-prices') . '</span>';
                }
                $html .= '</div>';
            }
            $html .= self::link($o['buyUrl'], __('Buy', 'tlders-domain-prices'), 'tlders-button') . '</div>';
        }
        $html .= '</div><p class="tlders-note" data-empty hidden>' . esc_html__('No registrar matches that filter.', 'tlders-domain-prices') . '</p>';
        if (count($offers) > $limit) {
            $html .= '<button type="button" class="tlders-more" data-more hidden>' . sprintf(
                /* translators: %s: number of registrars */
                esc_html__('Show all %s registrars', 'tlders-domain-prices'),
                '<span data-count>' . count($offers) . '</span>'
            ) . '</button>';
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
        self::enqueue();
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
