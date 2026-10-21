<?php
declare(strict_types=1);

namespace MyPHP\Plugins\Terminal\Vt;

use FFI;
use FFI\CData;

/**
 * Turns presses and drags of the pointer into selections as Ghostty does
 * (mygo's vt.Gesture): a click and a drag select cells, a double click
 * words and a triple click lines, and dragging past the top or the bottom
 * asks to scroll.
 *
 * @internal
 */
final class Gesture
{
    // Selection gesture events, options and data (GhosttySelectionGesture*).
    public const PRESS = 0, RELEASE = 1, DRAG = 2, AUTO_TICK = 3;
    private const OPT_REF = 0, OPT_POSITION = 1, OPT_REPEAT_DISTANCE = 2, OPT_TIME = 3, OPT_REPEAT_INTERVAL = 4, OPT_RECTANGLE = 7, OPT_GEOMETRY = 8, OPT_VIEWPORT = 9;
    private const DATA_DRAGGED = 1, DATA_AUTOSCROLL = 2;

    private ?CData $h;
    /** @var array<int, CData> the events, by type */
    private array $events = [];

    /** A gesture: repeated clicks count within $interval nanoseconds of each other and $distance pixels. */
    public function __construct(int $interval, float $distance)
    {
        $f = Lib::ffi();
        $this->h = $f->new('void*');
        Lib::must($f->ghostty_selection_gesture_new(null, FFI::addr($this->h)));
        foreach ([self::PRESS, self::RELEASE, self::DRAG, self::AUTO_TICK] as $typ) {
            $e = $f->new('void*');
            Lib::must($f->ghostty_selection_gesture_event_new(null, FFI::addr($e), $typ));
            $this->events[$typ] = $e;
        }
        $this->set(self::PRESS, self::OPT_REPEAT_INTERVAL, 'uint64_t', $interval);
        $this->set(self::PRESS, self::OPT_REPEAT_DISTANCE, 'double', $distance);
    }

    /** Frees the gesture, which belongs to $t (null if it was never used). */
    public function free(?Terminal $t): void
    {
        $f = Lib::ffi();
        foreach ($this->events as $e) { $f->ghostty_selection_gesture_event_free($e); }
        $this->events = [];
        if ($this->h !== null) { $f->ghostty_selection_gesture_free($this->h, $t?->h); }
        $this->h = null;
    }

    private function set(int $ev, int $opt, string $type, mixed $v): void
    {
        $c = Lib::ffi()->new($type);
        $c->cdata = $v;
        Lib::ffi()->ghostty_selection_gesture_event_set($this->events[$ev], $opt, FFI::addr($c));
    }

    private function setStruct(int $ev, int $opt, ?CData $v): void
    {
        Lib::ffi()->ghostty_selection_gesture_event_set($this->events[$ev], $opt, $v === null ? null : FFI::addr($v));
    }

    private function position(float $x, float $y): CData
    {
        $p = Lib::ffi()->new('GhosttySurfacePosition');
        $p->x = $x; $p->y = $y;
        return $p;
    }

    /** @param array{0: int, 1: int, 2: int, 3: int} $geo columns, cell width, left padding and height, in pixels */
    private function geometry(array $geo): CData
    {
        $g = Lib::ffi()->new('GhosttySelectionGestureGeometry');
        $g->columns = max($geo[0], 1); $g->cell_width = max($geo[1], 1); $g->padding_left = max($geo[2], 0); $g->screen_height = max($geo[3], 1);
        return $g;
    }

    private function apply(Terminal $t, int $ev): ?CData
    {
        $f = Lib::ffi();
        $s = $f->new('GhosttySelection');
        $s->size = FFI::sizeof($s);
        return $f->ghostty_selection_gesture_event($this->h, $t->h, $this->events[$ev], FFI::addr($s)) === Lib::SUCCESS ? $s : null;
    }

    /** Presses the pointer on cell $ref at ($x, $y) pixels, at $ns of a monotonic clock. A double or triple click selects at once. */
    public function press(Terminal $t, CData $ref, float $x, float $y, int $ns): ?CData
    {
        $this->setStruct(self::PRESS, self::OPT_REF, $ref);
        $this->setStruct(self::PRESS, self::OPT_POSITION, $this->position($x, $y));
        $this->set(self::PRESS, self::OPT_TIME, 'uint64_t', $ns);
        return $this->apply($t, self::PRESS);
    }

    /** Moves the pressed pointer to cell $ref at ($x, $y) pixels, selecting a rectangle when $rect. @param array{0: int, 1: int, 2: int, 3: int} $geo */
    public function drag(Terminal $t, CData $ref, float $x, float $y, array $geo, bool $rect): ?CData
    {
        $this->setStruct(self::DRAG, self::OPT_REF, $ref);
        $this->setStruct(self::DRAG, self::OPT_POSITION, $this->position($x, $y));
        $this->setStruct(self::DRAG, self::OPT_GEOMETRY, $this->geometry($geo));
        $this->set(self::DRAG, self::OPT_RECTANGLE, 'bool', $rect);
        return $this->apply($t, self::DRAG);
    }

    /** Continues a drag past an edge after the viewport scrolled, the pointer at ($x, $y) pixels over column $col of row $row of the viewport. @param array{0: int, 1: int, 2: int, 3: int} $geo */
    public function tick(Terminal $t, int $col, int $row, float $x, float $y, array $geo, bool $rect): ?CData
    {
        $vp = Lib::ffi()->new('GhosttyViewportCoordinate');
        $vp->x = max($col, 0); $vp->y = max($row, 0);
        $this->setStruct(self::AUTO_TICK, self::OPT_POSITION, $this->position($x, $y));
        $this->setStruct(self::AUTO_TICK, self::OPT_GEOMETRY, $this->geometry($geo));
        $this->setStruct(self::AUTO_TICK, self::OPT_VIEWPORT, $vp);
        $this->set(self::AUTO_TICK, self::OPT_RECTANGLE, 'bool', $rect);
        return $this->apply($t, self::AUTO_TICK);
    }

    /** Releases the pointer over cell $ref, or outside the grid. */
    public function release(Terminal $t, ?CData $ref): void
    {
        $this->setStruct(self::RELEASE, self::OPT_REF, $ref);
        $this->apply($t, self::RELEASE);
    }

    /** Whether the pointer moved to another cell since it was pressed. */
    public function dragged(Terminal $t): bool
    {
        $f = Lib::ffi();
        $v = $f->new('bool');
        return $f->ghostty_selection_gesture_get($this->h, $t->h, self::DATA_DRAGGED, FFI::addr($v)) === Lib::SUCCESS && $v->cdata;
    }

    /** Whether a drag past an edge asks to scroll up (-1) or down (1). */
    public function autoscroll(Terminal $t): int
    {
        $f = Lib::ffi();
        $v = $f->new('int32_t');
        $f->ghostty_selection_gesture_get($this->h, $t->h, self::DATA_AUTOSCROLL, FFI::addr($v));
        return match ($v->cdata) { 1 => -1, 2 => 1, default => 0 };
    }

    /** Forgets the gesture's clicks. */
    public function reset(Terminal $t): void { Lib::ffi()->ghostty_selection_gesture_reset($this->h, $t->h); }
}
