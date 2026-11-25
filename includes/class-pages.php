<?php

if (!defined('ABSPATH')) {
    exit;
}

use TLDers\Sdk\Client;

/**
 * One page per extension at /{base}/com (default base: "tld"), like tlders.com/tld/com.
 * The pages are virtual: nothing is stored in the database, so there is nothing to
 * clean up and every extension the API covers gets one. Unknown extensions (or ones
 * a free key can't look up) fall through to the theme's normal 404.
 */
class TLDers_DP_Pages
{
    const QUERY_VAR = 'tlders_tld';
    const FLUSH_OPTION = 'tlders_dp_rewrite_state';

    /** @var TLDers_DP_Plugin */
    private static $plugin;

    public static function register(TLDers_DP_Plugin $plugin)
    {
        self::$plugin = $plugin;
        add_filter('query_vars', function ($vars) {
            $vars[] = self::QUERY_VAR;
            return $vars;
        });
        add_action('init', [__CLASS__, 'rewrite'], 11);
        add_filter('the_posts', [__CLASS__, 'virtual_post'], 10, 2);
        add_filter('pre_get_document_title', [__CLASS__, 'document_title']);
        add_action('wp_head', [__CLASS__, 'head'], 1);
    }

    public static function enabled()
    {
        return !empty(self::$plugin->settings()['tld_pages']);
    }

    public static function base()
    {
        $base = sanitize_title((string) self::$plugin->settings()['tld_base']);
        return $base !== '' ? $base : 'tld';
    }

    /** Public URL of an extension's page, or '' when the pages are off. */
    public static function url($tld)
    {
        if (!self::enabled()) {
            return '';
        }
        return home_url('/' . self::base() . '/' . rawurlencode(Client::normalizeTld($tld)) . '/');
    }

    public static function rewrite()
    {
        if (!self::enabled()) {
            return;
        }
        add_rewrite_rule('^' . preg_quote(self::base(), '#') . '/([a-z0-9.-]{1,80})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
        // Rules are cached by WordPress; refresh them once whenever the base or on/off state changes.
        $state = self::base() . '|' . get_option('permalink_structure');
        if (get_option(self::FLUSH_OPTION) !== $state) {
            flush_rewrite_rules(false);
            update_option(self::FLUSH_OPTION, $state, true);
        }
    }

    private static function current_tld()
    {
        if (!self::enabled()) {
            return '';
        }
        return Client::normalizeTld((string) get_query_var(self::QUERY_VAR));
    }

    /** Swaps the (empty) result of a /tld/com request for one virtual page. */
    public static function virtual_post($posts, $query)
    {
        if (!$query->is_main_query() || $query->get(self::QUERY_VAR) === '' || self::current_tld() === '') {
            return $posts;
        }
        $tld = self::current_tld();
        $client = self::$plugin->client();
        if (!$client->canLookup($tld) || !$client->offers($tld)) {
            return $posts;
        }
        $now = current_time('mysql');
        $post = new WP_Post((object) [
            'ID' => 0,
            'post_author' => 0,
            'post_date' => $now,
            'post_date_gmt' => get_gmt_from_date($now),
            'post_modified' => $now,
            'post_modified_gmt' => get_gmt_from_date($now),
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'tld-' . $tld,
            'post_title' => self::heading($tld),
            'post_content' => self::content($tld),
            'post_excerpt' => '',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'filter' => 'raw',
        ]);
        $query->is_page = true;
        $query->is_singular = true;
        $query->is_single = false;
        $query->is_home = false;
        $query->is_archive = false;
        $query->is_category = false;
        $query->is_404 = false;
        $query->queried_object = $post;
        $query->queried_object_id = 0;
        $query->post_count = 1;
        $query->found_posts = 1;
        $query->max_num_pages = 1;
        status_header(200);
        return [$post];
    }

    private static function heading($tld)
    {
        /* translators: %s: extension such as .com */
        return sprintf(__('Cheapest .%s domain registration', 'tlders-domain-prices'), $tld);
    }

    private static function content($tld)
    {
        $intro = sprintf(
            /* translators: %s: extension such as .com */
            __('Compare what every registrar charges to register, renew and transfer a .%s domain, cheapest first.', 'tlders-domain-prices'),
            $tld
        );
        return '<p>' . esc_html($intro) . '</p>[tlders_table tld="' . esc_attr($tld) . '" limit="15"]';
    }

    public static function document_title($title)
    {
        $tld = self::current_tld();
        if ($tld === '' || !is_singular()) {
            return $title;
        }
        return self::heading($tld) . ' – ' . get_bloginfo('name');
    }

    public static function head()
    {
        $tld = self::current_tld();
        if ($tld === '' || !is_singular()) {
            return;
        }
        $client = self::$plugin->client();
        $best = $client->cheapest($tld);
        $desc = $best
            ? sprintf(
                /* translators: 1: extension, 2: price, 3: registrar */
                __('Cheapest .%1$s domain registration: %2$s at %3$s. Compare register, renew and transfer prices across registrars.', 'tlders-domain-prices'),
                $tld,
                self::$plugin->currency()->format($best['register']),
                $best['name']
            )
            : self::heading($tld);
        echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
        echo '<link rel="canonical" href="' . esc_url(self::url($tld)) . '">' . "\n";
    }
}
