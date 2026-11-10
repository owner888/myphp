<?php
// A window of native UI on Windows (windows_window_test.php): its size in points, the frame
// around it, moving and resizing it, its title, minimizing and restoring, maximizing, full
// screen and back, the events the core hears, closing it; its content drawn by Direct3D 11
// (WARP on CI's runners) and read back: a red square in its top left corner on the background.
declare(strict_types=1);

use MyPHP\{App, Rectangle, Window, WindowOptions};
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php';

$at = function (string $where): void { fwrite(STDERR, "at: $where\n"); fflush(STDERR); };
$r = ['events' => []];
App::setName('WindowTest');
App::whenReady(function () use (&$r, $at) {
    $at('ready');
    $w = new Window(new WindowOptions(title: 'Hello', width: 400, height: 300, content: Content::view(fn() => Color(Color::rgb(0xff0000))->frame(width: 100, height: 100)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading))));
    foreach (['focus', 'blur', 'move', 'resize', 'minimize', 'restore', 'maximize', 'unmaximize', 'enterFullScreen', 'leaveFullScreen', 'closed'] as $e) {
        $w->on($e, function () use (&$r, $e) { $r['events'][] = $e; });
    }
    $w->show();
    // A window of a material: Mica behind its content, which clears to transparent, where Windows draws backdrops (11 22H2, build 22621); its frames composed.
    $mw = new Window(new WindowOptions(title: 'Material', width: 200, height: 150, vibrancy: 'mica', content: Content::view(fn() => Color(Color::rgb(0x0000ff))->frame(width: 20, height: 20)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading))));
    $mw->show();
    $at('shown');
    $step = function (float $seconds, Closure $then) { App::after($seconds, $then); };
    $step(0.3, function () use ($w, $mw, &$r, $at, $step) {
        [$mpix, $mpw] = $mw->native()->lastFrame();
        $ms = $mw->native()->backingScale();
        $alpha = fn(int $x, int $y): ?int => $mpix === '' ? null : ord($mpix[($y * $mpw + $x) * 4 + 3]);
        $r['material'] = [$mw->native()->hasVibrancy(), \MyPHP\Platform\Windows\MenuDraw::build() >= 22621, $mw->native()->lastDraw(), $alpha((int)(5 * $ms), (int)(5 * $ms)), $alpha((int)(100 * $ms), (int)(100 * $ms))];
        $mw->close();
        $r['scale'] = $w->native()->backingScale();
        $r['draw'] = $w->native()->lastDraw();
        [$pix, $pw, $ph] = $w->native()->lastFrame();
        $px = fn(int $x, int $y): array => $pix === '' ? [] : array_values(unpack('C4', substr($pix, ($y * $pw + $x) * 4, 4)));
        $s = $r['scale'];
        $r['frame'] = [$pw, $ph, $px((int)(50 * $s), (int)(50 * $s)), $px((int)(200 * $s), (int)(150 * $s))];
        $png = $w->capturePage();
        $size = getimagesizefromstring($png);
        $r['capture'] = [substr($png, 0, 8) === "\x89PNG\r\n\x1a\n", $size === false ? null : [$size[0], $size[1]]];
        // The app's icon on the window, then the executable's again (WM_GETICON's ICON_BIG); a progress and a flash on its taskbar button.
        $u = FFI::cdef('intptr_t SendMessageW(void *hwnd, uint32_t msg, uintptr_t wp, intptr_t lp);', 'user32.dll');
        \MyPHP\Dock::setIcon(\MyPHP\Native\Wic::png(str_repeat("\0\0\xff\xff", 32 * 32), 32, 32));
        $iconSet = $u->SendMessageW($w->native()->hwnd, 0x007F, 1, 0) !== 0;
        \MyPHP\Dock::setIcon('');
        $r['icon'] = [$iconSet, $u->SendMessageW($w->native()->hwnd, 0x007F, 1, 0) !== 0];
        $w->setProgressBar(new \MyPHP\ProgressBar(value: 0.5));
        $r['bounce'] = \MyPHP\Dock::bounce();
        \MyPHP\Dock::cancelBounce($r['bounce']);
        // The app hidden, its window with it, then shown again; the recent documents told and cleared.
        $app = App::backend()->app();
        $app->hide();
        $hidden = [$app->isHidden(), $w->native()->isVisible()];
        $app->unhide();
        $r['hide'] = [...$hidden, $app->isHidden(), $w->native()->isVisible()];
        $app->addRecentDocument(__FILE__);
        $app->clearRecentDocuments();
        // OLE's drag and drop: a data object of PHP's offering a file and text, read back as another app reads it; dropped on the window, its file told.
        $dd = \MyPHP\Platform\Windows\DragDrop::class;
        $w->on('fileDrop', function ($e) use (&$r) { $r['dropped'] = $e->paths === [__FILE__] ? true : $e->paths; });
        $reps = array_map(fn($f) => \MyPHP\Transfer\Representation::bytes($f, \MyPHP\Transfer\Data::files(__FILE__)->read($f)), [\MyPHP\Transfer\Format::FILE_LIST, \MyPHP\Transfer\Format::URI_LIST]);
        $reps[] = \MyPHP\Transfer\Representation::bytes(\MyPHP\Transfer\Format::TEXT, 'héllo');
        $item = new \MyPHP\Transfer\Item(...$reps);
        $data = $dd::testData(new \MyPHP\Transfer\Data($item), 'session-1');
        $offer = $dd::testOffer($data);
        $text = $dd::testRead($data, 13);
        $r['dragData'] = [array_keys($offer['formats']), $offer['session'], $offer['files'], $text === null ? null : rtrim((string)iconv('UTF-16LE', 'UTF-8', $text), "\0"), $dd::testPaths($data) === [__FILE__]];
        $r['dropEffect'] = $dd::testDrop($w->native(), $data, 100, 100);
        \MyPHP\Native\Com::release($data);
        $r['dragAlive'] = $dd::alive();
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
