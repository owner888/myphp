<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use MyPHP\{Display, Point};
use MyPHP\Native\{ObjC, Screens};
use MyPHP\Platform;

/** NSScreen's displays (mygo's darwin screen). */
final class Screen implements Platform\Screen
{
    public function displays(): array
    {
        return array_map(fn(array $d) => new Display($d['id'], $d['label'], $d['bounds'], $d['workArea'], $d['scale'], $d['rotation'], $d['internal'], $d['primary']), Screens::displays());
    }

    public function cursorPoint(): Point
    {
        $p = ObjC::msg('q:', ObjC::cls('NSEvent'), 'mouseLocation');
        return new Point((int)round($p->x), (int)round(Screens::primaryHeight() - $p->y));
    }
}
