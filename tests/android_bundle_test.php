<?php
// The Android app bundle (.aab) Google Play takes: made by scripts/package-android-apk.sh with MYPHP_ANDROID_FORMAT=aab
// (myphp build -format aab). What runs without an SDK: BundleConfig.pb is the 11 bytes bundletool writes by default, and
// the script and the CLI carry the bundle's steps.
declare(strict_types=1);

namespace MyPHP\Tests;

$root = dirname(__DIR__);

$pb = tempnam(sys_get_temp_dir(), 'bundleconfig');
shell_exec(escapeshellarg("$root/scripts/android-bundle-config.sh") . ' ' . escapeshellarg($pb));
same("\x0a\x08\x12\x061.18.1", (string)file_get_contents($pb), 'BundleConfig.pb is what bundletool writes by default: a Bundletool message holding its version');
unlink($pb);

$script = (string)file_get_contents("$root/scripts/package-android-apk.sh");
check(str_contains($script, 'FORMAT="${MYPHP_ANDROID_FORMAT:-apk}"'), 'the format comes from MYPHP_ANDROID_FORMAT, an APK by default');
check(str_contains($script, '--proto-format'), 'a bundle\'s resources are linked in protobuf format');
foreach (['manifest/', 'dex/', 'BundleConfig.pb', 'resources.pb'] as $part) {
    check(str_contains($script, $part), "the module has $part");
}
check(str_contains($script, '"$JARSIGNER"') && str_contains($script, '-sigalg SHA256withRSA'), 'a bundle is signed with jarsigner');
check(str_contains($script, 'if [ "$FORMAT" = "apk" ]; then'), 'zipalign and the APK steps are for an APK only');

$cli = (string)file_get_contents("$root/bin/myphp");
check(str_contains($cli, "option(\$args, 'format', 'apk')") && str_contains($cli, '-format apk|aab'), 'myphp build takes -format apk|aab');
check(str_contains($cli, 'putenv("MYPHP_ANDROID_FORMAT=$format")') && str_contains($cli, '"$out/$name.$format"'), 'and makes <name>.aab of it');
