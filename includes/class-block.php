<?php

if (!defined('ABSPATH')) {
    exit;
}

/** The "Domain Prices" block, for posts, pages and block-theme widget areas. */
class TLDers_DP_Block
{
    public static function register()
    {
        wp_register_script(
            'tlders-dp-block',
            plugins_url('assets/block.js', TLDERS_DP_FILE),
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n'],
            TLDERS_DP_VERSION,
            true
        );

        register_block_type('tlders/domain-prices', [
            'api_version' => 3,
            'title' => __('Domain Prices', 'tlders-domain-prices'),
            'description' => __('Domain prices or a domain search box, with your affiliate links.', 'tlders-domain-prices'),
            'category' => 'widgets',
            'icon' => 'money-alt',
            'keywords' => ['domain', 'tld', 'registrar', 'price', 'tlders'],
            'supports' => ['html' => false, 'align' => ['wide', 'full']],
            'editor_script' => 'tlders-dp-block',
            'style' => 'tlders-dp',
            'attributes' => [
                'view' => ['type' => 'string', 'default' => 'cheapest'],
                'tld' => ['type' => 'string', 'default' => 'com'],
                'tlds' => ['type' => 'string', 'default' => ''],
                'limit' => ['type' => 'integer', 'default' => 10],
                'page' => ['type' => 'integer', 'default' => 0],
            ],
            'render_callback' => [__CLASS__, 'render'],
        ]);
    }

    /** Options the editor script needs: the view labels and the site's pages. */
    public static function editor_data()
    {
        $pages = [];
        foreach (get_pages(['sort_column' => 'post_title', 'number' => 200]) as $page) {
            $pages[] = ['id' => $page->ID, 'title' => $page->post_title !== '' ? $page->post_title : '#' . $page->ID];
        }
        wp_add_inline_script(
            'tlders-dp-block',
            'window.tldersDpBlock = ' . wp_json_encode(['views' => TLDers_DP_Views::labels(), 'pages' => $pages]) . ';',
            'before'
        );
    }

    public static function render($attributes)
    {
        $views = TLDers_DP_Views::labels();
        $view = isset($attributes['view'], $views[$attributes['view']]) ? $attributes['view'] : 'cheapest';
        $html = TLDers_DP_Views::render($view, [
            'tld' => TLDers\Sdk\Client::normalizeTld(isset($attributes['tld']) ? $attributes['tld'] : ''),
            'tlds' => isset($attributes['tlds']) ? (string) $attributes['tlds'] : '',
            'limit' => isset($attributes['limit']) ? (int) $attributes['limit'] : 10,
            'page' => isset($attributes['page']) ? (int) $attributes['page'] : 0,
        ]);
        if ($html === '') {
            return '';
        }
        return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
    }
}
