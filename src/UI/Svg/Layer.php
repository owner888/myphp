<?php
declare(strict_types=1);

namespace MyPHP\UI\Svg;

use FFI;
use FFI\CData;

/**
 * A canvas of premultiplied RGBA pixels, w × h, four uint8_t bytes a pixel in
 * $pix (1 byte per channel in C memory instead of a 32-byte PHP zval per int),
 * with what was drawn into it within $dirty [x0, y0, x1, y1] (exclusive), or null.
 */
final class Layer
{
    private static ?FFI $c = null;
    public CData $pix;
    public ?array $dirty = null;

    public static function ffi(): FFI
    {
        return self::$c ??= FFI::cdef('void* calloc(size_t, size_t); void free(void*);', PHP_OS_FAMILY === 'Windows' ? 'ucrtbase.dll' : null);
    }

    public function __construct(public readonly int $w, public readonly int $h)
    {
        $f = self::ffi();
        $n = max(1, $w * $h * 4);
        $this->pix = $f->cast('uint8_t*', $f->calloc($n, 1));
    }

    public function __destruct()
    {
        self::ffi()->free($this->pix);
    }

    /** Clears the layer for reuse. */
    public function clear(): void
    {
        if ($this->dirty === null) { return; }
        [$x0, $y0, $x1, $y1] = $this->dirty;
        $pix = $this->pix;
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

    /** The raw premultiplied RGBA bytes (w * h * 4 bytes). */
    public function bytes(): string
    {
        $n = $this->w * $this->h * 4;
        return $n <= 0 ? '' : FFI::string($this->pix, $n);
    }

    /** The alpha channel bytes (w * h bytes, 1 byte per pixel). */
    public function alphaBytes(): string
    {
        $n = $this->w * $this->h;
        if ($n <= 0) { return ''; }
        $f = self::ffi();
        $out = $f->cast('uint8_t*', $f->calloc($n, 1));
        $pix = $this->pix;
        for ($i = 0, $pi = 3; $i < $n; $i++, $pi += 4) {
            $out[$i] = $pix[$pi];
        }
        $s = FFI::string($out, $n);
        $f->free($out);
        return $s;
    }

    /** @return list<int> */
    public function toArray(): array
    {
        $n = $this->w * $this->h * 4;
        if ($n <= 0) { return []; }
        return array_values(unpack('C*', FFI::string($this->pix, $n)));
    }
}