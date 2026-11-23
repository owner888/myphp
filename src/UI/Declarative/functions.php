<?php
// Idempotent loader shim so Composer's `require` ("autoload.files") and
// `src/autoload.php` (`require_once`) share PHP 8.4 polyfills and never
// redeclare global enums/functions at compile time.
declare(strict_types=1);

if (!function_exists('array_first')) {
    require __DIR__ . '/polyfills.php';
}
if (!extension_loaded('intl')) {
    require_once dirname(__DIR__, 2) . '/Native/LocalePolyfill.php';
}
if (!function_exists('VStack')) {
    require __DIR__ . '/declarations.php';
}
