<?php
// AppImage: production PHP bundle compaction (comments & whitespace stripped while preserving exact line numbers and
// #[Attribute] metadata), platform-specific framework pruning, MYPHPAP1 binary encoding/decoding, and mobile native
// binary packaging (`libapp.so` on Android, `__TEXT,__const` on iOS) with zero `.php` source files shipped in release.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Build\AppImage;
use MyPHP\UI\Reactive\{Observer, Scope};

// 1. Line-preserving PHP compaction keeps exact line numbers, #[Attribute] annotations, and Observer closure tracking.
$sample = <<<'PHP'
<?php
declare(strict_types=1);

/**
 * Multi-line docblock on lines 4..6
 */
#[SomeAttribute('keep-me')]
final class SampleBox
{
    public string $title = 'hello'; // inline comment
    public int $clicks = 0;

    public function view(): \Closure
    {
        /* block
           comment */
        return fn() => [
            $this->title,
            fn() => $this->clicks++,
        ];
    }
}
return __LINE__;
PHP;

$compacted = AppImage::compactPhp($sample);
same(substr_count($sample, "
"), substr_count($compacted, "
"), 'compactPhp preserves exact newline count');
check(!str_contains($compacted, 'Multi-line docblock') && !str_contains($compacted, 'inline comment') && !str_contains($compacted, 'block'), 'comments and docblocks stripped');
check(str_contains($compacted, "#[SomeAttribute('keep-me')]"), 'PHP 8 #[Attribute] preserved');
check(strlen($compacted) < strlen($sample), 'compacted source is smaller than raw source');

$tmpFile = sys_get_temp_dir() . '/myphp-appimage-sample-' . getmypid() . '.php';
file_put_contents($tmpFile, $compacted);
$endLine = require $tmpFile;
same(23, $endLine, '__LINE__ matches original source line number');
$box = new \SampleBox();
$scope = new Scope(null);
Observer::run($scope, static fn() => null, $box->view());
$tracked = [];
foreach ($scope->targets() as [$ref, $prop]) {
    $tracked[] = $prop;
}
same(['title'], $tracked, 'Observer tracks outer reactive property and skips nested action closure on compacted file');
@unlink($tmpFile);

// 2. Framework platform pruning removes other OS backends, build tools, C/ObjC sources and .test.ts files.
same(true, AppImage::keepFrameworkFile('Platform/Android/Window.php', 'android'), 'Android keeps Platform/Android');
same(false, AppImage::keepFrameworkFile('Platform/Darwin/Window.php', 'android'), 'Android drops Platform/Darwin');
same(false, AppImage::keepFrameworkFile('Platform/Windows/Window.php', 'android'), 'Android drops Platform/Windows');
same(false, AppImage::keepFrameworkFile('Platform/Linux/Window.php', 'android'), 'Android drops Platform/Linux');
same(false, AppImage::keepFrameworkFile('Platform/iOS/Window.php', 'android'), 'Android drops Platform/iOS');
same(false, AppImage::keepFrameworkFile('Platform/Android/launcher/myphp_launcher.c', 'android'), 'C launcher source excluded');
same(false, AppImage::keepFrameworkFile('Platform/iOS/launcher/main.m', 'ios'), 'ObjC launcher source excluded');
same(false, AppImage::keepFrameworkFile('Native/bridge.test.ts', 'darwin'), '.test.ts excluded');
same(false, AppImage::keepFrameworkFile('Native/Build/Dmg.php', 'ios'), 'Native/Build/Dmg excluded from runtime');
same(true, AppImage::keepFrameworkFile('Native/Build/Png.php', 'windows'), 'Windows keeps Native/Build/Png for Tray/MenuDraw/Clipboard');
same(true, AppImage::keepFrameworkFile('Platform/Linux/Keys.php', 'windows'), 'Windows keeps Linux/Keys shared keymap');
same(true, AppImage::keepFrameworkFile('Platform/Android/Symbols.php', 'linux'), 'Linux keeps Material Symbols fallback');

// 3. Binary MYPHPAP1 encode / decode roundtrip (sorted table, raw DEFLATE + stored small files).
$inputFiles = [
    'z/last.php' => "<?php
return 'last';
",
    'app.php' => str_repeat("<?php // compressible payload
\$x = 'hello world';
", 40),
    'tiny.txt' => 'ok',
    'locales/zh-Hans.json' => '{"hello":"你好"}',
];
$blob = AppImage::encode($inputFiles);
same(AppImage::MAGIC, substr($blob, 0, 8), 'starts with MYPHPAP1 magic');
$decoded = AppImage::decode($blob);
same(['app.php', 'locales/zh-Hans.json', 'tiny.txt', 'z/last.php'], array_keys($decoded), 'entries sorted lexicographically for binary search');
same($inputFiles['app.php'], $decoded['app.php'], 'DEFLATE-compressed entry roundtrips byte-for-byte');
same('ok', $decoded['tiny.txt'], 'small stored entry roundtrips byte-for-byte');
same('{"hello":"你好"}', $decoded['locales/zh-Hans.json'], 'UTF-8 JSON entry roundtrips byte-for-byte');

// 4. Mobile blob builder & assembler generation (`libapp.so` ELF / iOS Mach-O).
$root = dirname(__DIR__);
$mobileBlob = AppImage::buildMobileBlob($root, "$root/examples/counter-native", "$root/examples/counter-native/main.php", 'android');
$mobileMap = AppImage::decode($mobileBlob);
check(isset($mobileMap['app.php']) && isset($mobileMap['src/autoload.php']) && isset($mobileMap['src/Platform/Android/Backend.php']), 'Android mobile blob holds app.php, autoload.php, and Android backend');
check(!isset($mobileMap['src/Platform/Darwin/Backend.php']) && !isset($mobileMap['src/Native/Build/Dmg.php']), 'Android mobile blob prunes Darwin backend and Build tools');
check(strlen($mobileBlob) < (3 << 20), sprintf('compressed mobile blob is compact (%d KB)', strlen($mobileBlob) >> 10));

$elfAsm = AppImage::assemblySource('/tmp/app.bin', false);
$machAsm = AppImage::assemblySource('/tmp/app.bin', true);
check(str_contains($elfAsm, '.section .rodata') && str_contains($elfAsm, 'myphp_app_blob:'), 'ELF assembly exports myphp_app_blob in .rodata');
check(str_contains($machAsm, '.section __TEXT,__const') && str_contains($machAsm, '_myphp_app_blob:'), 'Mach-O assembly exports _myphp_app_blob in __TEXT,__const');

// 5. Release scripts wire `libapp.so` (Android) and `__TEXT,__const` (iOS) while keeping debug hot reload intact.
$androidSh = (string)file_get_contents("$root/scripts/package-android-apk.sh");
check(str_contains($androidSh, 'lib/arm64-v8a/libapp.so') && str_contains($androidSh, 'AppImage::buildMobileBlob'), 'package-android-apk.sh builds libapp.so in release mode');
check(str_contains($androidSh, 'if [ "${MYPHP_ANDROID_DEBUGGABLE:-}" = "1" ]; then'), 'package-android-apk.sh preserves source assets in debug mode for myphp run');

$iosSh = (string)file_get_contents("$root/scripts/package-ios-app.sh");
check(str_contains($iosSh, 'MYPHP_IOS_RELEASE') && str_contains($iosSh, '_myphp_app_blob'), 'package-ios-app.sh links __TEXT,__const app image in release mode');
