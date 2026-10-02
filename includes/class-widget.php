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
    public static function labels()
    {
        return [
            'search' => __('Domain search box', 'tlders-domain-prices'),
            'cheapest' => __('Cheapest price per extension', 'tlders-domain-prices'),
            'table' => __('Price table for one extension', 'tlders-domain-prices'),
            'price' => __('Cheapest price for one extension (single line)', 'tlders-domain-prices'),
        ];
    }

    /**
     * @param string $view   search | cheapest | table | price
     * @param array  $opts   tld, tlds, limit, page (results page ID for search)
     */
    public static function render($view, array $opts)
    {
        $tld = isset($opts['tld']) && $opts['tld'] !== '' ? $opts['tld'] : 'com';
        $limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 5;
        switch ($view) {
            case 'search':
                return TLDers_DP_Shortcodes::search(['page' => isset($opts['page']) ? (int) $opts['page'] : 0, 'limit' => $limit]);
            case 'table':
                return TLDers_DP_Shortcodes::table(['tld' => $tld, 'limit' => $limit, 'show' => 'register,renew']);
            case 'price':
                return TLDers_DP_Shortcodes::price(['tld' => $tld]);
            case 'cheapest':
            default:
                $atts = isset($opts['tlds']) && trim($opts['tlds']) !== '' ? ['tlds' => $opts['tlds']] : [];
                return TLDers_DP_Shortcodes::cheapest($atts);
        }
    }
}

/** Appearance → Widgets: "TLDers Domain Prices" for classic-theme sidebars. */
class TLDers_DP_Widget extends WP_Widget
{
    const DEFAULTS = ['title' => '', 'view' => 'cheapest', 'tld' => 'com', 'tlds' => '', 'limit' => 5, 'page' => 0];

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
        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup
        $title = apply_filters('widget_title', $instance['title'], $instance, $this->id_base);
        if ($title !== '') {
            echo $args['before_title'] . esc_html($title) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderers
        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
        ];
    }

    public function form($instance)
    {
        $i = array_merge(self::DEFAULTS, (array) $instance);
        $id = function ($key) {
            return esc_attr($this->get_field_id($key));
        };
        $name = function ($key) {
            return esc_attr($this->get_field_name($key));
        };
        ?>
        <p>
            <label for="<?php echo $id('title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e('Title:', 'tlders-domain-prices'); ?></label>
            <input class="widefat" id="<?php echo $id('title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" name="<?php echo $name('title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" type="text" value="<?php echo esc_attr($i['title']); ?>">
        </p>
        <p>
            <label for="<?php echo $id('view'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e('Show:', 'tlders-domain-prices'); ?></label>
            <select class="widefat" id="<?php echo $id('view'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" name="<?php echo $name('view'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
                <?php foreach (TLDers_DP_Views::labels() as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($i['view'], $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo $id('tld'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e('Extension (table and single price):', 'tlders-domain-prices'); ?></label>
            <input class="widefat" id="<?php echo $id('tld'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" name="<?php echo $name('tld'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" type="text" value="<?php echo esc_attr($i['tld']); ?>" placeholder="com">
        </p>
        <p>
            <label for="<?php echo $id('tlds'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e('Extensions (cheapest list):', 'tlders-domain-prices'); ?></label>
            <input class="widefat" id="<?php echo $id('tlds'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" name="<?php echo $name('tlds'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" type="text" value="<?php echo esc_attr($i['tlds']); ?>" placeholder="<?php esc_attr_e('Empty = your popular TLDs', 'tlders-domain-prices'); ?>">
        </p>
        <p>
            <label for="<?php echo $id('limit'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e('Registrars to show (table):', 'tlders-domain-prices'); ?></label>
            <input class="tiny-text" id="<?php echo $id('limit'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" name="<?php echo $name('limit'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" type="number" min="1" max="50" value="<?php echo esc_attr($i['limit']); ?>">
        </p>
        <p>
            <label for="<?php echo $id('page'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e('Search results page (search box):', 'tlders-domain-prices'); ?></label>
            <?php
            wp_dropdown_pages([
                'id' => $this->get_field_id('page'),
                'name' => $this->get_field_name('page'),
                'selected' => (int) $i['page'],
                'show_option_none' => __('— Same page —', 'tlders-domain-prices'),
                'option_none_value' => '0',
                'class' => 'widefat',
            ]);
            ?>
            <small><?php esc_html_e('In a sidebar, pick a page that contains [tlders_search] (or the Domain Prices block set to "Domain search box") so results get the full width.', 'tlders-domain-prices'); ?></small>
        </p>
        <?php
    }
}
