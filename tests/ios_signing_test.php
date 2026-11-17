<?php
// An iPhone installs an app signed by a development (or distribution) certificate with a provisioning profile in it;
// ad hoc signing, which the simulator runs, it refuses ("The executable contains an invalid signature", 0xe8008014).
// myphp finds the profile and the identity as Xcode's automatic signing does (Native\Build\Provisioning, bin/myphp's
// iosSigning), and package-ios-app.sh signs with them.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Build\Provisioning;

$cert = "certificate DER of Apple Development: Ada (TEAM123456)";
$profile = function (string $appId, string $expires = '2099-01-01T00:00:00Z', ?array $devices = ['00008110-000A1B2C3D4E5F6A'], bool $debug = true, string $team = 'TEAM123456') use ($cert): string {
    $devs = $devices === null ? '<key>ProvisionsAllDevices</key><true/>' : '<key>ProvisionedDevices</key><array>' . implode('', array_map(fn($d) => "<string>$d</string>", $devices)) . '</array>';
    return "0\x82\x10\x00CMS signed data\0<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE plist PUBLIC \"-//Apple//DTD PLIST 1.0//EN\" \"http://www.apple.com/DTDs/PropertyList-1.0.dtd\">\n<plist version=\"1.0\"><dict>"
        . "<key>Name</key><string>iOS Team Provisioning Profile: $appId</string><key>UUID</key><string>1111-2222</string>"
        . "<key>Platform</key><array><string>iOS</string><string>xrOS</string></array>"
        . "<key>TeamIdentifier</key><array><string>$team</string></array>"
        . "<key>ExpirationDate</key><date>$expires</date>"
        . "<key>DeveloperCertificates</key><array><data>\n" . chunk_split(base64_encode($cert), 52, "\n") . "</data></array>"
        . "<key>Entitlements</key><dict><key>application-identifier</key><string>$team.$appId</string><key>com.apple.developer.team-identifier</key><string>$team</string>"
        . '<key>get-task-allow</key>' . ($debug ? '<true/>' : '<false/>') . "<key>keychain-access-groups</key><array><string>$team.*</string></array>"
        . '<key>com.apple.developer.associated-domains</key><string>*</string></dict>'
        . "$devs</dict></plist>\n\x00\xa0signature";
};

$p = Provisioning::read($profile('com.example.notes'));
same(['iOS Team Provisioning Profile: com.example.notes', 'TEAM123456', 'TEAM123456.com.example.notes', ['00008110-000A1B2C3D4E5F6A'], [strtoupper(sha1($cert))]],
    [$p['name'] ?? null, $p['team'] ?? null, $p['appId'] ?? null, $p['devices'] ?? null, $p['certs'] ?? null], 'a profile read out of its CMS signature: its team, app, devices, certificates');
same(gmmktime(0, 0, 0, 1, 1, 2099), $p['expires'] ?? null, 'and when it expires');
same(null, Provisioning::read('not a profile'), 'anything else is none');
same(null, Provisioning::read(str_replace('<string>iOS</string><string>xrOS</string>', '<string>OSX</string>', $profile('com.example.notes'))), "a Mac's profile is no iPhone's");

$now = gmmktime(0, 0, 0, 1, 1, 2027);
$udid = '00008110-000A1B2C3D4E5F6A';
$exact = Provisioning::read($profile('com.example.notes'));
$wild = Provisioning::read($profile('*'));
$prefix = Provisioning::read($profile('com.example.*'));
$expired = Provisioning::read($profile('com.example.notes', '2026-06-01T00:00:00Z'));
$other = Provisioning::read($profile('com.example.notes', team: 'OTHER12345'));
$enterprise = Provisioning::read($profile('com.example.notes', devices: null));
same($exact, Provisioning::choose([$wild, $prefix, $exact], 'com.example.notes', '', $udid, $now), "the app's own profile over wildcards");
same($prefix, Provisioning::choose([$wild, $prefix], 'com.example.notes', '', $udid, $now), 'the longer wildcard over "*"');
same($wild, Provisioning::choose([$wild, $prefix], 'org.other.app', '', $udid, $now), 'a wildcard of another prefix does not fit');
same(null, Provisioning::choose([$expired], 'com.example.notes', '', $udid, $now), 'an expired profile does not fit');
same(null, Provisioning::choose([$other], 'com.example.notes', 'TEAM123456', $udid, $now), 'nor one of another team than ios.teamId');
same($other, Provisioning::choose([$other], 'com.example.notes', '', $udid, $now), 'any team without ios.teamId');
same(null, Provisioning::choose([$exact], 'com.example.notes', '', '00008030-FFFFFFFFFFFFFFFF', $now), 'nor one without this iPhone');
same($exact, Provisioning::choose([$exact], 'com.example.notes', '', '', $now), 'a build for any device: its devices not asked');
same($enterprise, Provisioning::choose([$enterprise], 'com.example.notes', '', '00008030-FFFFFFFFFFFFFFFF', $now), 'an enterprise profile is for every device');
$later = Provisioning::read($profile('com.example.notes', '2100-01-01T00:00:00Z'));
same($later, Provisioning::choose([$exact, $later], 'com.example.notes', '', $udid, $now), 'of two as good, the one that expires last');

same(['application-identifier' => 'TEAM123456.com.example.notes', 'com.apple.developer.team-identifier' => 'TEAM123456', 'get-task-allow' => true, 'keychain-access-groups' => ['TEAM123456.com.example.notes']],
    Provisioning::entitlements($wild, 'com.example.notes'), "the app's entitlements of a wildcard profile: its own identifier and keychain group, a debugger allowed, no \"*\" of the profile's");
same(false, Provisioning::entitlements(Provisioning::read($profile('com.example.notes', debug: false)), 'com.example.notes')['get-task-allow'], 'a distribution profile allows no debugger');

$hash = strtoupper(sha1($cert));
$found = "  1) 0123456789ABCDEF0123456789ABCDEF01234567 \"Developer ID Application: Ada (TEAM123456)\"\n  2) $hash \"Apple Development: Ada (TEAM123456)\"\n     2 valid identities found\n";
same($hash, Provisioning::identity($exact, $found), "the keychain's identity whose certificate the profile names, by its SHA-1");
same(null, Provisioning::identity($exact, "  1) 0123456789ABCDEF0123456789ABCDEF01234567 \"Developer ID Application: Ada (TEAM123456)\"\n"), 'none without it');

// package-ios-app.sh signs with what myphp chose for an iPhone, the profile in the app; ad hoc for the simulator alone.
$sh = (string)file_get_contents(__DIR__ . '/../scripts/package-ios-app.sh');
check(str_contains($sh, 'cp "${MYPHP_IOS_PROFILE}" "${APP_DIR}/embedded.mobileprovision"') && str_contains($sh, 'codesign --force --sign "${MYPHP_IOS_SIGN_IDENTITY}" --entitlements "${MYPHP_IOS_ENTITLEMENTS}"'), "an iPhone's app: the profile in it, signed with the identity and the entitlements");
check(preg_match('/elif \[ "\$IOS_SDK" = iphoneos \]; then\s+echo "error:[^\n]*\n\s+exit 1\s+else\s+codesign -s - /', $sh) === 1, 'ad hoc only for the simulator: an iPhone build without them stops');
$cli = (string)file_get_contents(__DIR__ . '/../bin/myphp');
check(preg_match('/if \(\$targetDev->type === \'device\'\) \{\s+iosSigning\(\$dir, \$c, \$bundleId, \$targetDev->id, \$out\);/', $cli) === 1, 'myphp run signs for the iPhone it runs on, before building');
check(str_contains($cli, "putenv('MYPHP_IOS_SDK=iphoneos'); // a build is for devices\n    iosSigning(\$dir, \$c, \$bundleId, '', \$out);"), 'myphp build for any device');
