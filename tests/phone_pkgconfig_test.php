<?php
// configure finds the phone runtimes' libraries from the variables the scripts name, not from pkg-config's own search
// path: a runtime unpacked from an archive has no .pc files, and a Homebrew's libcurl.pc is the Mac's (-lcurl -lldap), whose
// -lldap the iPhone SDK has not ("checking for curl_easy_perform in -lcurl... no", "library 'ldap' not found").
declare(strict_types=1);

namespace MyPHP\Tests;

$root = dirname(__DIR__);
foreach (['build-ios-php.sh', 'build-android-php.sh'] as $name) {
    $script = (string)file_get_contents("$root/scripts/$name");
    check(str_contains($script, 'export PKG_CONFIG_LIBDIR="${SYSROOT}/lib/pkgconfig"'), "$name: pkg-config reads the sysroot's .pc files only");
    foreach (['OPENSSL', 'LIBXML', 'CURL', 'FFI', 'ZLIB'] as $lib) {
        check(preg_match('/export [^\n]*\b' . $lib . '_CFLAGS=[^\n]*\b' . $lib . '_LIBS=/', $script) === 1, "$name: $lib is named outright");
    }
    check(str_contains($script, 'CURL_LIBS="-L${SYSROOT}/lib -lcurl -lssl -lcrypto -lz"'), "$name: libcurl's link line is the sysroot's, with no -lldap");
    check(!preg_match('/CURL_LIBS="[^"]*ldap/', $script), "$name: and none names ldap");
}
