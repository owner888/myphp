<?php
declare(strict_types=1);

namespace MyPHP\UI;

/**
 * Where canvases are on the GPU: pages after the image atlas's in its
 * texture array, which no upload from the CPU touches, packed in shelves.
 * The renderer takes the canvases to render each frame (take()): those
 * drawn, and those placed again after the pages filled up and started
 * over, rendered from the list they were last drawn with.
 *
 * @internal
 */
final class CanvasAtlas
{
    /** How many pages canvases have, after ImageAtlas::MAX's. */
    public const PAGES = 2;
    public static int $w = 2048, $h = 2048;
    /** @var list<array{0: int, 1: int, 2: int}> the shelves of each page: [y, height, x of the free part] */
    private static array $shelves = [];
    /** @var ?\WeakMap<Canvas, true> the canvases placed, which a reset takes their places from */
    private static ?\WeakMap $placed = null;
    /** @var array<int, Canvas> the canvases to render, by object id */
    private static array $pending = [];
    /** Times the pages filled up and started over. */
    public static int $resets = 0;

    /** $c was drawn: it renders this frame, placed if it has no place and fits a page. */
    public static function drawn(Canvas $c): void
    {
        if ($c->slot === null && !$c->loose()) {
            self::place($c);
        }
        self::$pending[spl_object_id($c)] = $c;
    }

    /** Gives $c a place, starting the pages over when none is left; one drawn before renders again there. */
    public static function place(Canvas $c): void
    {
        $slot = self::fit($c->w, $c->h);
        if ($slot === null) {
            foreach (self::$placed ?? [] as $o => $_) {
                $o->slot = null; // placed again as they are next shown
            }
            self::$placed = null;
            self::$shelves = [];
            self::$resets++;
            $slot = self::fit($c->w, $c->h);
        }
        $c->slot = $slot;
        self::$placed ??= new \WeakMap;
        self::$placed[$c] = true;
        if ($c->drawn > 0) {
            self::$pending[spl_object_id($c)] = $c;
        }
    }

    /** A free place of $w × $h: [u, v, page], or null. @return ?array{0: int, 1: int, 2: int} */
    private static function fit(int $w, int $h): ?array
    {
        for ($page = 0; $page < self::PAGES; $page++) {
            $top = 0;
            foreach (self::$shelves[$page] ?? [] as $i => [$y, $sh, $x]) {
                if ($h <= $sh && $x + $w <= self::$w) {
                    self::$shelves[$page][$i][2] = $x + $w;
                    return [$x, $y, ImageAtlas::MAX + $page];
                }
                $top = $y + $sh;
            }
            if ($top + $h <= self::$h) {
                self::$shelves[$page][] = [$top, $h, $w];
                return [0, $top, ImageAtlas::MAX + $page];
            }
        }
        return null;
    }

    /** The canvases to render, in the order they were drawn: those drawn inside others first. @return list<Canvas> */
    public static function take(): array
    {
        $list = array_values(self::$pending);
        self::$pending = [];
        usort($list, fn(Canvas $a, Canvas $b): int => $a->drawn <=> $b->drawn);
        return array_values(array_filter($list, fn(Canvas $c): bool => ($c->slot !== null || $c->loose()) && $c->list !== null));
    }

    /** Forgets every canvas, for tests. */
    public static function reset(): void
    {
        self::$shelves = self::$pending = [];
        self::$placed = null;
    }
}
