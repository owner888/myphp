<?php
declare(strict_types=1);

namespace MyPHP;

use Closure;
use MyPHP\Native\{ObjC, Screens};

/** The connected displays (mygo's Screen). Call it once the app runs. */
final class Screen
{
    /** @var array<int, Closure> */
    private static array $onChanged = [];
    private static int $next = 0;

    /** The displays, the primary one first (mygo's Displays). @return list<Display> */
    public static function displays(): array
    {
        self::needsApp();
        return array_map(fn(array $d) => new Display($d['id'], $d['label'], $d['bounds'], $d['workArea'], $d['scale'], $d['rotation'], $d['internal'], $d['primary']), Screens::displays());
    }

    /** The display with the menu bar (mygo's PrimaryDisplay); one of no size without any. */
    public static function primaryDisplay(): Display
    {
        $ds = self::displays();
        foreach ($ds as $d) {
            if ($d->primary) { return $d; }
        }
        return $ds[0] ?? new Display;
    }

    /** Where the pointer is (mygo's CursorScreenPoint). */
    public static function cursorScreenPoint(): Point
    {
        self::needsApp();
        $p = ObjC::msg('q:', ObjC::cls('NSEvent'), 'mouseLocation');
        return new Point((int)round($p->x), (int)round(Screens::primaryHeight() - $p->y));
    }

    /** The display nearest $p (mygo's DisplayNearestPoint). */
    public static function displayNearestPoint(Point $p): Display
    {
        $best = new Display; $bestDist = INF;
        foreach (self::displays() as $d) {
            $b = $d->bounds;
            $dx = max($b->x - $p->x, 0, $p->x - ($b->x + $b->width));
            $dy = max($b->y - $p->y, 0, $p->y - ($b->y + $b->height));
            if (($dist = $dx * $dx + $dy * $dy) < $bestDist) {
                [$best, $bestDist] = [$d, $dist];
            }
        }
        return $best;
    }

    /** The display $r overlaps most (mygo's DisplayMatching). */
    public static function displayMatching(Rectangle $r): Display
    {
        $best = new Display; $bestArea = -1;
        foreach (self::displays() as $d) {
            $b = $d->bounds;
            $w = min($b->x + $b->width, $r->x + $r->width) - max($b->x, $r->x);
            $h = min($b->y + $b->height, $r->y + $r->height) - max($b->y, $r->y);
            if (($area = max($w, 0) * max($h, 0)) > $bestArea) {
                [$best, $bestArea] = [$d, $area];
            }
        }
        return $best;
    }

    /** Calls $fn when displays are added or removed, or change resolution (mygo's OnDisplaysChanged). Returns a function that removes it. */
    public static function onDisplaysChanged(Closure $fn): Closure
    {
        $k = self::$next++;
        self::$onChanged[$k] = $fn;
        return function () use ($k): void { unset(self::$onChanged[$k]); };
    }

    /** @internal the displays changed */
    public static function changed(): void
    {
        foreach (self::$onChanged as $fn) {
            $fn();
        }
    }

    private static function needsApp(): void
    {
        if (!App::running()) {
            throw new \RuntimeException('myphp: Screen needs the running app (App::whenReady)');
        }
    }
}
