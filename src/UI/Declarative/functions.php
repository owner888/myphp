<?php
// Idempotent loader shim so Composer's `require` ("autoload.files") and
// `src/autoload.php` (`require_once`) never redeclare global enums/functions
// at compile time when both `./myphp` and `vendor/owner888/myphp` are present.
declare(strict_types=1);

if (!function_exists('VStack')) {
    require __DIR__ . '/declarations.php';
}
