<?php
declare(strict_types=1);

namespace MyPHP\Platform\Darwin;

use MyPHP\Platform;

/** SF Symbols (Native\Symbols). */
final class Symbols implements Platform\Symbols
{
    public function exists(string $name): bool
    {
        return \MyPHP\Native\Symbols::exists($name);
    }

    public function draw(string $name, float $size, float $weight, int $rgb, float $scale): array
    {
        return \MyPHP\Native\Symbols::draw($name, $size, $weight, $rgb, $scale);
    }
}
