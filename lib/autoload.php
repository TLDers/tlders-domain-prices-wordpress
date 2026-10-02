<?php
// TLDers PHP SDK — no Composer needed: require this file.
// Guarded so a site running both the WordPress plugin and another copy
// doesn't fatal on a redeclared class.
if (!class_exists('TLDers\\Sdk\\Client', false)) {
    require __DIR__ . '/src/Transport.php';
    require __DIR__ . '/src/Cache.php';
    require __DIR__ . '/src/Client.php';
    require __DIR__ . '/src/Links.php';
    require __DIR__ . '/src/AppApi.php';
}
