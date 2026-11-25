<?php

if (!defined('ABSPATH')) {
    exit;
}

use TLDers\Sdk\Client;
use TLDers\Sdk\Links;

/** Settings → TLDers Domain Prices. */
class TLDers_DP_Admin
{
    const PAGE = 'tlders-domain-prices';

    /** @var TLDers_DP_Plugin */
    private $plugin;

    public function __construct(TLDers_DP_Plugin $plugin)
    {
        $this->plugin = $plugin;
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register']);
        add_action('admin_post_tlders_dp_clear_cache', [$this, 'clear_cache']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_filter('plugin_action_links_' . plugin_basename(TLDERS_DP_FILE), [$this, 'action_links']);
    }

    public function menu()
    {
        add_options_page(
            __('TLDers Domain Prices', 'tlders-domain-prices'),
            __('TLDers Domain Prices', 'tlders-domain-prices'),
            'manage_options',
            self::PAGE,
            [$this, 'render']
        );
    }

    public function action_links($links)
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=' . self::PAGE)) . '">' . esc_html__('Settings', 'tlders-domain-prices') . '</a>');
        return $links;
    }

    public function assets($hook)
    {
        if ($hook !== 'settings_page_' . self::PAGE) {
            return;
        }
        wp_enqueue_style('tlders-dp-admin', plugins_url('assets/admin.css', TLDERS_DP_FILE), [], TLDERS_DP_VERSION);
        wp_enqueue_script('tlders-dp-admin', plugins_url('assets/admin.js', TLDERS_DP_FILE), [], TLDERS_DP_VERSION, true);
    }

    public function register()
    {
        register_setting('tlders_dp', TLDers_DP_Plugin::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => TLDers_DP_Plugin::defaults(),
        ]);
    }

    public function sanitize($input)
    {
        $old = $this->plugin->settings();
        $input = is_array($input) ? $input : [];
        $out = TLDers_DP_Plugin::defaults();

        $out['api_key'] = sanitize_text_field(isset($input['api_key']) ? $input['api_key'] : '');
        $out['links'] = [];
        foreach ((isset($input['links']) && is_array($input['links']) ? $input['links'] : []) as $slug => $url) {
            $slug = sanitize_key($slug);
            $url = trim((string) $url); // options.php has already unslashed it
            if ($url === '') {
                continue;
            }
            if (!Links::isHttpUrl($url)) {
                add_settings_error(TLDers_DP_Plugin::OPTION, 'bad-link-' . $slug, sprintf(
                    /* translators: %s: registrar slug */
                    __('The affiliate link for %s must start with http:// or https:// and was not saved.', 'tlders-domain-prices'),
                    $slug
                ));
                continue;
            }
            // Not esc_url_raw(): it would mangle the {domain} placeholders.
            $out['links'][$slug] = $url;
        }
        $out['fallback'] = (isset($input['fallback']) && $input['fallback'] === Links::FALLBACK_HIDE) ? Links::FALLBACK_HIDE : Links::FALLBACK_TLDERS;
        $out['currency_code'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', isset($input['currency_code']) ? $input['currency_code'] : 'USD'), 0, 3)) ?: 'USD';
        $out['currency_symbol'] = sanitize_text_field(isset($input['currency_symbol']) ? $input['currency_symbol'] : '$');
        $rate = isset($input['currency_rate']) ? (float) $input['currency_rate'] : 1;
        $out['currency_rate'] = $rate > 0 ? $rate : 1;
        $out['decimals'] = max(0, min(3, isset($input['decimals']) ? (int) $input['decimals'] : 2));
        $out['free_tlds'] = implode(', ', Client::normalizeList(isset($input['free_tlds']) ? $input['free_tlds'] : '')) ?: implode(', ', Client::DEFAULT_FREE_TLDS);
        $out['popular_tlds'] = implode(', ', Client::normalizeList(isset($input['popular_tlds']) ? $input['popular_tlds'] : '')) ?: TLDers_DP_Plugin::defaults()['popular_tlds'];
        $out['skin'] = isset($input['skin'], TLDers_DP_Views::skins()[$input['skin']]) ? $input['skin'] : 'theme';
        foreach (['new_tab', 'show_disclosure', 'credit', 'app_api'] as $flag) {
            $out[$flag] = empty($input[$flag]) ? 0 : 1;
        }
        $out['disclosure'] = sanitize_textarea_field(isset($input['disclosure']) ? $input['disclosure'] : '');
        $out['app_name'] = sanitize_text_field(isset($input['app_name']) ? $input['app_name'] : '');

        // (A new API key resets the cache by itself — see Client.) A shorter
        // free list should stop serving TLDs that were dropped from it.
        if ($out['free_tlds'] !== $old['free_tlds']) {
            $this->plugin->clear_cache();
        }
        return $out;
    }

    public function clear_cache()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'tlders-domain-prices'));
        }
        check_admin_referer('tlders_dp_clear_cache');
        $this->plugin->clear_cache();
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'tlders_cleared' => 1], admin_url('options-general.php')));
        exit;
    }

    public function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = $this->plugin->settings();
        $client = $this->plugin->client();
        $name = TLDers_DP_Plugin::OPTION;
        $registrars = $client->registrars();
        $field = function ($key) use ($name) {
            return $name . '[' . $key . ']';
        };
        $checkbox = function ($key, $label) use ($s, $field) {
            echo '<label><input type="checkbox" name="' . esc_attr($field($key)) . '" value="1"' . checked(1, $s[$key], false) . '> ' . esc_html($label) . '</label>';
        };
        ?>
        <div class="wrap tlders-admin">
            <h1><?php esc_html_e('TLDers Domain Prices', 'tlders-domain-prices'); ?></h1>
            <?php settings_errors($name); ?>
            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (isset($_GET['tlders_cleared'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Price cache cleared.', 'tlders-domain-prices'); ?></p></div>
            <?php endif; ?>

            <?php $this->render_status($client, $s); ?>

            <form method="post" action="options.php">
                <?php settings_fields('tlders_dp'); ?>

                <h2><?php esc_html_e('Connection', 'tlders-domain-prices'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="tlders-api-key"><?php esc_html_e('TLDers API key', 'tlders-domain-prices'); ?></label></th>
                        <td>
                            <input id="tlders-api-key" type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr($field('api_key')); ?>" value="<?php echo esc_attr($s['api_key']); ?>">
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: link to tlders.com/developers */
                                    esc_html__('Free keys at %s. A free key covers the TLDs listed below; a paid key covers every TLD and refreshes all prices in one call.', 'tlders-domain-prices'),
                                    '<a href="https://www.tlders.com/developers" target="_blank" rel="noopener">tlders.com/developers</a>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tlders-free-tlds"><?php esc_html_e('TLDs on a free key', 'tlders-domain-prices'); ?></label></th>
                        <td>
                            <input id="tlders-free-tlds" type="text" class="large-text" name="<?php echo esc_attr($field('free_tlds')); ?>" value="<?php echo esc_attr($s['free_tlds']); ?>">
                            <p class="description"><?php esc_html_e('Each TLD uses about one request a day, and free keys get 100 a month. Keep this list to about 3 TLDs on a free key. Ignored on a paid key.', 'tlders-domain-prices'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Display', 'tlders-domain-prices'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Skin', 'tlders-domain-prices'); ?></th>
                        <td>
                            <div class="tlders-skin-picker">
                                <?php foreach (TLDers_DP_Views::skins() as $value => $label) : ?>
                                    <label class="tlders-skin-option tlders-swatch-<?php echo esc_attr($value); ?>">
                                        <input type="radio" name="<?php echo esc_attr($field('skin')); ?>" value="<?php echo esc_attr($value); ?>" <?php checked($s['skin'], $value); ?>>
                                        <span class="tlders-swatch"></span>
                                        <span class="tlders-skin-name"><?php echo esc_html($label); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description"><?php esc_html_e('The look of every price table, card list and search box. Each block, widget or shortcode can override it (skin="midnight"). The mobile app follows it too.', 'tlders-domain-prices'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Currency', 'tlders-domain-prices'); ?></th>
                        <td class="tlders-currency">
                            <label><?php esc_html_e('Code', 'tlders-domain-prices'); ?> <input type="text" size="4" maxlength="3" name="<?php echo esc_attr($field('currency_code')); ?>" value="<?php echo esc_attr($s['currency_code']); ?>"></label>
                            <label><?php esc_html_e('Symbol', 'tlders-domain-prices'); ?> <input type="text" size="4" name="<?php echo esc_attr($field('currency_symbol')); ?>" value="<?php echo esc_attr($s['currency_symbol']); ?>"></label>
                            <label><?php esc_html_e('1 USD =', 'tlders-domain-prices'); ?> <input type="number" step="any" min="0" name="<?php echo esc_attr($field('currency_rate')); ?>" value="<?php echo esc_attr($s['currency_rate']); ?>"></label>
                            <label><?php esc_html_e('Decimals', 'tlders-domain-prices'); ?> <input type="number" min="0" max="3" name="<?php echo esc_attr($field('decimals')); ?>" value="<?php echo esc_attr($s['decimals']); ?>"></label>
                            <p class="description"><?php esc_html_e('TLDers prices are in USD. For INR, set code INR, symbol ₹, your rate (e.g. 84) and 0 decimals.', 'tlders-domain-prices'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tlders-popular"><?php esc_html_e('Popular TLDs', 'tlders-domain-prices'); ?></label></th>
                        <td><input id="tlders-popular" type="text" class="large-text" name="<?php echo esc_attr($field('popular_tlds')); ?>" value="<?php echo esc_attr($s['popular_tlds']); ?>">
                            <p class="description"><?php esc_html_e('Used by [tlders_cheapest] without a tlds="" attribute, and by the mobile app home screen.', 'tlders-domain-prices'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Links', 'tlders-domain-prices'); ?></th>
                        <td><?php $checkbox('new_tab', __('Open buy links in a new tab', 'tlders-domain-prices')); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tlders-disclosure"><?php esc_html_e('Affiliate disclosure', 'tlders-domain-prices'); ?></label></th>
                        <td>
                            <?php $checkbox('show_disclosure', __('Show this under price tables and search results (once per page)', 'tlders-domain-prices')); ?>
                            <textarea id="tlders-disclosure" class="large-text" rows="2" name="<?php echo esc_attr($field('disclosure')); ?>"><?php echo esc_textarea($s['disclosure']); ?></textarea>
                            <p class="description"><?php esc_html_e('Most affiliate programs and advertising rules require a clear disclosure.', 'tlders-domain-prices'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Credit', 'tlders-domain-prices'); ?></th>
                        <td><?php $checkbox('credit', __('Show a small "Prices by TLDers" link under tables (optional)', 'tlders-domain-prices')); ?></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Your affiliate links', 'tlders-domain-prices'); ?></h2>
                <p>
                    <?php esc_html_e('Paste the link each registrar\'s affiliate program gives you. Use {domain} where the searched domain goes (example.com), or {sld} and {tld} for its parts. Registrars you leave empty use the option below.', 'tlders-domain-prices'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Registrars without your link', 'tlders-domain-prices'); ?></th>
                        <td>
                            <label><input type="radio" name="<?php echo esc_attr($field('fallback')); ?>" value="tlders" <?php checked($s['fallback'], 'tlders'); ?>> <?php esc_html_e('Show them, linking through tlders.com (TLDers may earn a commission on these)', 'tlders-domain-prices'); ?></label><br>
                            <label><input type="radio" name="<?php echo esc_attr($field('fallback')); ?>" value="hide" <?php checked($s['fallback'], 'hide'); ?>> <?php esc_html_e('Hide them: only show registrars I have a link for', 'tlders-domain-prices'); ?></label>
                        </td>
                    </tr>
                </table>
                <?php if (!$registrars) : ?>
                    <p class="notice notice-warning inline"><?php esc_html_e('Could not load the registrar list from TLDers. Reload the page to try again.', 'tlders-domain-prices'); ?></p>
                <?php else : ?>
                    <p><input type="search" id="tlders-registrar-filter" class="regular-text" placeholder="<?php esc_attr_e('Filter registrars…', 'tlders-domain-prices'); ?>">
                        <label><input type="checkbox" id="tlders-registrar-mine" <?php checked(!empty($s['links'])); ?>> <?php
                            /* translators: %d: number of registrars with the owner's link */
                            echo esc_html(sprintf(__('Only ones with my link (%d)', 'tlders-domain-prices'), count($s['links'])));
                        ?></label></p>
                    <table class="widefat striped tlders-links" id="tlders-registrar-links">
                        <thead><tr>
                            <th><?php esc_html_e('Registrar', 'tlders-domain-prices'); ?></th>
                            <th><?php esc_html_e('Your affiliate link', 'tlders-domain-prices'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($registrars as $slug => $r) :
                            $value = isset($s['links'][$slug]) ? $s['links'][$slug] : '';
                            ?>
                            <tr data-name="<?php echo esc_attr(strtolower($r['name'] . ' ' . $slug)); ?>">
                                <td>
                                    <strong><?php echo esc_html($r['name']); ?></strong>
                                    <?php if ($r['affiliateNetwork'] !== '') : ?>
                                        <br><span class="description"><?php
                                        /* translators: %s: affiliate network name */
                                        echo esc_html(sprintf(__('Program: %s', 'tlders-domain-prices'), $r['affiliateNetwork']));
                                        ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="text" class="large-text code" name="<?php echo esc_attr($name . '[links][' . $slug . ']'); ?>" value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($r['searchUrl'] !== '' ? $r['searchUrl'] : 'https://…'); ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php // Keep links for registrars TLDers no longer lists, rather than dropping them on save. ?>
                    <?php foreach ($s['links'] as $slug => $value) :
                        if (isset($registrars[$slug])) {
                            continue;
                        } ?>
                        <input type="hidden" name="<?php echo esc_attr($name . '[links][' . $slug . ']'); ?>" value="<?php echo esc_attr($value); ?>">
                    <?php endforeach; ?>
                <?php endif; ?>

                <h2><?php esc_html_e('Mobile app API', 'tlders-domain-prices'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('App API', 'tlders-domain-prices'); ?></th>
                        <td>
                            <?php $checkbox('app_api', __('Serve prices to the TLDers white-label mobile app', 'tlders-domain-prices')); ?>
                            <p class="description"><?php
                                printf(
                                    /* translators: %s: REST URL */
                                    esc_html__('Set the app\'s API base to %s. Your API key stays on this server; the app only receives prices and your buy links.', 'tlders-domain-prices'),
                                    '<code>' . esc_html(rest_url(TLDers_DP_Plugin::REST_NAMESPACE)) . '</code>'
                                );
                            ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tlders-app-name"><?php esc_html_e('Name shown in the app', 'tlders-domain-prices'); ?></label></th>
                        <td><input id="tlders-app-name" type="text" class="regular-text" name="<?php echo esc_attr($field('app_name')); ?>" value="<?php echo esc_attr($s['app_name']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e('Blocks, widgets and shortcodes', 'tlders-domain-prices'); ?></h2>
            <p><?php esc_html_e('In the block editor, add the "Domain Prices" block. In classic themes, add the "TLDers Domain Prices" widget under Appearance → Widgets. Or use these shortcodes anywhere:', 'tlders-domain-prices'); ?></p>
            <table class="widefat striped tlders-help">
                <tbody>
                <tr><td><code>[tlders_price tld="com"]</code></td><td><?php esc_html_e('Cheapest price with a link, e.g. "$9.58 at Namecheap". Options: type="renew", registrar="porkbun", show_registrar="no", link="no".', 'tlders-domain-prices'); ?></td></tr>
                <tr><td><code>[tlders_table tld="io" limit="10"]</code></td><td><?php esc_html_e('Registrar comparison table. Options: show="register,renew,transfer", domain="mybrand.io".', 'tlders-domain-prices'); ?></td></tr>
                <tr><td><code>[tlders_cheapest tlds="com,net,io"]</code></td><td><?php esc_html_e('Cheapest registrar for each TLD. Without tlds="" it uses your popular TLDs. Option: type="renew".', 'tlders-domain-prices'); ?></td></tr>
                <tr><td><code>[tlders_search page="12"]</code></td><td><?php esc_html_e('Search box only, sending visitors to page 12 (a page with a plain [tlders_search]) for results. Use it in sidebars and headers.', 'tlders-domain-prices'); ?></td></tr>
                <tr><td><code>skin="aurora"</code></td><td><?php esc_html_e('Any shortcode: theme, aurora, midnight, fresh, sunset or minimal. Overrides the skin chosen above.', 'tlders-domain-prices'); ?></td></tr>
                <tr><td><code>[tlders_search]</code></td><td><?php esc_html_e('A search box. Visitors type a domain and see where it is cheapest to register. Options: limit="10", show="register,renew".', 'tlders-domain-prices'); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_status(Client $client, array $s)
    {
        $plan = $client->plan();
        $labels = [
            'paid' => __('Paid key: every TLD, refreshed twice a day.', 'tlders-domain-prices'),
            'free' => __('Free key: only the TLDs listed under "TLDs on a free key".', 'tlders-domain-prices'),
            'unknown' => __('Not connected yet. Save an API key, then load a page with a shortcode.', 'tlders-domain-prices'),
        ];
        $own = count($s['links']);
        ?>
        <div class="tlders-status card">
            <p><strong><?php esc_html_e('Status:', 'tlders-domain-prices'); ?></strong> <?php echo esc_html($s['api_key'] === '' ? __('No API key yet.', 'tlders-domain-prices') : $labels[$plan]); ?></p>
            <p><?php
                /* translators: %d: number of registrars */
                echo esc_html(sprintf(_n('%d registrar uses your affiliate link.', '%d registrars use your affiliate link.', $own, 'tlders-domain-prices'), $own));
            ?></p>
            <?php if ($client->lastError()) : ?>
                <p class="tlders-error"><?php echo esc_html($client->lastError()); ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tlders_dp_clear_cache">
                <?php wp_nonce_field('tlders_dp_clear_cache'); ?>
                <?php submit_button(__('Clear price cache', 'tlders-domain-prices'), 'secondary small', 'submit', false); ?>
            </form>
        </div>
        <?php
    }
}
