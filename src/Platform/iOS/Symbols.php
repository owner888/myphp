<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Native\ObjC;
use MyPHP\Platform;

final class Symbols implements Platform\Symbols
{
    public function exists(string $name): bool
    {
        ObjC::boot(['UIKit']);
        return ObjC::cls('UIImage')->msg('systemImageNamed:', ObjC::str($name)) !== null; // null: no symbol of that name
    }

    public function draw(string $name, float $size, float $weight, int $rgb, float $scale): array
    {
        return [16, 16, str_repeat("\x00", 16 * 16 * 4)];
    }
}
