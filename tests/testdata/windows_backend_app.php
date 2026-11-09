<?php
// An app on the Windows backend without windows (windows_backend_test.php): the event loop
// (ready, later, timers in order, a wait turning the loop), the paths, the displays, the
// appearance and preferences, the languages and regional formats, the clipboard, power, the
// shell. Prints JSON and quits.
declare(strict_types=1);

use MyPHP\{App, Clipboard, PathName, Screen, Shell};

require __DIR__ . '/../../src/autoload.php';

$r = ['events' => []];
$at = function (string $where): void { fwrite(STDERR, "at: $where\n"); }; // where it got, for a run that hangs
$at('start');
App::setName('BackendTest');
App::whenReady(function () use (&$r, $at) {
    $at('ready');
    $r['events'][] = 'ready';
    App::later(function () use (&$r) { $r['events'][] = 'later'; });
    App::after(0.08, function () use (&$r) { $r['events'][] = 'after 80 ms'; });
    App::after(0.03, function () use (&$r) { $r['events'][] = 'after 30 ms'; });
    App::after(0, function () use (&$r) { $r['events'][] = 'after 0'; });
    App::after(0.2, function () use (&$r, $at) {
        $at('the timers ran');
        $start = microtime(true);
        $done = false;
        App::after(0.1, function () use (&$done) { $done = true; });
        App::wait(fn() => $done);
        $r['waited'] = round(microtime(true) - $start, 2);
        $at('waited');
        $r['userData'] = App::path(PathName::UserData);
        $r['appData'] = getenv('APPDATA');
        $r['displays'] = array_map(fn($d) => ['label' => $d->label, 'w' => $d->bounds->width, 'h' => $d->bounds->height, 'scale' => $d->scaleFactor, 'primary' => $d->primary], Screen::displays());
        $c = Screen::cursorScreenPoint();
        $r['cursor'] = [$c->x, $c->y];
        $b = App::backend();
        $r['dark'] = $b->theme()->isDark();
        $p = $b->theme()->preferences();
        $r['preferences'] = ['accent' => $p->accent === null ? null : sprintf('#%06X', $p->accent), 'reduceMotion' => $p->reduceMotion, 'reduceTransparency' => $p->reduceTransparency, 'highContrast' => $p->highContrast, 'textScale' => $p->textScale];
        $r['languages'] = $b->app()->preferredLanguages();
        $region = $b->app()->region();
        $r['region'] = [$region->locale, $region->firstWeekday, $region->hourCycle];
        $r['doubleClick'] = $b->doubleClickInterval();
        $at('the system read');
        try {
            Clipboard::writeText('myphp 剪贴板 ✓');
            $r['clipboard'] = Clipboard::readText();
            $r['clipboardFormats'] = Clipboard::availableFormats();
        } catch (Throwable $e) { $r['clipboard'] = 'error: ' . $e->getMessage(); }
        $at('the clipboard');
        $release = $b->power()->keepAwake(true, 'test');
        $release();
        $r['idle'] = $b->power()->idleTime() >= 0;
        $r['battery'] = [$b->power()->onBattery(), $b->power()->batteryLevel()];
        $r['local'] = [Shell::isLocal(__FILE__), Shell::isLocal('\\\\server\\share\\x')];
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        $at('quitting');
        App::quit();
        $at('quit asked');
    });
});
App::run();
$at('the loop ended');
