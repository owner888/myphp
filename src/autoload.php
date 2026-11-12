<?php
// PSR-4 autoloader for MyPHP\ without Composer: require this file.
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'MyPHP\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// PHP 8.5 array_first / array_last polyfills when running on PHP 8.4:
if (!function_exists('array_first')) {
    function array_first(array $array): mixed { return $array === [] ? null : $array[array_key_first($array)]; }
}
if (!function_exists('array_last')) {
    function array_last(array $array): mixed { return $array === [] ? null : $array[array_key_last($array)]; }
}

// When ext-intl is not loaded (iOS, Android, or a lightweight desktop PHP / micro runtime without ICU),
// load the built-in Locale / IntlDateFormatter / NumberFormatter polyfill.
if (!extension_loaded('intl')) {
    require_once __DIR__ . '/Native/LocalePolyfill.php';
}

// The declarative views, global as SwiftUI's: VStack, Text, Button, Color, Font, NavigationPath… (docs/swiftui.md)
require_once __DIR__ . '/UI/Declarative/functions.php';
