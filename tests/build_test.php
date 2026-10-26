<?php
// Packaging an app, as `myphp build` does it (mygo's cmd/mygo dmg_test.go,
// cli_test.go, resources_test.go and s3_test.go): the .DS_Store of the
// disk image's window, byte for byte what dmgbuild writes; binary property
// lists as Python's plistlib lays them out; the arguments of codesign and
// hdiutil; Mach-O files and universal binaries; the resources of each
// platform, merged and combined; the icon; S3's signatures and uploads;
// and the commands of bin/myphp that run anywhere.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Build\{Codesign, Dmg, DsStore, Icns, MachO, Resources, S3, Upload};

function bldDir(): string
{
    $d = sys_get_temp_dir() . '/myphp-build-' . bin2hex(random_bytes(4));
    mkdir($d, 0755, true);
    return $d;
}

/** @param array<string, string> $files */
function bldWrite(string $dir, array $files): void
{
    foreach ($files as $name => $content) {
        @mkdir(dirname("$dir/$name"), 0755, true);
        file_put_contents("$dir/$name", $content);
    }
}

function bldThrows(\Closure $fn): string
{
    try {
        $fn();
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
    return '';
}

/** The files under $dir, by path: their contents, or "-> target" for links. @return array<string, string> */
function bldFiles(string $dir, string $prefix = ''): array
{
    $out = [];
    foreach (scandir($dir) as $n) {
        if ($n === '.' || $n === '..') { continue; }
        $p = "$dir/$n";
        if (is_link($p)) {
            $out["$prefix$n"] = '-> ' . readlink($p);
        } elseif (is_dir($p)) {
            $out += bldFiles($p, "$prefix$n/");
        } else {
            $out["$prefix$n"] = (string)file_get_contents($p);
        }
    }
    ksort($out);
    return $out;
}

/** A Mach-O header followed by load commands of the given types: 64-bit little-endian, or 32-bit big-endian as on PowerPC. */
function bldMachO(bool $le, int ...$cmds): string
{
    $n = count($cmds);
    $b = $le ? pack('V8', 0xfeedfacf, 0x0100000c, 0, 2, $n, $n * 16, 0, 0) : pack('N7', 0xfeedface, 18, 0, 2, $n, $n * 16, 0);
    foreach ($cmds as $c) {
        $b .= $le ? pack('V4', $c, 16, 0, 0) : pack('N4', $c, 16, 0, 0);
    }
    return $b;
}

// The window of the disk image, as dmgbuild's ds_store writes it for the same records.
same(file_get_contents(__DIR__ . '/testdata/dmg.DS_Store'), DsStore::encodeFile(DsStore::dmgRecords('Hello MyGo.app')), 'the .DS_Store of dmgbuild, byte for byte');
// A binary property list, as Python's plistlib.dumps(fmt=FMT_BINARY, sort_keys=True) writes it.
same('62706c6973743030d70102030405060708090a0b0c0d0e55636f756e7454666c6167546c6f6e67546e616d65536f66665473697a6557756e69636f646511012c095f10147878787878787878787878787878787878787878546e6f6e650823406000000000000065006800e9006c006c006f08171d22272c30353d4041585d5e670000000000000101000000000000000f00000000000000000000000000000072',
    bin2hex(DsStore::binaryPlist(['flag' => true, 'off' => false, 'count' => 300, 'size' => 128.0, 'name' => 'none', 'unicode' => 'héllo', 'long' => str_repeat('x', 20)])), 'a binary plist, as plistlib');

// The arguments of codesign and hdiutil.
same(['--force', '--deep', '--sign', '-', 'A.app'], Codesign::args('A.app', '-', '', true), 'ad hoc');
same(['--force', '--deep', '--sign', 'Developer ID Application: X', '--options', 'runtime', '--timestamp', '--entitlements', 'e.plist', 'A.app'], Codesign::args('A.app', 'Developer ID Application: X', 'e.plist', true), 'production');
check(!in_array('--timestamp', Codesign::args('A.app', 'Apple Development: X', '', false), true), 'development builds need no timestamp');
foreach ([
    ['-', '', true, ['--force', '--sign', '-', '--preserve-metadata=entitlements']],
    ['Developer ID Application: X', '', true, ['--force', '--sign', 'Developer ID Application: X', '--options', 'runtime', '--timestamp', '--preserve-metadata=entitlements']],
    ['Developer ID Application: X', 'e.plist', true, ['--force', '--sign', 'Developer ID Application: X', '--options', 'runtime', '--timestamp', '--entitlements', 'e.plist']],
    ['Apple Development: X', '', false, ['--force', '--sign', 'Apple Development: X', '--preserve-metadata=entitlements']],
] as [$id, $ent, $prod, $want]) {
    same($want, Codesign::nestedArgs($id, $ent, $prod), "nested code: $id $ent");
}
$args = Dmg::createArgs('My App', 'src', 'rw.dmg');
check(!in_array('-size', $args, true) && !in_array('-quiet', $args, true), 'hdiutil create sizes the image and says why it failed');
check(Dmg::busy("hdiutil create -ov ...: exit status 1\nhdiutil: create failed - Resource busy"), '"Resource busy" is retried');
check(!Dmg::busy("hdiutil create -ov ...: exit status 1\nhdiutil: create failed - No space left on device"), 'only busy resources are retried');
same('/dev/disk14', Dmg::attachedDisk("/dev/disk14         \tGUID_partition_scheme          \t\n/dev/disk14s1       \tApple_HFS                      \t/tmp/x/mnt\n"), 'the whole disk attached');
same(null, Dmg::attachedDisk(''), 'no device');
// Each architecture's disk image is named apart; a universal one is for every Mac.
foreach (['arm64' => 'A-B- C 1.0 arm64.dmg', 'amd64' => 'A-B- C 1.0 amd64.dmg', 'universal' => 'A-B- C 1.0.dmg'] as $arch => $want) {
    same($want, Dmg::fileName('A/B: C', '1.0', $arch), "the disk image for $arch");
}
check(Codesign::isBundle(__DIR__) === false, 'a directory without an extension of code is no bundle');

// Mach-O files: which are code, and signed; universal binaries.
$d = bldDir();
foreach (['thin' => bldMachO(true, 0x19), 'signed' => bldMachO(true, 0x19, 0x1d), 'ppc' => bldMachO(false, 0x1d), 'truncated' => substr(bldMachO(true, 0x19, 0x1d), 0, 40),
    'header' => "\xcf\xfa\xed\xfe\x0c\x00\x00\x01", 'class' => "\xca\xfe\xba\xbe\x00\x00\x00\x3d", 'text' => "#!/bin/sh\necho", 'short' => "\xcf"] as $name => $bytes) {
    file_put_contents("$d/$name", $bytes);
}
MachO::writeUniversal("$d/fat", "$d/signed", "$d/signed");
MachO::writeUniversal("$d/mixed", "$d/signed", "$d/thin");
foreach (['thin' => [true, false], 'signed' => [true, true], 'ppc' => [true, true], 'truncated' => [true, false], 'header' => [true, false], 'fat' => [true, true],
    'mixed' => [true, false], 'class' => [false, false], 'text' => [false, false], 'short' => [false, false], 'missing' => [false, false]] as $name => $want) {
    same($want, MachO::read("$d/$name"), "Mach-O $name");
}
file_put_contents("$d/arm", pack('V2', 0xfeedfacf, MachO::CPU_ARM64) . str_repeat("\0", 992));
file_put_contents("$d/amd", pack('V2', 0xfeedfacf, MachO::CPU_AMD64) . str_repeat("\0", 2992));
MachO::writeUniversal("$d/both", "$d/arm", "$d/amd");
$fat = file_get_contents("$d/both");
same([0xcafebabe, 2], array_values(unpack('N2', $fat)), 'a fat header of two');
foreach ([1000, 3000] as $i => $size) {
    [, $off, $sz] = array_values(unpack('N3', $fat, 8 + $i * 20 + 4));
    check($sz === $size && $off % (1 << 14) === 0 && unpack('V', $fat, $off)[1] === 0xfeedfacf, "slice $i at $off, $sz bytes");
}
check(bldThrows(fn() => MachO::writeUniversal("$d/x", "$d/missing")) !== '', 'a missing slice');
same(['arm64 code', 'x86_64 code', '', 'universal or 32-bit code'], [MachO::kind("$d/arm"), MachO::kind("$d/amd"), MachO::kind("$d/text"), MachO::kind("$d/fat")], 'what code is');

// A micro.sfx takes its payload inside __LINKEDIT, which codesign signs, and its section says where (v1.28): the signature comes off, the segment grows over the payload.
$microSfx = function (bool $section): string {
    $cmds = pack('VV', 0x19, 72 + 80) . str_pad('__DATA', 16, "\0") . pack('PPPPVVVV', 0x4000, 0x4000, 0x1000, 0x1000, 3, 3, 1, 0)
        . str_pad($section ? MachO::MICRO_SECTION : '__data', 16, "\0") . str_pad('__DATA', 16, "\0") . pack('PPVVVVVVVV', 0x4000, 16, 0x1000, 0, 0, 0, 0, 0, 0, 0)
        . pack('VV', 0x19, 72) . str_pad('__LINKEDIT', 16, "\0") . pack('PPPPVVVV', 0x8000, 0x4000, 0x2000, 0x1100, 1, 1, 0, 0)
        . pack('VVVV', 0x1d, 16, 0x3000, 0x100);
    $head = pack('V8', 0xfeedfacf, MachO::CPU_ARM64, 0, 2, 3, strlen($cmds), 0, 0) . $cmds;
    return str_pad($head, 0x2000, "\0") . str_repeat("L", 0x1000) . str_repeat("S", 0x100);
};
MachO::embed($microSfx(true), MachO::microPayload("memory_limit=1G", 'PHAR'), "$d/embedded");
$embedded = file_get_contents("$d/embedded");
$payloadAt = 0x3000;
same(["\xfd\xf6\x69\xe6" . pack('N', 15) . 'memory_limit=1G' . 'PHAR', [$payloadAt, $payloadAt + 27]], [substr($embedded, $payloadAt), array_values(unpack('J2', $embedded, 0x1000))], 'the payload after __LINKEDIT\'s data, where the section says');
same([2, 0x1000 + 27, 0x4000], [unpack('V', $embedded, 16)[1], unpack('P', $embedded, 32 + 152 + 48)[1], unpack('P', $embedded, 32 + 152 + 32)[1]], 'the signature\'s command gone, __LINKEDIT over the payload');
file_put_contents("$d/nosection", $microSfx(false));
same([[true, false], true, false], [MachO::read("$d/embedded"), MachO::hasMicroSection("$d/embedded"), MachO::hasMicroSection("$d/nosection")], 'unsigned, for the bundle\'s signature; a micro.sfx without the section');
check(str_contains(bldThrows(fn() => MachO::embed($microSfx(false), 'x', "$d/refused")), MachO::MICRO_SECTION) && !is_file("$d/refused"), 'one without the section is refused');
check(bldThrows(fn() => MachO::embed(substr($microSfx(true), 0, 100), 'x', "$d/refused")) !== '', 'a truncated one too');

// Resources: the directory's entries, the listed ones under their names, hidden files left out, links inside kept.
$p = bldDir();
bldWrite($p, ['resources/icon.png' => 'png', 'resources/data/words.txt' => 'hello', 'resources/data/.DS_Store' => 'junk', 'resources/.gitkeep' => '',
    'legal/NOTICE.txt' => 'notice', 'vendor/models/tiny.bin' => 'model', 'vendor/models/sub/more.bin' => 'more', 'resources/bin/tool' => "#!/bin/sh\n"]);
chmod("$p/resources/bin/tool", 0755);
symlink('data/words.txt', "$p/resources/words");
symlink('words.txt', "$p/resources/data/alias");
$list = Resources::collect($p, 'myphp.json', ['legal/NOTICE.txt', 'vendor/models'], 'darwin', 'arm64', [], Resources::reserved(false));
same(['bin', 'data', 'icon.png', 'words', 'NOTICE.txt', 'models'], array_column($list, 'name'), 'the resources, in order');
$out = bldDir();
Resources::copy($list, $out);
$got = bldFiles($out);
same(['NOTICE.txt' => 'notice', 'bin/tool' => "#!/bin/sh\n", 'data/alias' => '-> words.txt', 'data/words.txt' => 'hello', 'icon.png' => 'png', 'models/sub/more.bin' => 'more', 'models/tiny.bin' => 'model', 'words' => 'hello'], $got,
    'copied: a listed link as a file, a link inside as a link, no hidden files');
check((fileperms("$out/bin/tool") & 0111) !== 0, 'bin/tool keeps its executable bit');
check(str_contains(bldThrows(fn() => Resources::copy($list, "$p/vendor/models/out")), 'contains the directory'), 'copying a resource into itself');
foreach ([
    [['resources'], [], 'always included'], [['./resources/'], [], 'always included'], [['missing.txt'], [], 'missing.txt'],
    [['legal/NOTICE.txt', 'other/notice.txt'], [], 'would both be installed as notice.txt'], [['vendor/data'], [], 'would both be installed as data'],
    [[], ['ICON.PNG'], "would replace the app's own ICON.PNG"],
] as [$listed, $reserved, $want]) {
    bldWrite($p, ['other/notice.txt' => '', 'vendor/data/x' => '']);
    check(str_contains(bldThrows(fn() => Resources::collect($p, 'myphp.json', $listed, 'linux', 'amd64', [], $reserved)), $want), "refused: $want");
}
$empty = bldDir();
same([], Resources::collect($empty, 'myphp.json', [], 'darwin', 'arm64'), 'no resources at all');
file_put_contents("$empty/resources", 'not a directory');
check(bldThrows(fn() => Resources::collect($empty, 'myphp.json', [], 'darwin', 'arm64')) !== '', 'a resources file');
same(['AppIcon.icns'], Resources::reserved(false), 'the bundle keeps its icon');
same(['AppIcon.icns', 'Assets.car'], Resources::reserved(true), 'and its asset catalog');

// Platform directories: each app ships its own.
$p = bldDir();
bldWrite($p, ['legal/NOTICE.txt' => 'notice', 'resources/data/words.txt' => 'hello', 'resources/bin/tool' => 'tool', 'resources/windows-icons/tray.ico' => 'not a platform directory',
    'resources/darwin/bin/helper' => 'darwin helper', 'resources/darwin-arm64/bin/server' => 'darwin arm64 server', 'resources/darwin-amd64/bin/server' => 'darwin amd64 server',
    'resources/darwin-universal/fat.txt' => 'universal apps only', 'resources/linux-amd64/bin/server' => 'linux amd64 server', 'resources/linux-amd64/.hidden' => '',
    'resources/linux-arm64/lib/libx.so' => 'linux arm64 library', 'resources/windows/bin/.keep' => '', 'resources/windows-amd64/bin/server.exe' => 'windows amd64 server']);
$shared = ['NOTICE.txt' => 'notice', 'bin/tool' => 'tool', 'data/words.txt' => 'hello', 'windows-icons/tray.ico' => 'not a platform directory'];
foreach ([['darwin', 'arm64', ['bin/helper' => 'darwin helper', 'bin/server' => 'darwin arm64 server']], ['darwin', 'amd64', ['bin/helper' => 'darwin helper', 'bin/server' => 'darwin amd64 server']],
    ['linux', 'amd64', ['bin/server' => 'linux amd64 server']], ['linux', 'arm64', ['lib/libx.so' => 'linux arm64 library']], ['linux', 'riscv64', []]] as [$os, $arch, $want]) {
    $out = bldDir();
    Resources::copy(Resources::collect($p, 'myphp.json', ['legal/NOTICE.txt'], $os, $arch), $out);
    $want += $shared;
    ksort($want);
    same($want, bldFiles($out), "the resources of $os/$arch");
}
foreach (['linux/riscv64' => 'linux-amd64', 'windows/arm64' => 'windows-amd64', 'linux/amd64' => '', 'darwin/arm64' => '', 'darwin/universal' => '', 'freebsd/amd64' => ''] as $platform => $want) {
    [$os, $arch] = explode('/', $platform);
    same($want, Resources::otherArch($p, $os, $arch), "another architecture's resources than $platform's");
}
foreach ([
    [['resources/bin/server' => '', 'resources/linux-amd64/bin/server' => ''], 'resources/bin/server and resources/linux-amd64/bin/server would both be installed as bin/server'],
    [['resources/linux/x' => '', 'resources/linux-amd64/x' => ''], 'resources/linux/x and resources/linux-amd64/x would both be installed as x'],
    [['resources/Bin/tool' => '', 'resources/linux-amd64/bin/server' => ''], 'resources/Bin and resources/linux-amd64/bin would both be installed as bin'],
    [['resources/bin' => 'a file', 'resources/linux/bin/server' => ''], 'resources/bin and resources/linux/bin would both be installed as bin'],
    [['resources/linux' => 'a file'], 'resources/linux is not a directory'],
    [['resources/darwin-x64/server' => ''], 'resources/darwin-x64 would ship to every platform: name the resources of one platform after its system and architecture, as in resources/darwin-amd64'],
    [['resources/macos/x' => ''], 'as in resources/darwin'], [['resources/Linux-ARM64/x' => ''], 'as in resources/linux-arm64'],
    [['resources/win32-x64/x' => ''], 'as in resources/windows-amd64'], [['resources/linux-x86_64/x' => ''], 'as in resources/linux-amd64'],
] as [$files, $want]) {
    $q = bldDir();
    bldWrite($q, $files);
    check(str_contains(bldThrows(fn() => Resources::collect($q, 'myphp.json', [], 'linux', 'amd64')), $want), "refused: $want");
}
foreach (['mac', 'win', 'js', 'windows-icons', 'linux-', 'darwin-universe'] as $name) {
    check(Resources::platformDirTypo($name) === '' && Resources::platformDir($name) === null, "$name ships to every platform");
}

// A universal app: identical files and links once, arm64 and x86_64 code as universal binaries.
$arm = bldMachO(true, 0x19);
$amd = substr_replace($arm, pack('V', MachO::CPU_AMD64), 4, 4);
$p = bldDir();
bldWrite($p, ['resources/bin/tool' => 'tool', 'resources/darwin/any.txt' => 'any', 'resources/darwin-universal/fat.txt' => 'fat',
    'resources/darwin-arm64/bin/server' => $arm, 'resources/darwin-amd64/bin/server' => $amd, 'resources/darwin-arm64/share/data.txt' => 'same', 'resources/darwin-amd64/share/data.txt' => 'same',
    'resources/darwin-arm64/Helper.app/Contents/MacOS/Helper' => $arm, 'resources/darwin-amd64/Helper.app/Contents/MacOS/Helper' => $amd,
    'resources/darwin-arm64/Helper.app/Contents/Info.plist' => 'plist', 'resources/darwin-amd64/Helper.app/Contents/Info.plist' => 'plist']);
chmod("$p/resources/darwin-arm64/bin/server", 0755);
symlink('server', "$p/resources/darwin-arm64/bin/current");
symlink('server', "$p/resources/darwin-amd64/bin/current");
$out = bldDir();
Resources::copy(Resources::collect($p, 'myphp.json', [], 'darwin', 'universal'), $out);
$got = bldFiles($out);
foreach (['bin/tool' => 'tool', 'any.txt' => 'any', 'fat.txt' => 'fat', 'share/data.txt' => 'same', 'Helper.app/Contents/Info.plist' => 'plist', 'bin/current' => '-> server'] as $name => $want) {
    same($want, $got[$name] ?? null, "universal: $name");
}
foreach (['bin/server', 'Helper.app/Contents/MacOS/Helper'] as $name) {
    $fat = $got[$name];
    $cpus = []; $data = [];
    for ($i = 0, $n = unpack('N', $fat, 4)[1]; $i < $n; $i++) {
        [$cpu, , $off, $size] = array_values(unpack('N4', $fat, 8 + 20 * $i));
        $cpus[] = $cpu; $data[] = substr($fat, $off, $size);
    }
    same([[MachO::CPU_ARM64, MachO::CPU_AMD64], $arm, $amd], [$cpus, $data[0], $data[1]], "a universal binary of $name");
}
same(0755, fileperms("$out/bin/server") & 0777, 'with the permissions of the arm64 one');
$out = bldDir();
Resources::copy(Resources::collect($p, 'myphp.json', [], 'darwin', 'arm64'), $out);
$got = bldFiles($out);
check($got['bin/server'] === $arm && !isset($got['fat.txt']) && $got['any.txt'] === 'any', 'darwin/arm64 gets its own files as they are');
foreach ([
    [['resources/darwin-arm64/data.txt' => 'a', 'resources/darwin-amd64/data.txt' => 'b'], [], 'resources/darwin-arm64/data.txt and resources/darwin-amd64/data.txt differ: a universal app holds one of them (files for both architectures go in resources/darwin)'],
    [['resources/darwin-arm64/bin/only' => '', 'resources/darwin-amd64/bin/x' => ''], [], 'resources/darwin-arm64/bin/only has no counterpart in resources/darwin-amd64/bin to make a universal app with'],
    [['resources/darwin-arm64/x' => '', 'resources/darwin-amd64/x' => '', 'resources/darwin-amd64/y' => ''], [], 'resources/darwin-amd64/y has no counterpart in resources/darwin-arm64 '],
    [['resources/darwin-arm64/x' => ''], [], 'a universal app combines resources/darwin-arm64 with resources/darwin-amd64, which does not exist'],
    [['resources/darwin-amd64/x' => ''], [], 'a universal app combines resources/darwin-amd64 with resources/darwin-arm64, which does not exist'],
    [['resources/darwin-arm64/server' => $arm, 'resources/darwin-amd64/server' => bldMachO(true, 0x19, 0x1d)], [], 'cannot make a universal binary of resources/darwin-arm64/server (arm64 code) and resources/darwin-amd64/server (arm64 code): it takes arm64 and x86_64 code'],
    [['resources/darwin-arm64/server' => $amd, 'resources/darwin-amd64/server' => $arm], [], '(x86_64 code) and resources/darwin-amd64/server (arm64 code): it takes'],
    [['resources/darwin-arm64/server' => $arm, 'resources/darwin-amd64/server' => '#!/bin/sh'], [], '(arm64 code) and resources/darwin-amd64/server (not code)'],
    [['resources/darwin-arm64/x/y' => '', 'resources/darwin-amd64/x' => ''], [], 'resources/darwin-arm64/x and resources/darwin-amd64/x differ'],
    [['resources/darwin/bin/server' => '', 'resources/darwin-arm64/bin/server' => $arm, 'resources/darwin-amd64/bin/server' => $amd], [], 'resources/darwin/bin/server and resources/darwin-arm64/bin/server would both be installed as bin/server'],
    [['resources/darwin-arm64/bin/a' => '', 'resources/darwin-amd64/bin/a' => ''], ['resources/darwin-arm64/bin/l' => 'a', 'resources/darwin-amd64/bin/l' => 'b'], 'resources/darwin-arm64/bin/l and resources/darwin-amd64/bin/l differ'],
] as [$files, $links, $want]) {
    $q = bldDir();
    bldWrite($q, $files);
    foreach ($links as $name => $target) { symlink($target, "$q/$name"); }
    check(str_contains(bldThrows(fn() => Resources::collect($q, 'myphp.json', [], 'darwin', 'universal')), $want), "universal refused: $want");
}
// The native libraries of a universal app combine as the resources do.
$q = bldDir();
bldWrite($q, ['natives/arm/libx.dylib' => $arm, 'natives/amd/libx.dylib' => $amd]);
$list = Resources::collect($q, 'myphp.json', [], 'darwin', 'universal', [['libx.dylib', "$q/natives/arm/libx.dylib", "$q/natives/amd/libx.dylib"]]);
same([['name' => 'libx.dylib', 'src' => "$q/natives/arm/libx.dylib", 'nested' => false, 'lipo' => "$q/natives/amd/libx.dylib"]], $list, 'a native library of both architectures');

// The icon: the sizes macOS expects, each a PNG of its size, scaled as mygo's resize scales them.
$px = '';
for ($y = 0; $y < 64; $y++) {
    for ($x = 0; $x < 64; $x++) {
        $px .= $x < 32 ? "\x80\x40\x20\x80" : "\x00\x00\x00\x00"; // premultiplied (255, 128, 64) at half, then clear
    }
}
$icns = Icns::fromPixels(64, 64, $px);
same(['icns', strlen($icns)], [substr($icns, 0, 4), unpack('N', $icns, 4)[1]], 'the icns header');
$kinds = [];
for ($off = 8; $off < strlen($icns); $off += $size) {
    $kind = substr($icns, $off, 4); $size = unpack('N', $icns, $off + 4)[1];
    $png = substr($icns, $off + 8, $size - 8);
    $kinds[$kind] = unpack('N', $png, 16)[1];
    if ($kind === 'ic11') { // 32 pixels from 64: two by two averaged
        $idat = substr($png, 33 + 8, unpack('N', $png, 33)[1]);
        $raw = gzuncompress($idat);
        same([0, 255, 128, 64, 128], array_values(unpack('C5', $raw)), 'a pixel of the opaque half, averaged and unpremultiplied');
        same([0, 0, 0, 0], array_values(unpack('C4', $raw, 1 + 31 * 4)), 'and of the clear half');
    }
}
same(['ic11' => 32, 'ic12' => 64, 'ic07' => 128, 'ic13' => 256, 'ic08' => 256, 'ic14' => 512, 'ic09' => 512, 'ic10' => 1024], $kinds, 'every entry a PNG of its size, without icp4 and icp5');
$icon = Icns::defaultIcon();
same(["\x89PNG\r\n\x1a\n", 1024, 1024], [substr($icon, 0, 8), unpack('N', $icon, 16)[1], unpack('N', $icon, 20)[1]], 'the icon myphp init gives');

// S3: the PUT Object example of the Signature Version 4 documentation, the URLs of buckets.
$s3 = S3::with('examplebucket', 'us-east-1', 'https://s3.amazonaws.com', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
[$url, $host, $path] = $s3->objectURL('test$file.text');
same(['https://examplebucket.s3.amazonaws.com/test%24file.text', 'examplebucket.s3.amazonaws.com', '/test%24file.text'], [$url, $host, $path], 'a virtual-hosted URL');
$signed = $s3->sign($host, $path, ['Date' => 'Fri, 24 May 2013 00:00:00 GMT', 'X-Amz-Storage-Class' => 'REDUCED_REDUNDANCY'], hash('sha256', 'Welcome to Amazon S3.'), gmmktime(0, 0, 0, 5, 24, 2013));
same('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class, Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
    $signed['Authorization'], 'the signature of the documentation');
$env = fn(array $vars) => array_walk($vars, fn($v, $k) => putenv($v === null ? $k : "$k=$v"));
$env(['AWS_ACCESS_KEY_ID' => 'id', 'AWS_SECRET_ACCESS_KEY' => 'secret', 'AWS_REGION' => '', 'AWS_DEFAULT_REGION' => 'eu-west-1', 'AWS_SESSION_TOKEN' => null]);
foreach ([
    [['bucket' => 'downloads', 'prefix' => '/my-app/'], 'https://downloads.s3.eu-west-1.amazonaws.com/my-app/My%20App%201.2.0.dmg'],
    [['bucket' => 'dl.example.com', 'region' => 'us-west-2'], 'https://s3.us-west-2.amazonaws.com/dl.example.com/My%20App%201.2.0.dmg'],
    [['bucket' => 'downloads', 'endpoint' => 'https://acc.r2.cloudflarestorage.com/'], 'https://acc.r2.cloudflarestorage.com/downloads/My%20App%201.2.0.dmg'],
    [['bucket' => 'downloads', 'endpoint' => 'https://oss-cn-hangzhou.aliyuncs.com', 'pathStyle' => false], 'https://downloads.oss-cn-hangzhou.aliyuncs.com/My%20App%201.2.0.dmg'],
] as [$cfg, $want]) {
    $c = S3::client($cfg);
    same($want, $c->objectURL($c->key('My App 1.2.0.dmg'))[0], 'the URL of ' . json_encode($cfg));
}
$env(['AWS_SECRET_ACCESS_KEY' => '']);
check(str_contains(bldThrows(fn() => S3::client(['bucket' => 'downloads'])), 'AWS_SECRET_ACCESS_KEY'), 'no upload without credentials');

// Uploads to a bucket: the files, then the manifests; their types; S3's errors.
$env(['AWS_ACCESS_KEY_ID' => 'id', 'AWS_SECRET_ACCESS_KEY' => 'secret', 'AWS_SESSION_TOKEN' => 'token', 'AWS_DEFAULT_REGION' => null]);
$www = bldDir();
$log = "$www/put.log";
file_put_contents("$www/router.php", <<<'PHP'
<?php
$body = file_get_contents('php://input');
$h = getallheaders();
$path = $_SERVER['REQUEST_URI'];
$ok = $_SERVER['REQUEST_METHOD'] === 'PUT' && ($h['X-Amz-Content-Sha256'] ?? '') === hash('sha256', $body) && ($h['X-Amz-Security-Token'] ?? '') === 'token'
    && str_starts_with($h['Authorization'] ?? '', 'AWS4-HMAC-SHA256 Credential=id/') && (int)($h['Content-Length'] ?? -1) === strlen($body);
if (str_contains($path, 'denied')) {
    http_response_code(403);
    echo '<?xml version="1.0" encoding="UTF-8"?><Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>';
    return;
}
if (str_contains($path, 'elsewhere')) {
    header('Location: /moved');
    header('X-Amz-Bucket-Region: eu-west-1');
    http_response_code(301);
    return;
}
file_put_contents(getenv('PUT_LOG'), json_encode([$path, $h['Content-Type'] ?? '', $h['Cache-Control'] ?? '', $ok]) . "\n", FILE_APPEND);
PHP);
$port = 20000 + random_int(0, 20000);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$www/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $www, ['PUT_LOG' => $log]);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) { usleep(100_000); }
$dist = bldDir();
$artifacts = [];
foreach (['update-darwin-arm64.json', 'App 1.2.0.dmg', 'app-1.2.0-darwin-arm64.tar.gz', 'app-1.1.0-to-1.2.0-darwin-arm64.delta', 'App.app', 'notes.txt'] as $name) {
    $name === 'App.app' ? mkdir("$dist/$name") : file_put_contents("$dist/$name", $name);
    $artifacts[] = "$dist/$name";
}
$c = ['updates' => ['url' => 'https://dl.example.com/app', 's3' => ['bucket' => 'downloads', 'prefix' => 'app', 'endpoint' => "http://127.0.0.1:$port"]]];
$said = [];
Upload::publish($c, '1.2.0', $artifacts, '', 'myphp/test', 'myphp.json', function (string $l) use (&$said) { $said[] = $l; });
same([
    ['/downloads/app/App%201.2.0.dmg', 'application/x-apple-diskimage', '', true],
    ['/downloads/app/app-1.2.0-darwin-arm64.tar.gz', 'application/gzip', '', true],
    ['/downloads/app/app-1.1.0-to-1.2.0-darwin-arm64.delta', 'application/octet-stream', '', true],
    ['/downloads/app/update-darwin-arm64.json', 'application/json', 'no-cache', true],
], array_map(fn($l) => json_decode($l, true), file($log, FILE_IGNORE_NEW_LINES)), 'uploaded, signed, the manifest last');
same('Uploaded to s3://downloads/app, which https://dl.example.com/app serves', end($said), 'and said where');
$c['updates']['s3']['prefix'] = 'denied';
check(str_contains(bldThrows(fn() => Upload::publish($c, '1.2.0', $artifacts, '', 'myphp/test', 'myphp.json', fn() => null)), 'AccessDenied: Access Denied'), 'S3 says why');
$c['updates']['s3']['prefix'] = 'elsewhere';
$e = bldThrows(fn() => Upload::publish($c, '1.2.0', $artifacts, '', 'myphp/test', 'myphp.json', fn() => null));
check(str_contains($e, '301 Moved Permanently (the bucket is in eu-west-1: set updates.s3.region)'), 'a bucket in another region: ' . $e);
proc_terminate($server);
proc_close($server);
check(str_contains(bldThrows(fn() => Upload::uploads(["$dist/App.app", "$dist/notes.txt"])), 'nothing to upload'), 'nothing to upload');
check(str_contains(bldThrows(fn() => Upload::check(['updates' => ['url' => 'https://x.example']], 'myphp.json')), 'needs updates.github or updates.s3'), '-upload needs somewhere to go');
$env(['AWS_ACCESS_KEY_ID' => null, 'AWS_SECRET_ACCESS_KEY' => null, 'AWS_SESSION_TOKEN' => null, 'AWS_DEFAULT_REGION' => null, 'AWS_REGION' => null]);

// The commands of bin/myphp that run anywhere.
$myphp = fn(string ...$args): array => (function () use ($args): array {
    $p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', dirname(__DIR__) . '/bin/myphp', ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
})();
[$code, $out] = $myphp('version');
same([0, true], [$code, str_starts_with($out, 'myphp ')], 'myphp version');
[$code, $out] = $myphp('frobnicate');
same([2, true], [$code, str_contains($out, 'unknown command "frobnicate"')], 'an unknown command');
[$code, $out] = $myphp('build', '-h');
same([0, true], [$code, str_contains($out, '-skip-notarize')], 'a command\'s flags');
$root = bldDir();
[$code, $out] = $myphp('init', '-name', 'My "Demo" App', "$root/demo");
check($code === 0 && str_contains($out, 'Created My "Demo" App.'), 'myphp init: ' . $out);
$cfg = json_decode(file_get_contents("$root/demo/myphp.json"), true);
same(['My "Demo" App', 'com.example.mydemoapp', '0.1.0', 'app.php', 'build'], [$cfg['name'], $cfg['identifier'], $cfg['version'], $cfg['entry'], $cfg['out']], 'its configuration');
check(str_contains(file_get_contents("$root/demo/app.php"), "App::setName('My \"Demo\" App');"), 'its name in the PHP it writes');
same("\x89PNG", substr(file_get_contents("$root/demo/resources/icon.png"), 0, 4), 'its icon');
check(is_file("$root/demo/.agents/skills/myphp-maintenance/SKILL.md"), 'its skills');
[$code, $out] = $myphp('init', "$root/demo");
check($code !== 0 && str_contains($out, 'is not empty'), 'not into a project');
unlink("$root/demo/.agents/skills/myphp-maintenance/SKILL.md");
file_put_contents("$root/demo/.agents/skills/mine.md", 'mine');
[$code] = $myphp('install-skills', "$root/demo");
check($code === 0 && is_file("$root/demo/.agents/skills/myphp-maintenance/SKILL.md") && is_file("$root/demo/.agents/skills/mine.md"), 'install-skills puts them back, leaving others');

// The Info.plist: what myphp.json says, the documents and URL schemes, the production flag, and the app's own keys, which win.
$c = ['name' => 'A & B', 'identifier' => 'com.example.ab', 'version' => '1.2.3', 'copyright' => '© Us', 'urlSchemes' => ['ab'],
    'fileAssociations' => [['ext' => ['md', 'markdown'], 'name' => 'Markdown Document', 'mimeType' => 'text/markdown'], ['ext' => ['txt'], 'role' => 'Viewer']],
    'macos' => ['minimumSystemVersion' => '12.0', 'infoPlist' => ['NSCameraUsageDescription' => 'Scan documents.', 'LSMinimumSystemVersion' => '13.0']]];
$xml = \MyPHP\Native\Build\Bundle::xml(\MyPHP\Native\Build\Bundle::infoPlist($c, 'A & B', true, false, [], ['MyPHPUpdateFeed' => 'https://x.example/update-darwin-arm64.json'], true));
foreach (["<string>A &amp; B</string>", "<key>CFBundleIdentifier</key>\n\t<string>com.example.ab</string>", "<key>CFBundleIconFile</key>\n\t<string>AppIcon.icns</string>",
    "<key>CFBundleURLSchemes</key>", "<string>ab</string>", "<key>NSHumanReadableCopyright</key>\n\t<string>© Us</string>", "<key>MyPHPProduction</key>\n\t<true/>",
    "<key>LSMinimumSystemVersion</key>\n\t<string>13.0</string>", "<string>Scan documents.</string>", "<string>Markdown Document</string>", "<string>text/markdown</string>",
    "<string>TXT file</string>", "<string>Viewer</string>", "<key>MyPHPUpdateFeed</key>", "<key>NSSupportsAutomaticGraphicsSwitching</key>\n\t<true/>"] as $want) {
    check(str_contains($xml, $want), "Info.plist has $want");
}
$plist = \MyPHP\Native\Plist::parse($xml);
same([true, null], [$plist['MyPHPProduction'], $plist['CFBundleIconName'] ?? null], 'it parses back');
check(!isset(\MyPHP\Native\Build\Bundle::infoPlist($c, 'A', false, true, [], [], false)['MyPHPProduction']), 'a -debug build is no production build');
same('AppIcon', \MyPHP\Native\Build\Bundle::infoPlist($c, 'A', false, true, [], [], false)['CFBundleIconName'], 'the icon of an asset catalog');

// The steps that run macOS's tools, with stand-ins that log what they are asked: a disk image notarized, a draft release, a build.
$stub = bldDir();
$stubLog = "$stub/log";
foreach ([
    'ditto' => 'cp -R "$2" "$3"',
    'hdiutil' => 'case "$1" in create|convert) for a; do last=$a; done; : > "$last";; attach) printf "/dev/disk9\tGUID_partition_scheme\t\n/dev/disk9s1\tApple_HFS\t/x\n";; esac',
    'xcrun' => '[ "$1" = notarytool ] && echo \'{"id":"abc","status":"Accepted"}\'; exit 0',
    'codesign' => 'exit 0',
    'gh' => '[ "$2" = view ] && exit 1; exit 0',
    'cc' => 'while [ $# -gt 0 ]; do [ "$1" = -o ] && printf bin > "$2"; shift; done',
] as $tool => $body) {
    file_put_contents("$stub/$tool", "#!/bin/sh\necho \"$tool \$*\" >> \"$stubLog\"\n$body\n");
    chmod("$stub/$tool", 0755);
}
$path = getenv('PATH');
putenv("PATH=$stub:$path");
$d = bldDir();
mkdir("$d/A.app/Contents", 0755, true);
$said = [];
$dmg = Dmg::build("$d/A.app", $d, 'A', 'A 1.0 arm64.dmg', 'Developer ID Application: X', ['keychainProfile' => 'p', 'keychain' => ''], function (string $l) use (&$said) { $said[] = $l; });
$ran = array_map(fn(string $l) => implode(' ', array_slice(explode(' ', $l), 0, 2)), file($stubLog, FILE_IGNORE_NEW_LINES));
same(["$d/A 1.0 arm64.dmg", ['ditto --noclone', 'hdiutil create', 'hdiutil attach', 'hdiutil detach', 'hdiutil convert', 'codesign --force', 'xcrun notarytool', 'xcrun stapler', 'xcrun stapler']], [$dmg, $ran], 'a disk image: made, signed, notarized, the image and the app stapled');
same(['Created A 1.0 arm64.dmg', 'Notarizing A 1.0 arm64.dmg (usually takes a few minutes)', 'Notarized A 1.0 arm64.dmg'], $said, 'its steps');
same(['.', '..', 'A 1.0 arm64.dmg', 'A.app'], scandir($d), 'its work directory gone');
file_put_contents($stubLog, '');
touch("$d/update-darwin-arm64.json");
Upload::publish(['updates' => ['github' => 'o/r', 'tagPrefix' => 'app-v']], '1.0', ["$d/A.app", $dmg, "$d/update-darwin-arm64.json"], 'notes', 'ua', 'myphp.json', fn() => null);
same(['gh release view app-v1.0 --repo o/r', 'gh release create app-v1.0 --repo o/r --draft --title app-v1.0 --notes notes --latest=false',
    "gh release upload app-v1.0 --repo o/r --clobber $dmg", "gh release upload app-v1.0 --repo o/r --clobber $d/update-darwin-arm64.json"], file($stubLog, FILE_IGNORE_NEW_LINES), 'a draft release, the image, then the manifest');
// myphp build: the app of each platform in its directory, signed.
file_put_contents($stubLog, '');
$root = bldDir();
$myphp('init', "$root/b");
unlink("$root/b/resources/icon.png"); // ImageIO decodes it, on macOS
bldWrite("$root/b", ['resources/darwin-arm64/bin/tool' => 'arm', 'resources/darwin-amd64/bin/tool' => 'amd', 'resources/notes.txt' => 'notes']);
$p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', dirname(__DIR__) . '/bin/myphp', 'build', '-skip-dmg', '-platform', 'darwin/arm64,darwin/amd64', "$root/b"], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
$out = stream_get_contents($pipes[1]);
same(0, proc_close($p), 'myphp build: ' . $out);
check(str_contains($out, 'Built b 0.1.0 in') && str_contains($out, 'build/darwin-amd64/'), 'and its summary');
foreach (['arm64' => 'arm', 'amd64' => 'amd'] as $arch => $tool) {
    $app = "$root/b/build/darwin-$arch/b.app/Contents";
    same([$tool, 'notes', true], [file_get_contents("$app/Resources/bin/tool"), file_get_contents("$app/Resources/notes.txt"), is_file("$app/Resources/app/app.php")], "darwin/$arch: its resources and the app");
    check(!file_exists("$app/Resources/app/resources") && !file_exists("$app/Resources/app/.agents") && !file_exists("$app/Resources/app/build"), "darwin/$arch: without the resources directory, hidden files and the output");
}
$log = file_get_contents($stubLog);
check(str_contains($log, 'cc -O2 -Wall -arch arm64 ') && str_contains($log, 'cc -O2 -Wall -arch x86_64 ') && substr_count($log, 'codesign --force --deep --sign - ') === 2, 'compiled for each architecture and signed ad hoc');
same(['.', '..', 'darwin-amd64', 'darwin-arm64'], scandir("$root/b/build"), 'no staging left');
putenv("PATH=$path");

// myphp dev: the development app built, launched, relaunched as App::relaunch() asks, built again on a change, until it quits.
$stub = bldDir();
file_put_contents("$stub/cc", "#!/bin/sh\nwhile [ \$# -gt 0 ]; do if [ \"\$1\" = -o ]; then printf '#!/bin/sh\\nexec sh \"\$DEVAPP\" \"\$0\"\\n' > \"\$2\"; chmod +x \"\$2\"; fi; shift; done\n");
file_put_contents("$stub/codesign", "#!/bin/sh\nexit 0\n");
chmod("$stub/cc", 0755); chmod("$stub/codesign", 0755);
// The app: asks to be relaunched, then runs until the change replaces it, then quits.
file_put_contents("$stub/app.sh", "echo \"\$MYPHP_DEV \$MYPHP_ENV \$(pwd)\" >> \"\$DEVLOG\"\nn=\$(wc -l < \"\$DEVLOG\")\n[ \$n -eq 1 ] && exit 75\n[ \$n -eq 2 ] && { sleep 5; exit 3; }\nexit 0\n");
$root = bldDir();
$myphp('init', '-name', 'Demo', "$root/d");
unlink("$root/d/resources/icon.png");
file_put_contents("$root/d/resources/data.txt", 'data');
$p = proc_open([PHP_BINARY, '-d', 'ffi.enable=1', dirname(__DIR__) . '/bin/myphp', 'dev'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, "$root/d",
    ['DEVAPP' => "$stub/app.sh", 'DEVLOG' => "$stub/log", 'PATH' => "$stub:" . getenv('PATH')] + getenv());
for ($i = 0; $i < 100 && substr_count((string)@file_get_contents("$stub/log"), "\n") < 2; $i++) { usleep(100_000); }
usleep(300_000);
file_put_contents("$root/d/app.php", "\n// changed\n", FILE_APPEND);
$out = stream_get_contents($pipes[1]);
same(0, proc_close($p), 'myphp dev ends as the app quits: ' . $out);
check(str_contains($out, 'Built Demo Dev') && str_contains($out, 'Relaunched Demo Dev') && str_contains($out, 'Rebuilt Demo Dev (app.php changed)') && str_contains($out, 'Demo Dev quit'), 'built, relaunched, rebuilt, quit');
same(array_fill(0, 3, "1 development $root/d"), file("$stub/log", FILE_IGNORE_NEW_LINES), 'run in the project, told it is under myphp dev');
$devApp = glob("$root/d/.myphp/dev/darwin-*/Demo Dev.app")[0] ?? '';
same(['com.example.demo.dev', 'Demo Dev', 'data'], [\MyPHP\Native\Plist::parse(file_get_contents("$devApp/Contents/Info.plist"))['CFBundleIdentifier'], \MyPHP\Native\Plist::parse(file_get_contents("$devApp/Contents/Info.plist"))['CFBundleName'], file_get_contents("$devApp/Contents/Resources/data.txt")], 'the development app: its own identifier and name, with the resources');

// myphp.config.ts: evaluated by Bun or Node.js, a function of the command running; not along with myphp.json.
$p = bldDir();
$cli = dirname(__DIR__) . '/packages/cli/index.js';
file_put_contents("$p/myphp.config.ts", "import { defineConfig } from " . json_encode($cli) . ";\nconst scheme: string = \"bad-\";\nexport default defineConfig(({ command }) => ({ name: \"TS\", devUrl: scheme + command }));\n");
[$code, $out] = $myphp('generate', $p);
check($code === 1 && str_contains($out, 'myphp.config.ts: devUrl "bad-generate" is not an http(s) URL'), 'the configuration of the command running, checked as myphp.json\'s: ' . $out);
file_put_contents("$p/myphp.config.ts", "export default 3;\n");
[$code, $out] = $myphp('generate', $p);
check($code === 1 && str_contains($out, 'myphp.config.ts:') && str_contains($out, 'not a configuration object'), 'a default export that is no configuration: ' . $out);
file_put_contents("$p/myphp.json", '{}');
[$code, $out] = $myphp('generate', $p);
check($code === 1 && str_contains($out, 'myphp.json configures the project too'), 'one of the two');
