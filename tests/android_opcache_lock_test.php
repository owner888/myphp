<?php
// A stock Android's SELinux policy (enforcing) denies fcntl() on the memfd opcache locks with by default: "Cannot create
// lock - Permission denied", the app dies at its first line, and a permissive phone only logs a denial. PHP is built
// without memfd_create, and opcache locks a file in the app's cache folder (the launcher sets opcache.lockfile_path).
declare(strict_types=1);

namespace MyPHP\Tests;

$root = dirname(__DIR__);
$script = (string)file_get_contents("$root/scripts/build-android-php.sh");
check(str_contains($script, "s/#define HAVE_MEMFD_CREATE 1/") && str_contains($script, 'ac_cv_func_memfd_create=no') && str_contains($script, 'opcache_uses_memfd'), 'PHP is built without memfd_create and stale memfd libphp.so binaries are replaced');
$launcher = (string)file_get_contents("$root/src/Platform/Android/launcher/myphp_launcher.c");
check(str_contains($launcher, '{"opcache.lockfile_path", g_cache_dir}'), 'opcache locks a file in the app\'s cache folder');
check(str_contains($launcher, '{"opcache.enable", "1"}'), 'opcache is still on');
// What the archive CI publishes holds is checked in CI itself (the phone-runtimes workflow reads the php_config.h in it).
