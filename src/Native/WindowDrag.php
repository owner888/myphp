<?php
declare(strict_types=1);

namespace MyPHP\Native;

/**
 * Drag regions move the window with a press (mygo's window_drag.go): the
 * press of a page's `--app-region: drag`, which the page tells a few
 * milliseconds after the web view got it, or of native UI's
 * Node::dragWindow, which asks while the frame handles the press.
 *
 * The window server moves the window best (performWindowDragWithEvent:):
 * it takes it to other Spaces and tiles it. But it may not begin when the
 * pointer has moved since the press by the time it is asked: AppKit logs
 * "Window move completed without beginning" and the window stays, which a
 * quick press and drag hits now and then. So the press is watched (a local
 * event monitor): when the pointer has not moved yet, the window server is
 * asked; when it has, or when the press's drags still reach the app past a
 * few points and the window server has not begun, myphp moves the window
 * itself, keeping the point pressed under the pointer. Either way the
 * press's drags are the window's, not the content's. The window server
 * tells the window as it begins (windowWillMove:); the window not being
 * where myphp put it tells that it began after all, and myphp leaves it.
 *
 * Points are in the display space of Core Graphics (the primary display's
 * top-left, y down), where events keep their place as the window moves;
 * window origins are AppKit's (bottom-left, y up). Main thread only.
 *
 * @internal Window
 */
final class WindowDrag
{
    public const IDLE = 0, PRESSED = 1, NATIVE = 2, MANUAL = 3, SERVER = 4;
    /** What start() says to do: nothing, ask the window server, or move the window to target() and with the pointer. */
    public const START_NONE = 0, START_NATIVE = 1, START_MANUAL = 2;
    /** How far the pointer may have gone when the drag is asked for the window server to be asked (a hand on a touchpad trembles); how far, the window not moving, before myphp moves it itself; how far the window may be from where myphp put it (AppKit rounds) before the window server is taken to move it. */
    private const STILL = 1.0, SLOP = 3.0, AWAY = 2.0;

    public int $phase = self::IDLE;
    /** The window pressed, by address; where the press was, where the pointer was last with the button down; the window's origin when the drag was asked, and where myphp last put it. @var array{0: float, 1: float} */
    public int $win = 0;
    public array $press = [0.0, 0.0], $at = [0.0, 0.0], $origin = [0.0, 0.0], $put = [0.0, 0.0];

    private static ?self $the = null;
    /** The window of the press, and the monitor of drags and releases, made with the first press. */
    private static mixed $window = null, $monitor = null;

    /** The press that may move a window. */
    public static function the(): self
    {
        return self::$the ??= new self;
    }

    /** A press of the left button in window $win at $at. */
    public function down(int $win, array $at): void
    {
        [$this->phase, $this->win, $this->press, $this->at] = [self::PRESSED, $win, $at, $at];
    }

    /** The window server begins to move window $win. */
    public function willMove(int $win): void
    {
        if ($this->phase === self::NATIVE && $this->win === $win) { $this->phase = self::SERVER; }
    }

    /** The release of the button, or a press elsewhere. */
    public function up(): void
    {
        [$this->phase, $this->win] = [self::IDLE, 0];
    }

    /** Asks to move window $win, whose origin is $origin, with the press: a press asks once. */
    public function start(int $win, array $origin): int
    {
        if ($this->phase !== self::PRESSED || $this->win !== $win) { return self::START_NONE; }
        $this->origin = $origin;
        if (self::distance($this->at, $this->press) >= self::STILL) {
            $this->phase = self::MANUAL;
            return self::START_MANUAL;
        }
        $this->phase = self::NATIVE;
        return self::START_NATIVE;
    }

    /** The pointer at $at with the button down, the window's origin $origin: whether the event is the window's move rather than the content's, and whether to move the window (to target()). @return array{0: bool, 1: bool} */
    public function dragged(array $at, array $origin): array
    {
        switch ($this->phase) {
            case self::PRESSED:
                $this->at = $at;
                return [false, false];
            case self::NATIVE:
                $this->at = $at;
                if ($origin !== $this->origin) { $this->phase = self::SERVER; return [true, false]; }
                if (self::distance($at, $this->press) < self::SLOP) { return [true, false]; } // the pointer has hardly moved yet
                $this->phase = self::MANUAL;
                return [true, true];
            case self::MANUAL:
                $this->at = $at;
                if (self::distance($origin, $this->put) > self::AWAY) { $this->phase = self::SERVER; return [true, false]; } // the window server began after all
                return [true, true];
            case self::SERVER:
                $this->at = $at;
                return [true, false];
        }
        return [false, false];
    }

    /** myphp moved the window to $origin. */
    public function placed(array $origin): void
    {
        $this->put = $origin;
    }

    /** The window's origin that keeps the point pressed under the pointer. @return array{0: float, 1: float} */
    public function target(): array
    {
        return [$this->origin[0] + $this->at[0] - $this->press[0], $this->origin[1] - ($this->at[1] - $this->press[1])];
    }

    /** The origin of a window $height tall moved down so that its top stays at or below $top, as AppKit keeps a title bar out of the menu bar. @return array{0: float, 1: float} */
    public static function belowMenuBar(array $origin, float $height, float $top): array
    {
        return $origin[1] + $height > $top ? [$origin[0], $top - $height] : $origin;
    }

    private static function distance(array $a, array $b): float
    {
        return hypot($a[0] - $b[0], $a[1] - $b[1]);
    }

    /** A press of the left button $event in window $window (an NSWindow), which a drag region may ask to move it with. */
    public static function pressed($window, $event): void
    {
        self::watch();
        self::$window = $window;
        self::the()->down(ObjC::addr($window), self::point());
    }

    /** Moves $window (an NSWindow) with the press of a drag region, while the button is down; $down is the press. */
    public static function startDrag($window, $down): void
    {
        if (!ObjC::msg('b:', $window, 'isMovable')) { return; }
        $f = ObjC::msg('r:', $window, 'frame');
        match (self::the()->start(ObjC::addr($window), [$f->x, $f->y])) {
            self::START_NATIVE => ObjC::msg('v:p', $window, 'performWindowDragWithEvent:', $down),
            self::START_MANUAL => self::move(),
            default => null,
        };
    }

    /** Forgets the press of window $window, which closes. */
    public static function forget($window): void
    {
        if (self::$the !== null && self::$the->win === ObjC::addr($window)) {
            self::$the->up();
            self::$window = null;
        }
    }

    /** Where the pointer is, in the display space of Core Graphics. @return array{0: float, 1: float} */
    private static function point(): array
    {
        $p = ObjC::msg('q:', ObjC::cls('NSEvent'), 'mouseLocation');
        return [$p->x, Screens::primaryHeight() - $p->y];
    }

    /** Sees the drags and the release of a press before the window does: made once. */
    private static function watch(): void
    {
        if (self::$monitor !== null) { return; }
        $mask = 1 << 1 | 1 << 2 | 1 << 6; // left mouse down, up and dragged
        self::$monitor = ObjC::msg('p:Lp', ObjC::cls('NSEvent'), 'addLocalMonitorForEventsMatchingMask:handler:', $mask, ObjC::block('p', function ($ev) {
            $d = self::the();
            if ($d->phase === self::IDLE) { return $ev; }
            if (ObjC::msg('L:', $ev, 'type') === 6) { // NSEventTypeLeftMouseDragged
                $w = ObjC::msg('p:', $ev, 'window');
                if ($w === null || ObjC::addr($w) !== $d->win) { return $ev; }
                $f = ObjC::msg('r:', $w, 'frame');
                [$eat, $move] = $d->dragged(self::point(), [$f->x, $f->y]);
                if ($move) { self::move(); }
                return $eat ? null : $ev;
            }
            $d->up(); // a release, or a press the window has yet to see, which notes itself if it may drag
            return $ev;
        }, false, 'p'));
    }

    /** Moves the window of the press to its target, below the menu bar of the screen with the pointer. */
    private static function move(): void
    {
        $d = self::the();
        $win = self::$window;
        if ($win === null) { return; }
        $origin = $d->target();
        $primary = Screens::primaryHeight();
        $p = [$d->at[0], $primary - $d->at[1]];
        foreach (\MyPHP\Native\Drag::array(ObjC::msg('p:', ObjC::cls('NSScreen'), 'screens')) as $s) {
            $f = ObjC::msg('r:', $s, 'frame');
            if ($p[0] >= $f->x && $p[0] < $f->x + $f->w && $p[1] >= $f->y && $p[1] < $f->y + $f->h) {
                $v = ObjC::msg('r:', $s, 'visibleFrame');
                $origin = self::belowMenuBar($origin, ObjC::msg('r:', $win, 'frame')->h, $v->y + $v->h);
                break;
            }
        }
        $d->placed($origin);
        ObjC::msg('v:q', $win, 'setFrameOrigin:', ObjC::point($origin[0], $origin[1]));
    }
}
