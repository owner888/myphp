<?php
// The phones' launchers turn FFI on as php_embed's INI defaults (ini_defaults): ini_entries set before php_embed_init
// are lost, as it sets them to its own HARDCODED_INI, and an iOS runtime built from source (its php_embed.c not
// patched) ran with FFI off: "FFI API is restricted by the ffi.enable configuration directive", a black screen.
declare(strict_types=1);

namespace MyPHP\Tests;

$root = dirname(__DIR__);
foreach (['iOS' => 'src/Platform/iOS/launcher/main.m', 'Android' => 'src/Platform/Android/launcher/myphp_launcher.c'] as $os => $file) {
    $src = (string)file_get_contents("$root/$file");
    check(!str_contains($src, 'php_embed_module.ini_entries'), "$os: no ini_entries, which php_embed_init replaces");
    check(str_contains($src, 'php_embed_module.ini_defaults = myphp_ini_defaults;'), "$os: the INI as php_embed's defaults");
    preg_match('/static void myphp_ini_defaults\(HashTable \*configuration_hash\)\s*\{\s*static const char \*const ini\[\]\[2\] = \{(.*?)\};/s', $src, $m);
    check(str_contains($m[1] ?? '', '{"ffi.enable", "1"}'), "$os: FFI on");
    $init = strpos($src, 'php_embed_init(');
    $set = strpos($src, 'php_embed_module.ini_defaults =');
    check($set !== false && $init !== false && $set < $init, "$os: set before php_embed_init");
}
same(['512M'], (function () use ($root) { preg_match('/\{"memory_limit", "([^"]+)"\}/', (string)file_get_contents("$root/src/Platform/iOS/launcher/main.m"), $m); return [$m[1] ?? null]; })(), 'iOS: memory_limit 512M, as the launcher set and lost too');
check(preg_match('/build-ios-php\.sh"\n(#[^\n]*\n)*if ! xcrun nm -g "\$\{BUILD_DIR\}\/libs\/libphp\.a" [^\n]*\' _ffi_module_entry\$\'/', (string)file_get_contents("$root/scripts/package-ios-app.sh")) === 1,
    'package-ios-app.sh refuses a runtime without ext-ffi');
foreach (['build-android-php.sh', 'build-ios-php.sh'] as $script) {
    check(!str_contains((string)file_get_contents("$root/scripts/$script"), 'php_embed.c'), "$script leaves php_embed.c as PHP has it: the launchers set the INI");
}
