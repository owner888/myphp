<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

/** A canvas of premultiplied RGBA pixels, w × h, four ints of 0..255 a pixel in $pix, with what was drawn into it within $dirty [x0, y0, x1, y1] (exclusive), or null. */
final class Layer
{
    /** @var list<int> */
    public array $pix;
    public ?array $dirty = null;

    public function __construct(public readonly int $w, public readonly int $h)
    {
        $this->pix = array_fill(0, $w * $h * 4, 0);
    }

    /** Clears the layer for reuse. */
    public function clear(): void
    {
        if ($this->dirty === null) { return; }
        [$x0, $y0, $x1, $y1] = $this->dirty;
        $pix = &$this->pix;
        for ($y = $y0; $y < $y1; $y++) {
            for ($i = ($y * $this->w + $x0) * 4, $e = ($y * $this->w + $x1) * 4; $i < $e; $i++) { $pix[$i] = 0; }
        }
        $this->dirty = null;
    }

    /** Widens the dirty rectangle to include [x0, y0, x1, y1]. */
    public function touch(array $r): void
    {
        $d = $this->dirty;
        $this->dirty = $d === null ? $r : [min($d[0], $r[0]), min($d[1], $r[1]), max($d[2], $r[2]), max($d[3], $r[3])];
    }
}
