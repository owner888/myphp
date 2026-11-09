<?php
// The mouse, the wheel and the keys on Windows (windows_input_test.php): Win32's messages sent to a
// window of native UI and what its core window's Input heard of them, in points; a click tapping a
// view; the input context the window's only while a field takes text, which WM_CHAR types into.
declare(strict_types=1);

use MyPHP\{App, Window, WindowOptions};
use MyPHP\Platform\Windows\Win32;
use MyPHP\UI\Content;

require __DIR__ . '/../../src/autoload.php';

$r = ['taps' => 0, 'window' => [], 'steps' => []];
App::setName('InputTest');
App::whenReady(function () use (&$r) {
    // Made out here: the view's arrow function would hold a copy of $r, which the tap would count in.
    $tap = function () use (&$r) { $r['taps']++; };
    $w = new Window(new WindowOptions(title: 'Input', width: 400, height: 300, content: Content::view(fn() => Color(Color::rgb(0xff0000))->frame(width: 100, height: 100)->onTapGesture($tap)->frame(maxWidth: INF, maxHeight: INF, alignment: Alignment::topLeading))));
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
    foreach (['focus', 'blur'] as $e) {
        $w->on($e, function () use (&$r, $e) { $r['window'][] = $e; });
    }
    // Each event as the test reads it: its kind, where in whole points, and what else it carries.
    $plain = fn(array $e) => match ($e[0]) {
        'move', 'drag' => [$e[0], round($e[1]), round($e[2])],
        'down', 'up' => [$e[0], round($e[1]), round($e[2]), $e[3], $e[4], $e[5]],
        'scroll' => [$e[0], round($e[1]), round($e[2]), round($e[3], 3), round($e[4], 3), $e[5]],
        'magnify' => [$e[0], round($e[1]), round($e[2]), round($e[3], 4)],
        'key', 'keyup' => [$e[0], $e[1], $e[2], $e[3]],
        default => [$e[0], $e[1] ?? null],
    };
    // A message sent, and what the Input heard of it: by its name, for the test to tell each apart.
    $step = function (string $name, int $m, int $wp, int $lp) use ($w, $send, $plain, &$r): void {
        $w->input->events = [];
        $send($m, $wp, $lp);
        $r['steps'][] = [$name, array_map($plain, $w->input->events), $w->input->mouseDown];
    };
    App::after(0.4, function () use ($w, $u, $send, $step, $at, $screen, &$r) {
        $send(Win32::WM_LBUTTONDOWN, 0x1, $at(50, 50));
        $r['afterDown'] = array_map(fn(array $e) => $e[0], $w->input->events);
        $send(Win32::WM_LBUTTONUP, 0, $at(50, 50));
        $r['afterUp'] = array_map(fn(array $e) => $e[0], $w->input->events);
        App::after(0.3, function () use ($w, $u, $send, $step, $at, $screen, &$r) {
            $r['tapped'] = $r['taps'];
            $in = $w->input;
            $step('move', Win32::WM_MOUSEMOVE, 0, $at(60, 70));
            $r['inside'] = [$in->mouseInside, round($in->mouseX), round($in->mouseY)];
            $step('press', Win32::WM_LBUTTONDOWN, 0x1, $at(60, 70));
            $step('drag', Win32::WM_MOUSEMOVE, 0x1, $at(61, 70));
            $step('release', Win32::WM_LBUTTONUP, 0, $at(61, 70));
            $step('press again', Win32::WM_LBUTTONDOWN, 0x1, $at(61, 70));
            $step('release again', Win32::WM_LBUTTONUP, 0, $at(61, 70));
            $step('right press', Win32::WM_RBUTTONDOWN, 0x2, $at(61, 70));
            $step('right release', Win32::WM_RBUTTONUP, 0, $at(61, 70));
            $step('wheel', Win32::WM_MOUSEWHEEL, 120 << 16, $screen(80, 90));
            $step('wheel across', Win32::WM_MOUSEHWHEEL, 120 << 16, $screen(80, 90));
            $step('touchpad', Win32::WM_MOUSEWHEEL, 40 << 16, $screen(80, 90));
            $step('pinch', Win32::WM_MOUSEWHEEL, (60 << 16) | 0x8, $screen(80, 90));
            $step('back', Win32::WM_XBUTTONUP, 1 << 16, $at(80, 90));
            $step('forward', Win32::WM_XBUTTONUP, 2 << 16, $at(80, 90));
            $step('leave', Win32::WM_MOUSELEAVE, 0, 0);
            $r['left'] = $in->mouseInside;
            // A key's lParam: one press, its scancode, whether it is extended, whether it was down, whether it goes up.
            $key = fn(int $scan, bool $extended = false, bool $up = false): int => 1 | ($scan << 16) | ($extended ? 1 << 24 : 0) | ($up ? (1 << 30) | (1 << 31) : 0);
            $step('A down', Win32::WM_KEYDOWN, 0x41, $key(0x1E));
            $step('a typed', Win32::WM_CHAR, 0x61, $key(0x1E));
            $step('A up', Win32::WM_KEYUP, 0x41, $key(0x1E, up: true));
            $step('a control character', Win32::WM_CHAR, 0x08, $key(0x0E));
            $send(Win32::WM_CHAR, 0xD83D, 0);
            $step('an emoji, of two halves', Win32::WM_CHAR, 0xDE00, 0);
            $step('left', Win32::WM_KEYDOWN, 0x25, $key(0x4B, extended: true));
            $step('its character, none', Win32::WM_CHAR, 0x25, 0);
            // Ctrl held, as the thread's keyboard state has it, which the window reads.
            $kb = FFI::cdef('int32_t GetKeyboardState(uint8_t *s); int32_t SetKeyboardState(uint8_t *s);', 'user32.dll');
            $state = $kb->new('uint8_t[256]');
            $kb->GetKeyboardState($state);
            $state[0x11] = 0x80; $state[0xA2] = 0x80;
            $kb->SetKeyboardState($state);
            $step('Ctrl+C', Win32::WM_KEYDOWN, 0x43, $key(0x2E));
            $step('Ctrl+C\'s character, none', Win32::WM_CHAR, 0x03, $key(0x2E));
            $state[0x11] = 0; $state[0xA2] = 0;
            $kb->SetKeyboardState($state);
            $n = $u->new('uint32_t');
            $r['lines'] = $u->SystemParametersInfoW(0x68, 0, FFI::addr($n), 0) !== 0 && $n->cdata > 0 && $n->cdata < 100 ? $n->cdata : 3;
            // No node takes text: the window has no input context, so keys type the layout's characters.
            $r['imeOff'] = Win32::imm32()->ImmGetContext($w->native()->hwnd) === null;
            $w->close();
            // A field focused: the window has its input context, and WM_CHAR types into the field.
            $name = '';
            $field = Content::view(function () use (&$name) { return TextField('Name', text: $name)->defaultFocus()->frame(width: 200); });
            $w2 = new Window(new WindowOptions(title: 'Field', width: 300, height: 100, content: $field));
            $w2->show();
            App::after(0.4, function () use ($w2, $u, &$name, &$r) {
                $hwnd = $w2->native()->hwnd;
                $imm = Win32::imm32();
                $himc = $imm->ImmGetContext($hwnd);
                $r['imeOn'] = $himc !== null;
                if ($himc !== null) { $imm->ImmReleaseContext($hwnd, $himc); }
                $r['focused'] = $w2->input->focus !== null;
                foreach ([0x68, 0x69, 0xD83D, 0xDE00] as $c) { $u->SendMessageW($hwnd, Win32::WM_CHAR, $c, 0); }
                App::after(0.3, function () use ($w2, &$name, &$r) {
                    $r['typed'] = $name;
                    $w2->close();
                    echo json_encode($r), "\n";
                    App::quit();
                });
            });
        });
    });
});
App::run();
