<?php
declare(strict_types=1);

namespace MyPHP\Platform;

/**
 * The system's icons by name (myphp's: mygo draws none of the system's),
 * which UI\Image::symbol draws: SF Symbols on macOS, the GTK icon theme's
 * symbolic icons on Linux (Linux\Symbols maps the SF names to them).
 */
interface Symbols
{
    /** Whether the system has symbol $name. */
    public function exists(string $name): bool;

    /**
     * Symbol $name at $size points and $weight (NSFontWeight: -0.8
     * ultra-light to 0.62 black, 0 regular), in $rgb, at $scale pixels a
     * point: [w, h, premultiplied RGBA] in pixels.
     * @return array{0: int, 1: int, 2: string}
     */
    public function draw(string $name, float $size, float $weight, int $rgb, float $scale): array;
}
