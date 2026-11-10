<?php
// The packaging of Windows apps, on every system (Native\Build\Nsis, InstallPs1, Checksums,
// WinSign, Windows; Update\Publish::writeFlat; Native\Detached): the installer's script, its
// languages and escapes; install.ps1 and its ASCII quoting; SHA256SUMS merged; a PE image's
// signature told; myphp.json's windows checked; an update of a folder of files signed and
// unpacked; a command line quoted as Windows reads it.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Build\{Checksums, InstallPs1, Nsis, Windows, WinSign};
use MyPHP\Native\Update\{Archive, Ed25519, Format, Publish};

(function (): void {
    same('"a$$b$\\"c$\\n"', Nsis::str("a\$b\"c\n"), "NSIS's escapes");
    same(['SimpChinese', 'TradChinese', 'Portuguese', 'PortugueseBR', 'English', null], [Nsis::language('zh-Hans'), Nsis::language('zh-TW'), Nsis::language('pt-PT'), Nsis::language('pt'), Nsis::language('en-GB'), Nsis::language('sw')], 'the NSIS language of a tag');
    $macros = Nsis::languageMacros([['zh-Hans', '创建桌面快捷方式'], ['en', ''], ['zh-CN', 'x']]);
    same("!insertmacro MUI_LANGUAGE \"SimpChinese\"\n!insertmacro MUI_LANGUAGE \"English\"\nLangString MYPHP_DESKTOP_SHORTCUT \${LANG_SIMPCHINESE} \"创建桌面快捷方式\"\nLangString MYPHP_DESKTOP_SHORTCUT \${LANG_ENGLISH} \"Create a desktop shortcut\"\n", $macros, "the installer's languages, the app's first, one each, the finish page's label in each");
    try { Nsis::languageMacros([['sw', '']]); check(false, 'a language NSIS has no file of fails'); } catch (\RuntimeException $e) { check(str_contains($e->getMessage(), 'sw'), 'a language NSIS has no file of fails'); }

    $bad = function (string $m): never { throw new \RuntimeException($m); };
    $w = Windows::config(['displayName' => 'Weather & Co'], 'weather', 'Jane Doe <jane@example.org>', $bad);
    same(['Weather & Co', 'weather', 'Jane Doe', ''], [$w['displayName'], $w['installDirName'], $w['publisher'], $w['certificate']], "windows's defaults");
    foreach ([['installDirName' => 'a/b'], ['certificate' => 'a.pfx', 'signCommand' => 'x %1'], ['displayName' => 3]] as $wrong) {
        try { Windows::config($wrong, 'x', '', $bad); check(false, 'refused: ' . json_encode($wrong)); } catch (\RuntimeException) { check(true, 'refused: ' . json_encode($wrong)); }
    }
    $c = ['name' => 'weather', 'slug' => '', 'identifier' => 'com.example.weather', 'version' => '1.2.0', 'windows' => $w,
        'fileAssociations' => [['ext' => ['wx'], 'name' => 'Weather file']], 'urlSchemes' => ['weather']];
    same(['weather.exe', 'Weather & Co', 'weather-setup-1.2.0-amd64.exe'], [Windows::exeName($c), Windows::shortcutName($c), Nsis::installerName($c, 'amd64')], 'the names of the launcher, the shortcuts and the installer');
    $stage = sys_get_temp_dir() . '/myphp-nsis-' . bin2hex(random_bytes(4));
    mkdir("$stage/php", 0o755, true);
    file_put_contents("$stage/weather.exe", 'MZ');
    $script = Nsis::script($c, 'C:\\out\\setup.exe', $stage, ['php', 'weather.exe'], 'weather.exe', '', $macros, '', '');
    foreach ([
        'Name "Weather & Co" "Weather && Co"', 'RequestExecutionLevel user', 'InstallDir "$LOCALAPPDATA\\Programs\\weather"',
        '  File /r "' . str_replace('/', '\\', "$stage/php") . '"', '  File "' . str_replace('/', '\\', "$stage/weather.exe") . '"',
        'WriteRegStr HKCU "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\com.example.weather" "DisplayName" "Weather & Co"',
        'WriteRegStr HKCU "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\com.example.weather" "Publisher" "Jane Doe"',
        'WriteRegStr HKCU "Software\\Classes\\com.example.weather.wx\\shell\\open\\command" "" \'"$INSTDIR\\weather.exe" "%1"\'',
        'WriteRegStr HKCU "Software\\Classes\\weather" "URL Protocol" ""', 'CreateShortCut "$SMPROGRAMS\\Weather & Co.lnk" "$INSTDIR\\weather.exe"',
        'Delete "$SMPROGRAMS\\weather.lnk"', 'Function un.CloseApp', 'RMDir /r "$INSTDIR"',
    ] as $line) {
        check(str_contains($script, $line), "the installer's script has $line");
    }
    \MyPHP\Update::removeAll($stage);

    same(["'a''b'", "('' + 'Caf' + [char]0x00E9)", "('' + [char]0x5929 + [char]0x6C14)"], [InstallPs1::quote("a'b"), InstallPs1::quote('Café'), InstallPs1::quote('天气')], "PowerShell's ASCII quoting");
    $pub = base64_encode(Ed25519::keyPair(str_repeat("\1", 32))[1]);
    $p = new Publish(sys_get_temp_dir(), $c + ['updates' => ['publicKey' => $pub, 'github' => 'me/weather']]);
    $ps1 = InstallPs1::script($c, $p);
    check(str_contains($ps1, 'irm https://github.com/me/weather/releases/latest/download/install.ps1 | iex') && str_contains($ps1, "\$setupPrefix = 'weather-setup-'") && str_contains($ps1, '$github = $true')
        && str_contains($ps1, '$env:WEATHER_DOWNLOAD_URL') && str_contains($ps1, "\$appName = 'Weather & Co'"), 'install.ps1 of a GitHub release');

    $a = str_repeat('a', 64); $b = str_repeat('b', 64); $c2 = str_repeat('c', 64);
    same("$a  x-setup-1-amd64.exe\n$c2  x-setup-1-arm64.exe\n", Checksums::merge("$a  x-setup-1-amd64.exe\n", "$b  x-setup-1-amd64.exe\n$c2  x-setup-1-arm64.exe\n"), "SHA256SUMS merged, this build's first");

    // A PE32+ image: the DOS header pointing at the PE header, its optional header's certificate table empty, then not.
    $pe = function (int $certSize): string {
        $dos = 'MZ' . str_repeat("\0", 0x3a) . pack('V', 64);
        $file = "PE\0\0" . str_repeat("\0", 16) . pack('v', 240) . "\0\0";
        $optional = pack('v', 0x20b) . str_repeat("\0", 106) . pack('V', 16) . str_repeat("\0", 4 * 8) . pack('VV', $certSize > 0 ? 4096 : 0, $certSize) . str_repeat("\0", 11 * 8);
        return $dos . $file . $optional;
    };
    $f = (string)tempnam(sys_get_temp_dir(), 'pe');
    file_put_contents($f, $pe(0));
    $unsigned = WinSign::peImage($f);
    file_put_contents($f, $pe(1024));
    $signed = WinSign::peImage($f);
    file_put_contents($f, 'not an image');
    same([[true, false], [true, true], [false, false]], [$unsigned, $signed, WinSign::peImage($f)], "a PE image's signature told");
    unlink($f);

    // An update of a folder of files: its archive holds them at its top, signed with the key.
    $root = sys_get_temp_dir() . '/myphp-flat-' . bin2hex(random_bytes(4));
    mkdir("$root/app/php", 0o755, true);
    mkdir("$root/out");
    file_put_contents("$root/app/weather.exe", 'launcher');
    file_put_contents("$root/app/php/php.exe", 'php');
    [$priv, $pubKey] = Ed25519::keyPair(str_repeat("\2", 32));
    putenv('MYPHP_UPDATER_PRIVATE_KEY=' . base64_encode($priv));
    $pf = new Publish($root, $c + ['updates' => ['publicKey' => base64_encode($pubKey), 'url' => 'https://example.org/updates', 'deltas' => 0]]);
    $files = $pf->writeFlat("$root/app", ['php', 'weather.exe'], "$root/out", 'windows-amd64', fn(string $l) => null);
    putenv('MYPHP_UPDATER_PRIVATE_KEY');
    same(["$root/out/weather-1.2.0-windows-amd64.tar.gz", "$root/out/update-windows-amd64.json"], $files, 'the archive and the manifest');
    $m = json_decode((string)file_get_contents("$root/out/update-windows-amd64.json"), true);
    $sum = hash_file('sha256', "$root/out/weather-1.2.0-windows-amd64.tar.gz", true);
    $ok = true;
    try { Format::verify($pubKey, $sum, $m['signature']); } catch (\RuntimeException) { $ok = false; }
    mkdir("$root/x");
    Archive::extract("$root/out/weather-1.2.0-windows-amd64.tar.gz", "$root/x");
    same(['https://example.org/updates/weather-1.2.0-windows-amd64.tar.gz', true, 'launcher', 'php'], [$m['url'], $ok, @file_get_contents("$root/x/weather.exe"), @file_get_contents("$root/x/php/php.exe")], 'its manifest, its signature, its files at its top');
    \MyPHP\Update::removeAll($root);

    same(['plain', '"two words"', '"a\\"b"', '"C:\\dir with space\\\\"', '""'], array_map([\MyPHP\Native\Detached::class, 'quote'], ['plain', 'two words', 'a"b', 'C:\\dir with space\\', '']), 'a command line quoted as Windows reads it');
})();
