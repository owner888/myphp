<?php
// A stock Android's SELinux policy (enforcing) denies fcntl() on the memfd opcache locks with by default: "Cannot create
// lock - Permission denied", the app dies at its first line, and a permissive phone only logs a denial. PHP is built
// without memfd_create, and opcache locks a file in the app's cache folder (the launcher sets opcache.lockfile_path).
declare(strict_types=1);

namespace MyPHP\Tests;

$root = dirname(__DIR__);
$script = (string)file_get_contents("$root/scripts/build-android-php.sh");
check(str_contains($script, "s/#define HAVE_MEMFD_CREATE 1/"), 'PHP is built without memfd_create');
$launcher = (string)file_get_contents("$root/src/Platform/Android/launcher/myphp_launcher.c");
check(str_contains($launcher, '{"opcache.lockfile_path", g_cache_dir}'), 'opcache locks a file in the app\'s cache folder');
check(str_contains($launcher, '{"opcache.enable", "1"}'), 'opcache is still on');
$cfg = "$root/build/android/php-" . (getenv('MYPHP_PHONE_PHP_VERSION') ?: '8.5.12') . '/main/php_config.h';
if (is_file($cfg)) {
    check(preg_match('/^#define HAVE_MEMFD_CREATE/m', (string)file_get_contents($cfg)) === 0, 'the PHP built here has no memfd_create');
}
