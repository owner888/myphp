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

// The declarative views, global as SwiftUI's: VStack, Text, Button, Color, Font, NavigationPath… (docs/declarative.md)
require_once __DIR__ . '/UI/Declarative/functions.php';
