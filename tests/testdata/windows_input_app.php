<?php
// The mouse and the wheel on Windows (windows_input_test.php): Win32's messages sent to a window
// of native UI and what its core window's Input heard of them, in points; a click tapping a view.
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Platform\Windows\Win32;
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php';

$r = ['taps' => 0];
App::setName('InputTest');
App::whenReady(function () use (&$r) {
    $w = new Window(new WindowOptions(title: 'Input', width: 400, height: 300, content: Content::view(fn() => Color(Color::rgb(0xff0000))->frame(width: 100, height: 100)->onTapGesture(function () use (&$r) { $r['taps']++; })->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading))));
    $w->show();
    $u = Win32::user32();
    $hwnd = $w->native()->hwnd;
    $s = $w->native()->backingScale();
    // A point's pixels as a message's lParam; one on the screen, as the wheel's.
    $at = fn(float $x, float $y): int => ((int)round($y * $s) << 16) | ((int)round($x * $s) & 0xFFFF);
    $screen = function (float $x, float $y) use ($u, $hwnd, $s): int {
        $p = $u->new('POINT');
        $p->x = (int)round($x * $s); $p->y = (int)round($y * $s);
        $u->ClientToScreen($hwnd, FFI::addr($p));
        return (($p->y & 0xFFFF) << 16) | ($p->x & 0xFFFF);
    };
    $send = fn(int $m, int $wp, int $lp) => $u->SendMessageW($hwnd, $m, $wp, $lp);
    App::after(0.4, function () use ($w, $u, $send, $at, $screen, &$r) {
        $send(Win32::WM_LBUTTONDOWN, 0x1, $at(50, 50));
        $send(Win32::WM_LBUTTONUP, 0, $at(50, 50));
        App::after(0.3, function () use ($w, $u, $send, $at, $screen, &$r) {
            $r['tapped'] = $r['taps'];
            $in = $w->input;
            $in->events = [];
            $send(Win32::WM_MOUSEMOVE, 0, $at(60, 70));
            $r['inside'] = [$in->mouseInside, round($in->mouseX), round($in->mouseY)];
            $send(Win32::WM_LBUTTONDOWN, 0x1, $at(60, 70));
            $send(Win32::WM_MOUSEMOVE, 0x1, $at(61, 70));
            $send(Win32::WM_LBUTTONUP, 0, $at(61, 70));
            $send(Win32::WM_LBUTTONDOWN, 0x1, $at(61, 70));
            $send(Win32::WM_LBUTTONUP, 0, $at(61, 70));
            $send(Win32::WM_RBUTTONDOWN, 0x2, $at(61, 70));
            $send(Win32::WM_RBUTTONUP, 0, $at(61, 70));
            $send(Win32::WM_MOUSEWHEEL, 120 << 16, $screen(80, 90));
            $send(Win32::WM_MOUSEHWHEEL, 120 << 16, $screen(80, 90));
            $send(Win32::WM_MOUSEWHEEL, (40 << 16), $screen(80, 90));
            $send(Win32::WM_MOUSEWHEEL, (60 << 16) | 0x8, $screen(80, 90));
            $send(Win32::WM_XBUTTONUP, 1 << 16, $at(80, 90));
            $send(Win32::WM_XBUTTONUP, 2 << 16, $at(80, 90));
            $send(Win32::WM_MOUSELEAVE, 0, 0);
            $r['left'] = $in->mouseInside;
            // Each as the test reads it: its kind, where in whole points, and what else it carries.
            $r['events'] = array_map(fn(array $e) => match ($e[0]) {
                'move', 'drag' => [$e[0], round($e[1]), round($e[2])],
                'down', 'up' => [$e[0], round($e[1]), round($e[2]), $e[3], $e[4], $e[5]],
                'scroll' => [$e[0], round($e[1]), round($e[2]), round($e[3], 3), round($e[4], 3), $e[5]],
                'magnify' => [$e[0], round($e[1]), round($e[2]), round($e[3], 4)],
                default => [$e[0], $e[2], $e[3]],
            }, $in->events);
            $n = $u->new('uint32_t');
            $r['lines'] = $u->SystemParametersInfoW(0x68, 0, FFI::addr($n), 0) !== 0 && $n->cdata > 0 && $n->cdata < 100 ? $n->cdata : 3;
            $w->close();
            echo json_encode($r), "\n";
            App::quit();
        });
    });
});
App::run();
