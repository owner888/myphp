<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Display;
use MyPHP\Native\iOS;
use MyPHP\Platform;
use MyPHP\Point;
use MyPHP\Rectangle;

/** The one display of a phone, as the launcher measures it (main.m). */
final class Screen implements Platform\Screen
{
    public function displays(): array
    {
        $f = iOS::ffi();
        $r = new Rectangle(0, 0, (int)$f->myphp_ios_get_screen_width(), (int)$f->myphp_ios_get_screen_height());
        return [new Display(id: 1, label: 'Display', bounds: $r, workArea: $r, scaleFactor: max(1.0, (float)$f->myphp_ios_get_screen_scale()), primary: true)];
    }

    public function primaryDisplay(): Display
    {
        return $this->displays()[0];
    }

    public function cursorPoint(): Point
    {
        return new Point(0.0, 0.0); // no pointer
    }
}
