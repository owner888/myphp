<?php
declare(strict_types=1);

namespace MyPHP\Platform\Android;

use MyPHP\Native\Android;
use MyPHP\Platform;
use MyPHP\Rectangle;

final class Screen implements Platform\Screen
{
    public function displays(): array
    {
        $f = Android::ffi();
        $scale = max(1.0, $f->myphp_get_density());
        $w = (float)$f->myphp_get_surface_width() / $scale;
        $h = (float)$f->myphp_get_surface_height() / $scale;
        $r = new Rectangle(0.0, 0.0, $w, $h);

        return [
            new \MyPHP\Display(
                id: '1',
                name: 'Android Display',
                bounds: $r,
                workArea: $r,
                scale: $scale,
                primary: true,
            ),
        ];
    }

    public function primaryDisplay(): \MyPHP\Display
    {
        return $this->displays()[0];
    }
}
