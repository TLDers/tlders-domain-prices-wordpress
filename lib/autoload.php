<?php
// TLDers PHP SDK — no Composer needed: require this file.
// Outside WordPress, define('TLDERS_SDK', true) before requiring it (direct
// web access to these files does nothing). Guarded so a site running two
// bundled copies doesn't fatal on a redeclared class.
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('TLDers\\Sdk\\Client', false)) {
    require __DIR__ . '/src/Transport.php';
    require __DIR__ . '/src/Cache.php';
    require __DIR__ . '/src/Client.php';
    require __DIR__ . '/src/Links.php';
    require __DIR__ . '/src/AppApi.php';
    // Not bundled in the WordPress plugin, which uses wp_remote_get and transients instead.
    foreach (['CurlTransport', 'FileCache'] as $tlders_sdk_optional) {
        if (is_file(__DIR__ . '/src/' . $tlders_sdk_optional . '.php')) {
            require __DIR__ . '/src/' . $tlders_sdk_optional . '.php';
        }
    }
}
