<?php
// What goes into an iOS app's Info.plist beyond MyPHP's own keys (scripts/ios-info-plist.sh, ios.infoPlist in myphp.json):
// ITSAppUsesNonExemptEncryption false by default, so that App Store Connect does not ask about encryption at every
// upload, and the app's own keys (usage descriptions) over it. plutil is the Mac's: the merge is checked where it is.
declare(strict_types=1);

namespace MyPHP\Tests;

$root = dirname(__DIR__);
$script = (string)file_get_contents("$root/scripts/package-ios-app.sh");
check(str_contains($script, 'scripts/ios-info-plist.sh'), 'package-ios-app.sh adds the keys');
$cli = (string)file_get_contents("$root/bin/myphp");
check(str_contains($cli, "'MYPHP_IOS_INFO_PLIST'") && str_contains($cli, 'ios.infoPlist is an object of Info.plist keys'), 'myphp.json\'s ios.infoPlist is checked and given to the script');

if (PHP_OS_FAMILY === 'Darwin') {
    $merge = function (string $env) use ($root): array {
        $file = tempnam(sys_get_temp_dir(), 'infoplist');
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd"><plist version="1.0"><dict>'
            . '<key>CFBundleIdentifier</key><string>com.example.app</string><key>UILaunchScreen</key><dict/>'
            . '<key>UIRequiredDeviceCapabilities</key><array><string>arm64</string></array></dict></plist>');
        shell_exec(($env !== '' ? 'MYPHP_IOS_INFO_PLIST=' . escapeshellarg($env) . ' ' : '') . escapeshellarg("$root/scripts/ios-info-plist.sh") . ' ' . escapeshellarg($file));
        $json = (string)shell_exec('plutil -convert json -o - ' . escapeshellarg($file));
        unlink($file);
        return (array)json_decode($json, true);
    };
    $plain = $merge('');
    same(false, $plain['ITSAppUsesNonExemptEncryption'] ?? null, 'no export-compliance question by default');
    same('com.example.app', $plain['CFBundleIdentifier'] ?? null, 'MyPHP\'s keys stay');
    same([], $plain['UILaunchScreen'] ?? null, 'an empty dictionary stays a dictionary');
    $app = $merge(json_encode(['NSLocalNetworkUsageDescription' => 'Probe hosts on your network.', 'NSBonjourServices' => ['_http._tcp'], 'UIFileSharingEnabled' => true, 'ITSAppUsesNonExemptEncryption' => true]));
    same('Probe hosts on your network.', $app['NSLocalNetworkUsageDescription'] ?? null, 'a usage description is added');
    same(['_http._tcp'], $app['NSBonjourServices'] ?? null, 'an array is added');
    same(true, $app['UIFileSharingEnabled'] ?? null, 'a boolean is added');
    same(true, $app['ITSAppUsesNonExemptEncryption'] ?? null, 'the app\'s key replaces MyPHP\'s default');
    same(['arm64'], $app['UIRequiredDeviceCapabilities'] ?? null, 'and the keys it does not name stay');
}
