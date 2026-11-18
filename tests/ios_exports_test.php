<?php
// What PHP's ext-ffi looks up in the iOS app's own process (FFI::cdef without a library) is in the list of symbols
// scripts/package-ios-app.sh exports: the launcher's C bridge (Native\iOS) and libcurl's API (Native\Curl). One
// that is not is gone from the app (-Wl,-dead_strip, strip -x), and every call to it fails at run time
// ("Failed resolving C function 'curl_easy_init'": no HTTP at all on iOS).
declare(strict_types=1);

namespace MyPHP\Tests;

$root = dirname(__DIR__);
$script = (string)file_get_contents("$root/scripts/package-ios-app.sh");
$main = (string)file_get_contents("$root/src/Platform/iOS/launcher/main.m");

// The list as the script makes it: the bridge's names grepped from main.m, then the patterns it adds.
preg_match_all('/myphp_ios_[a-zA-Z0-9_]+/', $main, $m);
$exported = array_map(fn(string $s) => "_$s", array_unique($m[0]));
preg_match_all('/^echo \'(_[^\']+)\' >> "\$\{EXPORT_LIST\}"$/m', $script, $m);
$patterns = $m[1];
check(str_contains($script, '-Wl,-exported_symbols_list,"${EXPORT_LIST}"'), 'the launcher is linked with the list');
$isExported = fn(string $symbol): bool => in_array($symbol, $exported, true) || array_filter($patterns, fn(string $p) => fnmatch($p, $symbol)) !== [];

// Every name the list takes from main.m is a function main.m defines, and not static: ld fails on an exported symbol the
// launcher does not have ("Undefined symbols: _myphp_ios_line", a static function named as the bridge's). A function of
// the launcher's own is named otherwise (myphp_log_line).
$undefined = array_values(array_filter(array_unique(array_map(fn(string $s) => substr($s, 1), $exported)),
    fn(string $f) => preg_match('/^(?!static\b)[A-Za-z_][\w \t*]*\b' . $f . '\s*\([^;{]*\)\s*\{/m', $main) !== 1));
same([], $undefined, 'every myphp_ios_ name in main.m is a function it defines, not static');

/** The C functions declared in $cdef, those of names starting $prefix. @return list<string> */
$declared = function (string $cdef, string $prefix): array {
    preg_match_all('/(?<![A-Za-z0-9_])(' . $prefix . '[A-Za-z0-9_]*)\s*\(/', $cdef, $m);
    return array_values(array_unique($m[1]));
};

$curl = $declared((new \ReflectionClassConstant(\MyPHP\Native\Curl::class, 'CDEF'))->getValue(), 'curl_');
check(in_array('curl_easy_init', $curl, true) && in_array('curl_multi_poll', $curl, true), 'libcurl\'s functions found in Curl::CDEF: ' . implode(' ', $curl));
same([], array_values(array_filter($curl, fn(string $f) => !$isExported("_$f"))), 'every libcurl function Native\Curl declares is exported');
same([], array_values(array_filter($patterns, fn(string $p) => fnmatch($p, '_Curl_ssl_init'))), 'libcurl\'s internal functions (Curl_*) are not');

$bridge = $declared((string)file_get_contents("$root/src/Native/iOS.php"), 'myphp_ios_');
check(count($bridge) > 10, 'the bridge\'s functions found in Native\iOS');
same([], array_values(array_filter($bridge, fn(string $f) => !$isExported("_$f"))), 'every bridge function Native\iOS declares is in main.m and exported');

// libcurl's API must be visible to be exported at all: a static libcurl built with curl's symbol hiding hides every
// function (CURL_EXTERN is empty then), and no list exports a hidden symbol. build-ios-php.sh builds it without, and
// again when the one it has hides them; package-ios-app.sh fails when the app does not export them.
$build = (string)file_get_contents("$root/scripts/build-ios-php.sh");
check(str_contains($build, '--disable-symbol-hiding'), 'curl is configured without symbol hiding');
check(preg_match('/if curl_hidden; then\s+echo [^\n]*\n\s+rm -f "\$\{SYSROOT\}\/lib\/libcurl\.a"/', $build) === 1, 'a libcurl.a that hides its API is built again');
check(str_contains($build, '&& ! curl_hidden; then'), 'nor is a downloaded one that hides it taken as it is');
check(preg_match('/strip -x "\$\{APP_DIR\}\/\$\{APP_NAME\}"\n(#[^\n]*\n)*if ! xcrun nm -gU "\$\{APP_DIR\}\/\$\{APP_NAME\}" \| grep \' _curl_easy_init\$\'/', $script) === 1, 'the app\'s build fails when the stripped app does not export curl_easy_init');
