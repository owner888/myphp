<?php
// `myphp emulators` (Native\Build\Emulators, as Flutter's): which system image and which device profile an AVD is
// made of when none is named — the newest image for this machine's architecture, minSdk 31 and over, Google Play's
// first; the newest Pixel, a Pro first — and the command's list without an Android SDK.
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\Native\Build\Emulators;

$images = [
    'system-images;android-30;google_apis;arm64-v8a',
    'system-images;android-35;google_apis;arm64-v8a',
    'system-images;android-36;default;arm64-v8a',
    'system-images;android-36;google_apis_playstore;arm64-v8a',
    'system-images;android-36;google_apis_playstore;x86_64',
    'system-images;android-37;google_apis;x86_64',
];
same('system-images;android-36;google_apis_playstore;arm64-v8a', Emulators::newestImage($images, 'arm64-v8a'), 'the highest API for the ABI, Google Play\'s over the plain one');
same('system-images;android-37;google_apis;x86_64', Emulators::newestImage($images, 'x86_64'), 'on Intel, the x86_64 images');
same(null, Emulators::newestImage(['system-images;android-30;google_apis;arm64-v8a'], 'arm64-v8a'), 'none under minSdk 31');

// What `sdkmanager --list` offers: the images of its sections, each once.
$list = <<<'TXT'
Installed packages:
  Path                                                      | Version | Description
  -------                                                   | ------- | -------
  system-images;android-35;google_apis;arm64-v8a            | 9       | Google APIs ARM 64 v8a System Image

Available Packages:
  Path                                                      | Version | Description
  system-images;android-35;google_apis;arm64-v8a            | 9       | Google APIs ARM 64 v8a System Image
  system-images;android-36;google_apis_playstore;arm64-v8a  | 7       | Google Play ARM 64 v8a System Image
  system-images;android-36;google_apis_playstore;x86_64     | 7       | Google Play Intel x86_64 Atom System Image
  platforms;android-36                                      | 2       | Android SDK Platform 36
TXT;
same(['system-images;android-35;google_apis;arm64-v8a', 'system-images;android-36;google_apis_playstore;arm64-v8a', 'system-images;android-36;google_apis_playstore;x86_64'], Emulators::imagesOf($list), 'the images listed, each once, nothing else');
same('system-images;android-36;google_apis_playstore;arm64-v8a', Emulators::newestImage(Emulators::imagesOf($list), 'arm64-v8a'), 'the one --create downloads when none is installed');

$profiles = ['pixel_7', 'pixel_9_pro_fold', 'pixel_11', 'pixel_11_pro', 'pixel_11_pro_fold', 'pixel_10_pro', 'Nexus 5', 'medium_phone'];
same('pixel_11_pro', Emulators::newestPixel($profiles), 'the highest number, the Pro over the plain one and the Fold');
same('pixel_11', Emulators::newestPixel(['pixel_10_pro', 'pixel_11', 'pixel_11a']), 'the plain one over an a');
same(null, Emulators::newestPixel(['Nexus 5', 'medium_phone']), 'no Pixel, none');

same(in_array(strtolower(php_uname('m')), ['arm64', 'aarch64'], true) ? 'arm64-v8a' : 'x86_64', Emulators::abi(), 'the ABI this machine runs natively');

// hw.keyboard=yes, which avdmanager leaves no and without which the emulator's Back and Home do nothing.
$avds = sys_get_temp_dir() . '/myphp-avd-' . getmypid();
@mkdir("$avds/a.avd", 0755, true); @mkdir("$avds/b.avd", 0755, true);
file_put_contents("$avds/a.avd/config.ini", "hw.lcd.density=480\nhw.keyboard=no\nhw.ramSize=2048\n");
file_put_contents("$avds/b.avd/config.ini", "hw.lcd.density=480\n");
$wasAvd = getenv('ANDROID_AVD_HOME'); putenv("ANDROID_AVD_HOME=$avds");
check(Emulators::keyboard('a') && Emulators::keyboard('b'), 'changed where it was no, or missing');
same("hw.lcd.density=480\nhw.keyboard=yes\nhw.ramSize=2048\n", file_get_contents("$avds/a.avd/config.ini"), 'no became yes, the rest kept');
same("hw.lcd.density=480\nhw.keyboard=yes\n", file_get_contents("$avds/b.avd/config.ini"), 'missing, added');
check(!Emulators::keyboard('a'), 'yes already: nothing to change');
putenv($wasAvd === false ? 'ANDROID_AVD_HOME' : "ANDROID_AVD_HOME=$wasAvd");

// Without an SDK (none here): no AVDs; the iOS Simulator only on a Mac with Xcode.
$was = [getenv('ANDROID_HOME'), getenv('HOME')];
putenv('ANDROID_HOME'); putenv('HOME=' . sys_get_temp_dir() . '/myphp-no-home');
$ids = array_map(fn($e) => $e->id, Emulators::list());
check(!array_filter($ids, fn($id) => $id !== Emulators::IOS) || \MyPHP\Native\Build\Cmd::look('emulator') !== '', 'no SDK, no AVDs: ' . json_encode($ids));
putenv($was[0] === false ? 'ANDROID_HOME' : "ANDROID_HOME={$was[0]}"); putenv("HOME={$was[1]}");

// The shaders' GLSL ES on Android: 3.20 on a phone's GPU, 3.10 with texture buffers, else 3.00 reading rows of 2D textures (the emulator's on a Mac).
use MyPHP\Native\GlShader;
same("#version 320 es\n", GlShader::esVersion('OpenGL ES GLSL ES 3.20', ''), 'GLSL ES 3.2: as it is');
same("#version 310 es\n#extension GL_EXT_texture_buffer : require\n", GlShader::esVersion('OpenGL ES GLSL ES 3.10', 'GL_OES_EGL_image GL_EXT_texture_buffer GL_EXT_color_buffer_float'), 'GLSL ES 3.1 with EXT_texture_buffer: 310 and the extension');
same("#version 310 es\n#extension GL_OES_texture_buffer : require\n", GlShader::esVersion('OpenGL ES GLSL ES 3.10', 'GL_OES_texture_buffer'), 'or OES_texture_buffer');
same("#version 300 es\n", GlShader::esVersion('OpenGL ES GLSL ES 3.00', ''), 'GLSL ES 3.0: 300');
same("#version 300 es\n", GlShader::esVersion('OpenGL ES GLSL ES 3.10', ''), '3.1 without texture buffers: 300 too');
check(GlShader::texelRows("#version 300 es\n") && !GlShader::texelRows("#version 310 es\n#extension GL_EXT_texture_buffer : require\n") && !GlShader::texelRows("#version 320 es\n"), 'rows of 2D textures only on 300');
$threw = null;
try { GlShader::esVersion('OpenGL ES GLSL ES 1.00', ''); } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
check($threw !== null && str_contains($threw, '1.00'), 'GLSL ES 1.0: says so: ' . $threw);
putenv('MYPHP_PLATFORM=android');
$rows = GlShader::adapt(GlShader::COMMON . "\n" . GlShader::VERTEX, "#version 300 es\n");
$buffers = GlShader::adapt(GlShader::COMMON . "\n" . GlShader::VERTEX, "#version 320 es\n");
putenv('MYPHP_PLATFORM');
check(str_starts_with($rows, "#version 300 es\n") && !str_contains($rows, 'samplerBuffer') && !preg_match('/texelFetch\((uQuads|uXforms),/', $rows)
    && str_contains($rows, 'uniform highp sampler2D uQuads;') && str_contains($rows, 'texelRow(uXforms, e * 4)'), 'on 300 the quads and transforms are read from rows of 2D textures');
check(str_contains($buffers, 'uniform samplerBuffer uQuads;') && !str_contains($buffers, 'texelRow'), 'on 320 from texture buffers, as they were');
