<?php
declare(strict_types=1);

namespace MyPHP\Tests;

use MyPHP\AppHandler;
use MyPHP\Platform;
use MyPHP\Platform\iOS\{Backend, Clipboard, Network, Power, Screen, Secrets, Shell, Symbols, Theme, Window};
use MyPHP\WindowOptions;

require_once __DIR__ . '/Headless.php';

$b = new Backend(new AppHandler());
same('ios/metal', $b->name(), 'iOS backend identifier');

// 1. Single full-screen window constraint
$ref = new \ReflectionClass(\MyPHP\Window::class);
$w1 = $ref->newInstanceWithoutConstructor();
$win1 = $b->newWindow($w1, new WindowOptions(), false);
same(true, $win1->isFullscreen(), 'first iOS window is full-screen');

$failed = false;
try {
    $w2 = $ref->newInstanceWithoutConstructor();
    $b->newWindow($w2, new WindowOptions(), false);
} catch (Platform\Unsupported $e) {
    $failed = true;
}
same(true, $failed, 'second new Window throws Unsupported on iOS');

// 2. Screen & Display metrics
$screen = $b->screen();
$displays = $screen->displays();
same(1, count($displays), 'single display on iPhone');
same(true, $displays[0]->primary, 'primary display');

// 3. Theme preferences
$theme = $b->theme();
$prefs = $theme->preferences();
same(true, is_bool($theme->isDark()), 'theme dark mode detected');
same(true, is_int($prefs->accent), 'theme accent color is integer');

// 4. Power keep awake
$power = $b->power();
$release = $power->keepAwake(true, 'testing');
same(true, is_callable($release), 'keepAwake returns release closure');
$release();
same(true, true, 'power keepAwake toggles without error');

// 5. Network status
$net = $b->network();
$status = $net->status();
same(true, is_bool($status->online), 'network online status is boolean');
same(true, in_array($status->type, ['wifi', 'cellular', 'none'], true), 'valid network type');

// 6. Symbols support
$sym = $b->symbols();
same(true, is_bool($sym->exists('gear')), 'SF Symbols exists check returns boolean');

// 7. Clipboard formats
$cb = $b->clipboard();
same([\MyPHP\Transfer\Format::TEXT], $cb->availableFormats(), 'clipboard supports plain text');

// 8. Notifications supported
same(true, $b->notificationsSupported(), 'iOS notifications are supported');

// 9. Shell beep / haptic feedback
$shell = $b->shell();
$shell->beep();
same(true, true, 'shell beep triggers without error');

// 10. Media outputs
$media = $b->media();
same(true, is_array($media->audioOutputs()), 'iOS audio outputs returned as array');

$win1->close();
same(true, $win1->isClosed(), 'window closes successfully');
