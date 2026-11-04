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
        $scale = 3.0;
        $w = 393.0;
        $h = 852.0;

        try {
            ObjC::boot(['UIKit']);
            $mainScreen = ObjC::cls('UIScreen')?->msg('mainScreen');
            if ($mainScreen !== null) {
                $scale = max(1.0, (float)$mainScreen->msg('scale'));
                $bounds = $mainScreen->msg('bounds');
                if (is_object($bounds) && isset($bounds->w, $bounds->h)) {
                    $w = (float)$bounds->w;
                    $h = (float)$bounds->h;
                }
            }
        } catch (\Throwable $e) {}

        $r = new Rectangle(0, 0, (int)round($w), (int)round($h));

        return [
            new \MyPHP\Display(
                id: 1,
                label: 'iPhone Display',
                bounds: $r,
                workArea: $r,
                scaleFactor: $scale,
                primary: true,
            ),
        ];
    }

    public function primaryDisplay(): \MyPHP\Display
    {
        return $this->displays()[0];
    }

    public function cursorPoint(): \MyPHP\Point
    {
        return new \MyPHP\Point(0.0, 0.0);
    }
}
