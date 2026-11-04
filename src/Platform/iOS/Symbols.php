<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Native\ObjC;
use MyPHP\Platform;

final class Symbols implements Platform\Symbols
{
    public function exists(string $name): bool
    {
        try {
            ObjC::boot(['UIKit']);
            $img = ObjC::cls('UIImage')?->msg('systemImageNamed:', ObjC::str($name));
            return $img !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function draw(string $name, float $size, float $weight, int $rgb, float $scale): array
    {
        return [16, 16, str_repeat("\x00", 16 * 16 * 4)];
    }
}
