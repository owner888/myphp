<?php
declare(strict_types=1);

namespace MyPHP\UI;

use FFI\CData;
use MyPHP\Native\ImageIO;
use MyPHP\Native\Mem;
use RuntimeException;

/**
 * A decoded image the UI draws with Context::image(): RGBA pixels at
 * their own size (at most $max on a side), put into the image atlas the
 * first frame they are drawn and again whenever the atlas starts over,
 * so the pixels stay (w*h*4 bytes). Make one once and keep it in the
 * app's state; it is not a per-frame object.
 */
final class Image
{
    private static int $next = 0;
    public readonly string $key;
    public readonly int $w, $h;
    private CData $pix;
    public readonly int $addr;
    /** Shows its pixels as squares when scaled up, sampled at the nearest rather than blended, as pixel art wants (mygo's Bitmap.Pixelated); its levels, smaller, stay smooth. */
    public bool $pixelated = false;
    /** @var list<Image> the image halved once, twice…, made as a smaller showing asks (mygo's levels) */
    private array $levels = [];

    private function __construct(array $decoded)
    {
        $this->key = 'img' . ++self::$next;
        $this->w = $decoded['w']; $this->h = $decoded['h'];
        $this->pix = $decoded['pix']; $this->addr = $decoded['addr'];
    }

    public static function fromBytes(string $bytes, int $max = 1024): self
    {
        return new self(ImageIO::decode($bytes, $max));
    }

    /** An image of $w × $h premultiplied RGBA pixels, four bytes a pixel, top row first. */
    public static function fromPixels(int $w, int $h, string $rgba): self
    {
        $n = $w * $h * 4;
        if (strlen($rgba) !== $n) { throw new RuntimeException("image {$w}x{$h} needs $n bytes, got " . strlen($rgba)); }
        $pix = Mem::alloc($n);
        \FFI::memcpy($pix, $rgba, $n);
        return new self(['w' => $w, 'h' => $h, 'pix' => $pix, 'addr' => Mem::addr($pix)]);
    }

    public static function fromFile(string $path, int $max = 1024): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("cannot read image $path");
        }
        return self::fromBytes($bytes, $max);
    }

    /** Makes the image pixelated ($pixelated) and returns it. */
    public function pixelated(): self
    {
        $this->pixelated = true;
        return $this;
    }

    /** The pixels, premultiplied RGBA, four bytes a pixel, top row first. */
    public function pixels(): string
    {
        return \FFI::string($this->pix, $this->w * $this->h * 4);
    }

    /**
     * The image, or a halving of it, for showing $shrink times smaller
     * than its pixels, as mygo's smaller: far smaller than its pixels, a
     * level of it, so it is sampled smooth rather than aliased. Levels are
     * made once and kept.
     */
    public function smaller(float $shrink): self
    {
        if ($shrink < 2) { return $this; }
        $k = (int)log($shrink, 2); // halvings that stay as large
        $img = $this;
        for ($i = 0; $i < $k; $i++) {
            if ($i === count($this->levels)) {
                if ($img->w < 2 && $img->h < 2) { return $img; }
                $this->levels[] = $img->halved();
            }
            $img = $this->levels[$i];
        }
        return $img;
    }

    /** This image half as large, each pixel the average of the 2 × 2 it covers, of premultiplied colors; an odd last row or column counts twice. */
    private function halved(): self
    {
        $w = max(1, intdiv($this->w + 1, 2)); $h = max(1, intdiv($this->h + 1, 2));
        $src = \FFI::string($this->pix, $this->w * $this->h * 4); $stride = 4 * $this->w;
        $out = '';
        for ($y = 0; $y < $h; $y++) {
            $r0 = 2 * $y * $stride; $r1 = min(2 * $y + 1, $this->h - 1) * $stride;
            for ($x = 0; $x < $w; $x++) {
                $x0 = 8 * $x; $x1 = 4 * min(2 * $x + 1, $this->w - 1);
                for ($c = 0; $c < 4; $c++) {
                    $out .= chr(intdiv(ord($src[$r0 + $x0 + $c]) + ord($src[$r0 + $x1 + $c]) + ord($src[$r1 + $x0 + $c]) + ord($src[$r1 + $x1 + $c]) + 2, 4));
                }
            }
        }
        return self::fromPixels($w, $h, $out);
    }

    public function __destruct()
    {
        Mem::free($this->pix);
    }
}
