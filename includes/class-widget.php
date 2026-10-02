<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the widget and the block can show. Each view is one of the shortcodes,
 * so all three render identically.
 */
class TLDers_DP_Views
{
    /** Skin names for the Settings screen, the widget and the block. */
    public static function skins()
    {
        return [
            'theme' => __('Theme (blends into your site)', 'tlders-domain-prices'),
            'aurora' => __('Aurora (indigo to pink)', 'tlders-domain-prices'),
            'midnight' => __('Midnight (dark, neon)', 'tlders-domain-prices'),
            'fresh' => __('Fresh (mint and teal)', 'tlders-domain-prices'),
            'sunset' => __('Sunset (orange to pink)', 'tlders-domain-prices'),
            'minimal' => __('Minimal (clean, blue)', 'tlders-domain-prices'),
        ];
    }

    public static function labels()
    {
        return [
            'search' => __('Domain search box', 'tlders-domain-prices'),
            'cheapest' => __('Cheapest price per extension', 'tlders-domain-prices'),
            'table' => __('Price table for one extension', 'tlders-domain-prices'),
            'price' => __('Cheapest price for one extension (single line)', 'tlders-domain-prices'),
        ];
    }

    /** Post HTML plus the search form's tags, for escaping rendered views. */
    public static function allowed_html()
    {
        $allowed = wp_kses_allowed_html('post');
        $allowed['form'] = ['class' => true, 'method' => true, 'action' => true, 'role' => true];
        $allowed['input'] = [
            'type' => true, 'name' => true, 'value' => true, 'placeholder' => true,
            'aria-label' => true, 'maxlength' => true, 'required' => true, 'class' => true,
        ];
        $allowed['button'] = ['type' => true, 'class' => true];
        $allowed['span']['style'] = true;
        $allowed['i'] = ['style' => true];
        return $allowed;
    }

    /**
     * @param string $view   search | cheapest | table | price
     * @param array  $opts   tld, tlds, limit, page (results page ID for search)
     */
    public static function render($view, array $opts)
    {
        $tld = isset($opts['tld']) && $opts['tld'] !== '' ? $opts['tld'] : 'com';
        $limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 5;
        $skin = isset($opts['skin']) ? (string) $opts['skin'] : '';
        switch ($view) {
            case 'search':
                return TLDers_DP_Shortcodes::search(['page' => isset($opts['page']) ? (int) $opts['page'] : 0, 'limit' => $limit, 'skin' => $skin]);
            case 'table':
                return TLDers_DP_Shortcodes::table(['tld' => $tld, 'limit' => $limit, 'show' => 'register,renew', 'skin' => $skin]);
            case 'price':
                return TLDers_DP_Shortcodes::price(['tld' => $tld]);
            case 'cheapest':
            default:
                $atts = isset($opts['tlds']) && trim($opts['tlds']) !== '' ? ['tlds' => $opts['tlds']] : [];
                return TLDers_DP_Shortcodes::cheapest($atts + ['skin' => $skin]);
        }
    }
}

/** Appearance → Widgets: "TLDers Domain Prices" for classic-theme sidebars. */
class TLDers_DP_Widget extends WP_Widget
{
    const DEFAULTS = ['title' => '', 'view' => 'cheapest', 'tld' => 'com', 'tlds' => '', 'limit' => 5, 'page' => 0, 'skin' => ''];

    public function __construct()
    {
        parent::__construct('tlders_dp_widget', __('TLDers Domain Prices', 'tlders-domain-prices'), [
            'description' => __('Domain prices or a domain search box, with your affiliate links.', 'tlders-domain-prices'),
            'customize_selective_refresh' => true,
        ]);
    }

    public static function register()
    {
        register_widget(__CLASS__);
    }

    public function widget($args, $instance)
    {
        $instance = array_merge(self::DEFAULTS, (array) $instance);
        $html = TLDers_DP_Views::render($instance['view'], $instance);
        if ($html === '') {
            return; // nothing to show (e.g. prices unavailable): skip the empty box
        }
        echo wp_kses_post($args['before_widget']);
        $title = apply_filters('widget_title', $instance['title'], $instance, $this->id_base);
        if ($title !== '') {
            echo wp_kses_post($args['before_title']) . esc_html($title) . wp_kses_post($args['after_title']);
        }
        echo wp_kses($html, TLDers_DP_Views::allowed_html());
        echo wp_kses_post($args['after_widget']);
    }

    public function update($new, $old)
    {
        $views = TLDers_DP_Views::labels();
        return [
            'title' => sanitize_text_field(isset($new['title']) ? $new['title'] : ''),
            'view' => isset($new['view'], $views[$new['view']]) ? $new['view'] : 'cheapest',
            'tld' => TLDers\Sdk\Client::normalizeTld(isset($new['tld']) ? $new['tld'] : '') ?: 'com',
            'tlds' => implode(', ', TLDers\Sdk\Client::normalizeList(isset($new['tlds']) ? $new['tlds'] : '')),
            'limit' => max(1, min(50, isset($new['limit']) ? (int) $new['limit'] : 5)),
            'page' => isset($new['page']) ? absint($new['page']) : 0,
            'skin' => isset($new['skin'], TLDers_DP_Views::skins()[$new['skin']]) ? $new['skin'] : '',
        ];
    }

    public function form($instance)
    {
        $i = array_merge(self::DEFAULTS, (array) $instance);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'tlders-domain-prices'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($i['title']); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('view')); ?>"><?php esc_html_e('Show:', 'tlders-domain-prices'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('view')); ?>" name="<?php echo esc_attr($this->get_field_name('view')); ?>">
                <?php foreach (TLDers_DP_Views::labels() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($i['view'], $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('skin')); ?>"><?php esc_html_e('Skin:', 'tlders-domain-prices'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('skin')); ?>" name="<?php echo esc_attr($this->get_field_name('skin')); ?>">
                <option value=""><?php esc_html_e('— Site default (Settings) —', 'tlders-domain-prices'); ?></option>
                <?php foreach (TLDers_DP_Views::skins() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($i['skin'], $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('tld')); ?>"><?php esc_html_e('Extension (table and single price):', 'tlders-domain-prices'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('tld')); ?>" name="<?php echo esc_attr($this->get_field_name('tld')); ?>" type="text" value="<?php echo esc_attr($i['tld']); ?>" placeholder="com">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('tlds')); ?>"><?php esc_html_e('Extensions (cheapest list):', 'tlders-domain-prices'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('tlds')); ?>" name="<?php echo esc_attr($this->get_field_name('tlds')); ?>" type="text" value="<?php echo esc_attr($i['tlds']); ?>" placeholder="<?php esc_attr_e('Empty = your popular TLDs', 'tlders-domain-prices'); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('limit')); ?>"><?php esc_html_e('Registrars to show (table):', 'tlders-domain-prices'); ?></label>
            <input class="tiny-text" id="<?php echo esc_attr($this->get_field_id('limit')); ?>" name="<?php echo esc_attr($this->get_field_name('limit')); ?>" type="number" min="1" max="50" value="<?php echo esc_attr($i['limit']); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('page')); ?>"><?php esc_html_e('Search results page (search box):', 'tlders-domain-prices'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('page')); ?>" name="<?php echo esc_attr($this->get_field_name('page')); ?>">
                <option value="0"><?php esc_html_e('— Same page —', 'tlders-domain-prices'); ?></option>
                <?php foreach (get_pages(['sort_column' => 'post_title']) as $tlders_dp_page) : ?>
                    <option value="<?php echo esc_attr($tlders_dp_page->ID); ?>" <?php selected((int) $i['page'], $tlders_dp_page->ID); ?>><?php echo esc_html($tlders_dp_page->post_title !== '' ? $tlders_dp_page->post_title : '#' . $tlders_dp_page->ID); ?></option>
                <?php endforeach; ?>
            </select>
            <small><?php esc_html_e('In a sidebar, pick a page that contains [tlders_search] (or the Domain Prices block set to "Domain search box") so results get the full width.', 'tlders-domain-prices'); ?></small>
        </p>
        <?php
    }
}
