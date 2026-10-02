<?php
/**
 * Plugin Name:       TLDers Domain Prices
 * Plugin URI:        https://github.com/TLDers/tlders-domain-prices-wordpress
 * Description:       Domain price comparison tables, cheapest-price tags and a domain search box, as shortcodes, a block and a sidebar widget, with your own registrar affiliate links. Prices from TLDers (145+ registrars, refreshed daily).
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            TLDers
 * Author URI:        https://www.tlders.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tlders-domain-prices
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TLDERS_DP_VERSION', '1.0.0');
define('TLDERS_DP_FILE', __FILE__);
define('TLDERS_DP_DIR', plugin_dir_path(__FILE__));

require TLDERS_DP_DIR . 'lib/autoload.php';
require TLDERS_DP_DIR . 'includes/class-wp-adapters.php';
require TLDERS_DP_DIR . 'includes/class-plugin.php';
require TLDERS_DP_DIR . 'includes/class-shortcodes.php';
require TLDERS_DP_DIR . 'includes/class-widget.php';
require TLDERS_DP_DIR . 'includes/class-block.php';
require TLDERS_DP_DIR . 'includes/class-admin.php';

TLDers_DP_Plugin::instance()->boot();

register_activation_hook(__FILE__, ['TLDers_DP_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['TLDers_DP_Plugin', 'deactivate']);
