<?php
declare(strict_types=1);

namespace MyPHP\Platform\iOS;

use MyPHP\Native\iOS;
use MyPHP\Platform;

/** SF Symbols as UIKit draws them, on the main thread (main.m). */
final class Symbols implements Platform\Symbols
{
    public function exists(string $name): bool
    {
        return iOS::ffi()->myphp_ios_symbol_exists($name) === 1;
    }

    public function draw(string $name, float $size, float $weight, int $rgb, float $scale): array
    {
        $f = iOS::ffi();
        $max = (int)ceil($size * $scale * 3) ** 2 * 4; // a symbol is at most about three times as wide as its point size
        $buf = $f->new("uint8_t[$max]");
        $w = $f->new('int');
        $h = $f->new('int');
        $n = $f->myphp_ios_draw_symbol($name, $size, $weight, $rgb, $scale, \FFI::addr($w), \FFI::addr($h), $buf, $max);
        if ($n === 0) {
            throw new \RuntimeException("no symbol \"$name\"");
        }
        return [(int)$w->cdata, (int)$h->cdata, \FFI::string($buf, $n)];
    }
}
