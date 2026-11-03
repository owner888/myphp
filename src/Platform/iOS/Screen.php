<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Native\ObjC;
use MyPHP\Platform;
use MyPHP\Rectangle;

final class Screen implements Platform\Screen
{
    public function displays(): array
    {
        $mainScreen = ObjC::cls('UIScreen')?->msg('mainScreen');
        $scale = 3.0;
        $w = 393.0;
        $h = 852.0;

        if ($mainScreen !== null) {
            $scale = max(1.0, (float)$mainScreen->msg('scale'));
            $bounds = $mainScreen->msg('bounds');
            if (is_object($bounds) && isset($bounds->w, $bounds->h)) {
                $w = (float)$bounds->w;
                $h = (float)$bounds->h;
            }
        }

        $r = new Rectangle(0.0, 0.0, $w, $h);

        return [
            new \MyPHP\Display(
                id: '1',
                name: 'iPhone Display',
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
