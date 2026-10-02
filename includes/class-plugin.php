<?php

if (!defined('ABSPATH')) {
    exit;
}

use TLDers\Sdk\AppApi;
use TLDers\Sdk\Client;
use TLDers\Sdk\Currency;
use TLDers\Sdk\Links;

/** Settings, the shared SDK objects, background refresh and the mobile app REST API. */
class TLDers_DP_Plugin
{
    const OPTION = 'tlders_dp_settings';
    const CRON_HOOK = 'tlders_dp_refresh';
    const REST_NAMESPACE = 'tlders/v1';
    const SOURCE = 'wp-plugin';

    /** @var self|null */
    private static $instance;
    /** @var Client|null */
    private $client;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot()
    {
        add_action(self::CRON_HOOK, [$this, 'refresh']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        // On init, not wp_enqueue_scripts: block themes render post content
        // (and so the shortcodes that enqueue this) before that hook fires.
        add_action('init', [$this, 'register_assets']);
        add_action('init', ['TLDers_DP_Block', 'register'], 20);
        add_action('enqueue_block_editor_assets', ['TLDers_DP_Block', 'editor_data']);
        add_action('widgets_init', ['TLDers_DP_Widget', 'register']);
        TLDers_DP_Shortcodes::register($this);
        if (is_admin()) {
            new TLDers_DP_Admin($this);
        }
    }

    public static function activate()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'twicedaily', self::CRON_HOOK);
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function defaults()
    {
        return [
            'api_key' => '',
            'links' => [],
            'fallback' => Links::FALLBACK_TLDERS,
            'currency_code' => 'USD',
            'currency_symbol' => '$',
            'currency_rate' => 1,
            'decimals' => 2,
            'free_tlds' => implode(', ', Client::DEFAULT_FREE_TLDS),
            'popular_tlds' => 'com, net, org, io, ai, co, app, dev, in, xyz',
            'new_tab' => 1,
            'show_disclosure' => 1,
            'disclosure' => __('We may earn a commission when you buy through links on this page, at no extra cost to you.', 'tlders-domain-prices'),
            'credit' => 0,
            'app_api' => 0,
            'app_name' => '',
        ];
    }

    public function settings()
    {
        $saved = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    /** The SDK client, caching in transients (so a persistent object cache is used when there is one). */
    public function client()
    {
        if ($this->client === null) {
            $settings = $this->settings();
            /**
             * Filters the SDK client options (base, userAgent, ttl, freeTlds).
             *
             * @param array $options
             */
            $options = apply_filters('tlders_dp_client_options', [
                'userAgent' => 'TLDers-WordPress/' . TLDERS_DP_VERSION . ' (' . home_url() . ')',
                'freeTlds' => $settings['free_tlds'],
            ]);
            $this->client = new Client($settings['api_key'], new TLDers_DP_Transport(), new TLDers_DP_TransientCache(), $options);
        }
        return $this->client;
    }

    public function links()
    {
        $settings = $this->settings();
        return new Links((array) $settings['links'], $settings['fallback'], self::SOURCE);
    }

    public function currency()
    {
        $s = $this->settings();
        return new Currency($s['currency_code'], $s['currency_symbol'], $s['currency_rate'], $s['decimals']);
    }

    /** Background refresh so visitors rarely wait on the bulk download. */
    public function refresh()
    {
        $client = $this->client();
        if ($client->plan() === 'paid' || $client->plan() === 'unknown') {
            $client->tlds();
        }
        foreach (Client::normalizeList($this->settings()['popular_tlds']) as $tld) {
            if ($client->canLookup($tld)) {
                $client->offers($tld);
            }
        }
    }

    public function clear_cache()
    {
        $this->client()->clearCache();
    }

    public function register_assets()
    {
        wp_register_style('tlders-dp', plugins_url('assets/tlders.css', TLDERS_DP_FILE), [], TLDERS_DP_VERSION);
    }

    // ── Mobile app API (/wp-json/tlders/v1/...) ─────────────────────────────

    public function register_rest_routes()
    {
        if (!$this->settings()['app_api']) {
            return;
        }
        $routes = [
            '/config' => 'config',
            '/search' => 'search',
            '/cheapest' => 'cheapest',
            '/tld/(?P<tld>[a-z0-9.-]{1,80})' => 'tld',
        ];
        foreach ($routes as $route => $name) {
            register_rest_route(self::REST_NAMESPACE, $route, [
                'methods' => 'GET',
                // Public: the same prices and buy links the site already shows.
                'permission_callback' => '__return_true',
                'callback' => function (WP_REST_Request $request) use ($name) {
                    $path = $name === 'tld' ? 'tld/' . $request['tld'] : $name;
                    list($status, $body) = $this->app_api()->handle($path, $request->get_query_params());
                    $response = new WP_REST_Response($body, $status);
                    $response->header('Cache-Control', 'public, max-age=3600');
                    return $response;
                },
            ]);
        }
    }

    public function app_api()
    {
        $s = $this->settings();
        // App users tap through from inside an app, so tag those clicks apart.
        $links = new Links((array) $s['links'], $s['fallback'], self::SOURCE . '-app');
        return new AppApi($this->client(), $links, $this->currency(), [
            'name' => $s['app_name'] !== '' ? $s['app_name'] : get_bloginfo('name'),
            'disclosure' => $s['disclosure'],
            'popularTlds' => Client::normalizeList($s['popular_tlds']),
        ]);
    }
}
