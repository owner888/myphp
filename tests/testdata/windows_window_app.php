<?php
// A window of native UI on Windows (windows_window_test.php): its size in points, the frame
// around it, moving and resizing it, its title, minimizing and restoring, maximizing, full
// screen and back, the events the core hears, closing it. Its text is DirectWrite's; the
// content shows the background alone till the renderer's stage.
declare(strict_types=1);

use MyPHP\{App, Rectangle, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php';

$at = function (string $where): void { fwrite(STDERR, "at: $where\n"); fflush(STDERR); };
$r = ['events' => []];
App::setName('WindowTest');
App::whenReady(function () use (&$r, $at) {
    $at('ready');
    $w = new Window(new WindowOptions(title: 'Hello', width: 400, height: 300, content: Content::view(fn() => Text('Hello'))));
    foreach (['focus', 'blur', 'move', 'resize', 'minimize', 'restore', 'maximize', 'unmaximize', 'enterFullScreen', 'leaveFullScreen', 'closed'] as $e) {
        $w->on($e, function () use (&$r, $e) { $r['events'][] = $e; });
    }
    $w->show();
    $at('shown');
    $step = function (float $seconds, Closure $then) { App::after($seconds, $then); };
    $step(0.3, function () use ($w, &$r, $at, $step) {
        $r['scale'] = $w->native()->backingScale();
        $r['content'] = $w->contentSize();
        $b = $w->bounds(); $c = $w->contentBounds();
        $r['frameAround'] = $b->width > $c->width && $b->height > $c->height;
        $r['visible'] = [$w->native()->isVisible(), $w->native()->showing()];
        $w->setTitle('Renamed');
        $r['title'] = $w->title();
        $w->setBounds(new Rectangle(50, 60, 500, 400));
        $b = $w->bounds();
        $r['bounds'] = [$b->x, $b->y, $b->width, $b->height];
        $w->setContentSize(320, 200);
        $r['contentSet'] = $w->contentSize();
        $at('sized');
        $w->minimize();
        $step(0.3, function () use ($w, &$r, $at, $step) {
            $r['minimized'] = $w->native()->isMinimized();
            $w->restore();
            $step(0.3, function () use ($w, &$r, $at, $step) {
                $w->maximize();
                $step(0.3, function () use ($w, &$r, $at, $step) {
                    $r['maximized'] = $w->isMaximized();
                    $w->native()->unmaximize();
                    $w->setFullScreen(true);
                    $r['fullScreen'] = [$w->isFullScreen(), $w->bounds()->width >= $w->native()->bounds()->width];
                    $w->setFullScreen(false);
                    $r['afterFullScreen'] = $w->isFullScreen();
                    $at('states');
                    $step(0.3, function () use ($w, &$r, $at) {
                        $w->close();
                        $r['closed'] = $w->native()->isDead();
                        echo json_encode($r), "\n";
                        $at('quitting');
                        App::quit();
                    });
                });
            });
        });
    });
});
App::run();
$at('the loop ended');
